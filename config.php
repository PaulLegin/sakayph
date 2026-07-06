<?php
// SakayPH Global Configuration File
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database Credentials (Default XAMPP)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sakayph_db');

// External APIs Configuration
// Replace with your actual Paymongo Secret Key (sk_...) and Public Key (pk_...)
define('PAYMONGO_SECRET_KEY', 'sk_test_65V6b4G351H45f8fdfg9Gjhf'); // Placeholder, change to your Paymongo test secret key
define('PAYMONGO_PUBLIC_KEY', 'pk_test_HjgF45g3H8Fk5g39HlkDfgdf'); // Placeholder, change to your Paymongo test public key

// ============================================================
// SIMULATION MODE (set to false when you have real API keys)
// When TRUE: Shows a fake payment page instead of real Paymongo
// When FALSE: Uses real Paymongo API (requires valid API keys)
// ============================================================
define('PAYMONGO_TEST_MODE', true);

// Paymongo Webhook Signing Secret (Get this from Paymongo Dashboard -> Webhooks -> Add Webhook)
// Used to verify webhook signature and prevent spoofed payments.
define('PAYMONGO_WEBHOOK_SIGNING_SECRET', 'whsec_test_your_actual_signing_secret_here');

// OCR.Space API Configuration
// 'helloworld' is the free demo API key provided by OCR.space for basic testing.
// For production, register a free key at https://ocr.space/ocrapi and replace below.
define('OCR_SPACE_API_KEY', 'helloworld');

// Base URL configuration for redirects (Paymongo success/cancel URLs)
define('BASE_URL', 'http://localhost/sakayph/');

// Establish PDO Database Connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // If the database doesn't exist yet, we capture the error gracefully
    // (This allows running the app to see instructions before creating the database)
    $pdo = null;
}

// Load Dynamic Settings from Database
$commission_rate = 10; // Default 10%
$min_payout_amount = 1000; // Default ₱1,000

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (isset($settings['commission_rate'])) {
            $commission_rate = floatval($settings['commission_rate']);
        }
        if (isset($settings['min_payout_amount'])) {
            $min_payout_amount = floatval($settings['min_payout_amount']);
        }
    } catch (PDOException $e) {
        // Table system_settings does not exist yet (before sql import)
    }
}

define('COMMISSION_RATE', $commission_rate);
define('MIN_PAYOUT_AMOUNT', $min_payout_amount);

// --- HELPER FUNCTIONS ---

// Format price to Philippine Peso (e.g. ₱2,500.00)
function format_peso($amount) {
    return '₱' . number_format($amount, 2);
}

// Redirect utility
function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}

// Check authorization and user roles
function require_login($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        redirect('login.php');
    }
    
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'], $allowed_roles)) {
        // Logged in but unauthorized for this section
        if ($_SESSION['user_role'] === 'admin') {
            redirect('admin/dashboard.php');
        } elseif ($_SESSION['user_role'] === 'driver') {
            redirect('driver/dashboard.php');
        } else {
            redirect('client/dashboard.php');
        }
    }
}

// Check if user is already logged in (redirect to dashboard if so)
function redirect_if_logged_in() {
    if (isset($_SESSION['user_id'])) {
        if ($_SESSION['user_role'] === 'admin') {
            redirect('admin/dashboard.php');
        } elseif ($_SESSION['user_role'] === 'driver') {
            redirect('driver/dashboard.php');
        } else {
            redirect('client/dashboard.php');
        }
    }
}

// ============================================================
// AUTOMATIC BACKGROUND scheduler (No Cron Job Required)
// Runs silently during page loads to manage trip states
// ============================================================
function run_auto_scheduler($pdo) {
    if (!$pdo) return;
    
    // Limit execution to once every 2 minutes per visitor session to prevent DB overhead
    $now = time();
    if (isset($_SESSION['last_scheduler_run'])) {
        $elapsed = $now - $_SESSION['last_scheduler_run'];
        if ($elapsed < 120) {
            return;
        }
    }
    $_SESSION['last_scheduler_run'] = $now;

    try {
        // ----------------------------------------------------
        // RULE 1: AUTO-COMPLETE TRIPS (Departure Time + 24 Hours)
        // ----------------------------------------------------
        // Find trips that are booked/in_progress and have exceeded departure_time by 24 hours
        $stmt = $pdo->prepare("
            SELECT t.id, t.driver_id, b.id AS booking_id, b.driver_earnings 
            FROM trips t
            JOIN bookings b ON t.id = b.trip_id
            WHERE t.status IN ('booked', 'in_progress') 
              AND b.status = 'confirmed'
              AND t.departure_time <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $trips_to_complete = $stmt->fetchAll();

        foreach ($trips_to_complete as $trip) {
            $pdo->beginTransaction();
            
            // Mark trip as completed
            $updateTrip = $pdo->prepare("UPDATE trips SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $updateTrip->execute([$trip['id']]);

            // Mark booking as completed
            $updateBooking = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $updateBooking->execute([$trip['booking_id']]);

            // Release funds/earnings to driver wallet
            $creditDriver = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $creditDriver->execute([$trip['driver_earnings'], $trip['driver_id']]);

            $pdo->commit();
        }

        // ----------------------------------------------------
        // RULE 2: AUTO-CANCEL GHOST TRIPS (Departure Time + 2 Hours)
        // ----------------------------------------------------
        // Find trips that are booked but not started within 2 hours of departure_time
        $stmt = $pdo->prepare("
            SELECT t.id, b.id AS booking_id 
            FROM trips t
            LEFT JOIN bookings b ON t.id = b.trip_id
            WHERE t.status = 'booked' 
              AND t.departure_time <= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ");
        $stmt->execute();
        $trips_to_cancel = $stmt->fetchAll();

        foreach ($trips_to_cancel as $trip) {
            $pdo->beginTransaction();
            
            // Cancel trip
            $updateTrip = $pdo->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
            $updateTrip->execute([$trip['id']]);

            // Cancel booking (if any confirmed/pending exists)
            if ($trip['booking_id']) {
                $updateBooking = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
                $updateBooking->execute([$trip['booking_id']]);
            }

            $pdo->commit();
        }

        // Also cancel 'active' trips (unbooked) that have passed their departure time by 1 hour
        $stmt = $pdo->prepare("
            UPDATE trips 
            SET status = 'cancelled' 
            WHERE status = 'active' 
              AND departure_time <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();

        // ----------------------------------------------------
        // RULE 3: AUTO-DELETE UNVERIFIED ACCOUNTS (48 Hours)
        // ----------------------------------------------------
        // Delete client accounts that are not email-verified and were created more than 48 hours ago
        $stmt = $pdo->prepare("
            DELETE FROM users 
            WHERE role = 'client' 
              AND is_email_verified = 0 
              AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        $stmt->execute();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Fail silently in background
    }
}

// Run the auto-scheduler immediately on session check
if (isset($pdo)) {
    run_auto_scheduler($pdo);
}

// Include Auto-Release Pseudo-Cron Job
// require_once __DIR__ . '/includes/auto_release.php';
?>
