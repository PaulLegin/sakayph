<?php
// SakayPH - Driver Trip Action Handler (Start / Complete Trip)
require_once __DIR__ . '/../config.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
$trip_id = isset($_REQUEST['trip_id']) ? intval($_REQUEST['trip_id']) : 0;
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

if ($trip_id <= 0 || !in_array($action, ['start', 'complete'])) {
    redirect('driver/dashboard.php');
}

if ($pdo) {
    try {
        // Verify the trip belongs to this driver
        $stmt = $pdo->prepare("SELECT * FROM trips WHERE id = ? AND driver_id = ?");
        $stmt->execute([$trip_id, $driver_id]);
        $trip = $stmt->fetch();

        if (!$trip) {
            redirect('driver/dashboard.php');
        }

        if ($action === 'start' && $trip['status'] === 'booked') {
            // Mark trip as in-progress (started)
            $stmt = $pdo->prepare("UPDATE trips SET status = 'in_progress', started_at = NOW() WHERE id = ?");
            $stmt->execute([$trip_id]);
            $_SESSION['trip_success'] = 'Trip has been started! Safe travels!';

        } elseif ($action === 'complete' && $trip['status'] === 'in_progress') {
            $completion_pin = isset($_POST['completion_pin']) ? trim($_POST['completion_pin']) : '';
            
            // Get booking details and verify PIN
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE trip_id = ? AND status = 'confirmed'");
            $stmt->execute([$trip_id]);
            $booking = $stmt->fetch();

            if (!$booking || $booking['completion_pin'] !== $completion_pin) {
                $_SESSION['trip_error'] = 'Invalid Completion PIN! Please ask the passenger for the correct 4-digit PIN.';
                redirect('driver/dashboard.php');
            }

            // Begin transaction - mark trip complete and release escrow to driver
            $pdo->beginTransaction();

            // 1. Mark trip as completed
            $stmt = $pdo->prepare("UPDATE trips SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->execute([$trip_id]);
            
            // 2. Mark booking as completed
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $stmt->execute([$booking['id']]);

            // 3. Credit wallet
            $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$booking['amount_paid'], $driver_id]);

            $pdo->commit();
            $_SESSION['trip_success'] = 'PIN Verified! Trip completed successfully and earnings have been released to your wallet.';
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['trip_error'] = 'Error: ' . $e->getMessage();
    }
}

redirect('driver/dashboard.php');
?>
