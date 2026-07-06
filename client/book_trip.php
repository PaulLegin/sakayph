<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/paymongo_helper.php';
require_login(['client']);

$trip_id = isset($_GET['trip_id']) ? intval($_GET['trip_id']) : 0;
$client_id = $_SESSION['user_id'];

$error = '';
$trip = null;

if ($trip_id <= 0) {
    redirect('index.php');
}

if ($pdo) {
    try {
        // Fetch trip details and make sure it is active
        $stmt = $pdo->prepare("
            SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as driver_name, v.brand, v.model, v.plate_number 
            FROM trips t 
            JOIN users u ON t.driver_id = u.id 
            JOIN vehicles v ON u.id = v.driver_id 
            WHERE t.id = ?
        ");
        $stmt->execute([$trip_id]);
        $trip = $stmt->fetch();
        
        if (!$trip) {
            $error = 'The requested trip does not exist.';
        } elseif ($trip['status'] !== 'active') {
            $error = 'This vehicle charter is already booked or cancelled.';
        } else {
            // Check if there is already an active booking attempt by this client for this trip
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE trip_id = ? AND client_id = ? AND status = 'pending_payment'");
            $stmt->execute([$trip_id, $client_id]);
            $existing_booking = $stmt->fetch();
            
            $booking_id = 0;
            
            if ($existing_booking) {
                $booking_id = $existing_booking['id'];
            } else {
                $price_total = floatval($trip['price_total']);
                $commission = round($price_total * (COMMISSION_RATE / 100), 2);
                $driver_earnings = $price_total - $commission;
                
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (trip_id, client_id, amount_paid, admin_commission, driver_earnings, status) 
                    VALUES (?, ?, ?, ?, ?, 'pending_payment')
                ");
                $stmt->execute([$trip_id, $client_id, $price_total, $commission, $driver_earnings]);
                $booking_id = $pdo->lastInsertId();
            }
            
            // -----------------------------------------------
            // SIMULATION MODE CHECK
            // -----------------------------------------------
            if (PAYMONGO_TEST_MODE) {
                // Skip real Paymongo — redirect to our local simulation page
                header("Location: " . BASE_URL . "client/payment_simulate.php?booking_id=" . $booking_id);
                exit;
            }
            
            // Real Paymongo Checkout Session
            $session = paymongo_create_session($booking_id, $trip['origin'], $trip['destination'], $trip['price_total']);
            
            if ($session) {
                $stmt = $pdo->prepare("UPDATE bookings SET paymongo_session_id = ? WHERE id = ?");
                $stmt->execute([$session['id'], $booking_id]);
                header("Location: " . $session['checkout_url']);
                exit;
            } else {
                $error = 'Failed to initialize payment gateway. Please try again later.';
            }
        }
    } catch (PDOException $e) {
        $error = 'System error: ' . $e->getMessage();
    }
} else {
    $error = 'Database connection failed.';
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom p-4 text-center shadow-lg">
                <i class="bi bi-exclamation-triangle-fill text-warning display-3 mb-3"></i>
                <h3 class="fw-bold text-white mb-2">Booking Payment Failed</h3>
                <p class="text-muted small mb-4">We encountered an issue while setting up your Paymongo payment checkout session. See the error details below:</p>
                
                <div class="alert alert-danger py-2 rounded-3 border-0 small mb-4" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <div class="d-flex gap-3 justify-content-center">
                    <a href="../index.php" class="btn btn-outline-custom px-4"><i class="bi bi-arrow-left me-2"></i>Back to Search</a>
                    <a href="book_trip.php?trip_id=<?php echo $trip_id; ?>" class="btn btn-gradient-primary px-4"><i class="bi bi-arrow-clockwise me-2"></i>Try Again</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
