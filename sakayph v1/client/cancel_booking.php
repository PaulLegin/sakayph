<?php
// SakayPH - Client Cancel Booking
require_once __DIR__ . '/../config.php';
require_login(['client']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id'])) {
    redirect('client/dashboard.php');
}

$client_id = $_SESSION['user_id'];
$booking_id = intval($_POST['booking_id']);

if ($pdo) {
    try {
        $pdo->beginTransaction();

        // 1. Fetch booking and trip details
        $stmt = $pdo->prepare("
            SELECT b.*, t.driver_id, t.departure_time 
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            WHERE b.id = ? AND b.client_id = ? AND b.status = 'confirmed'
        ");
        $stmt->execute([$booking_id, $client_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $pdo->rollBack();
            redirect('client/dashboard.php');
        }

        $amount = floatval($booking['amount_paid']);
        $departure = strtotime($booking['departure_time']);
        $now = time();
        $hours_diff = ($departure - $now) / 3600;

        // 2. Calculate Refund & Penalty
        if ($hours_diff >= 24) {
            // Early cancel: 100% refund to client
            $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $client_id]);
            $_SESSION['payout_success'] = 'Booking cancelled successfully. 100% refund (' . format_peso($amount) . ') has been credited to your wallet.';
        } else {
            // Late cancel: 50% to client, 50% to driver
            $refund_amount = $amount * 0.50;
            $penalty_amount = $amount * 0.50;
            
            // Refund client
            $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$refund_amount, $client_id]);

            // Penalty to driver
            $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$penalty_amount, $booking['driver_id']]);
            
            $_SESSION['trip_error'] = 'Booking cancelled. Since it was less than 24 hours before departure, 50% penalty was applied. ' . format_peso($refund_amount) . ' refunded to your wallet.';
        }

        // 3. Mark booking as cancelled
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$booking_id]);

        // 4. Mark trip as cancelled
        $stmt = $pdo->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$booking['trip_id']]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['trip_error'] = 'Error processing cancellation: ' . $e->getMessage();
    }
}

redirect('client/dashboard.php');
?>
