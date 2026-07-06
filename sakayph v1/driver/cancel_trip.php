<?php
// SakayPH - Driver Cancel Trip
require_once __DIR__ . '/../config.php';
require_login(['driver']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id']) || !isset($_POST['trip_id'])) {
    redirect('driver/dashboard.php');
}

$driver_id = $_SESSION['user_id'];
$booking_id = intval($_POST['booking_id']);
$trip_id = intval($_POST['trip_id']);

if ($pdo) {
    try {
        $pdo->beginTransaction();

        // 1. Fetch booking and trip details, verify ownership
        $stmt = $pdo->prepare("
            SELECT b.*, t.driver_id, t.status as trip_status
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            WHERE b.id = ? AND t.id = ? AND t.driver_id = ? AND b.status = 'confirmed' AND t.status = 'booked'
        ");
        $stmt->execute([$booking_id, $trip_id, $driver_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $pdo->rollBack();
            redirect('driver/dashboard.php');
        }

        $amount = floatval($booking['amount_paid']);

        // 2. Refund 100% to client since driver cancelled
        $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
        $stmt->execute([$amount, $booking['client_id']]);

        // 3. Mark booking as cancelled
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$booking_id]);

        // 4. Mark trip as cancelled
        $stmt = $pdo->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$trip_id]);

        $pdo->commit();
        $_SESSION['trip_success'] = 'Trip has been cancelled. 100% of the payment has been automatically refunded to the passenger.';
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['trip_error'] = 'Error processing cancellation: ' . $e->getMessage();
    }
}

redirect('driver/dashboard.php');
?>
