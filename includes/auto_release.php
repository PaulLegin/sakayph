<?php
// SakayPH - Auto Release Escrow Script
// Acts as a pseudo-cron job that runs on every page load (included in config.php)

if (!isset($pdo)) return;

try {
    // We want to find bookings that are STILL confirmed (payment held in escrow)
    // where the associated trip was marked completed BY THE DRIVER at least 24 hours ago.
    
    // First, find all such bookings to log or process them
    $stmt = $pdo->prepare("
        SELECT b.id as booking_id, b.driver_earnings, t.driver_id 
        FROM bookings b
        JOIN trips t ON b.trip_id = t.id
        WHERE b.status = 'confirmed' 
          AND t.status = 'completed' 
          AND t.completed_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $pending_releases = $stmt->fetchAll();

    if (!empty($pending_releases)) {
        // We have funds to auto-release!
        $pdo->beginTransaction();

        foreach ($pending_releases as $release) {
            // 1. Mark booking as completed
            $update_booking = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $update_booking->execute([$release['booking_id']]);

            // 2. Add earnings to driver's wallet
            $update_wallet = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $update_wallet->execute([$release['driver_earnings'], $release['driver_id']]);
        }

        $pdo->commit();
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Silently fail, it will try again next page load.
}
?>
