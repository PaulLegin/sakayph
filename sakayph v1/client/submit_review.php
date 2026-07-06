<?php
// SakayPH - Submit Driver Review Handler
require_once __DIR__ . '/../config.php';
require_login(['client']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    $client_id = $_SESSION['user_id'];

    if ($booking_id <= 0 || $rating < 1 || $rating > 5) {
        $_SESSION['booking_error'] = 'Invalid rating value or booking ID.';
        redirect('client/dashboard.php');
    }

    if ($pdo) {
        try {
            // Verify booking ownership and state (must be completed)
            $stmt = $pdo->prepare("
                SELECT b.*, t.driver_id 
                FROM bookings b
                JOIN trips t ON b.trip_id = t.id
                WHERE b.id = ? AND b.client_id = ? AND b.status = 'completed'
            ");
            $stmt->execute([$booking_id, $client_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $_SESSION['booking_error'] = 'Booking not found or not eligible for review.';
                redirect('client/dashboard.php');
            }

            // Insert review (unique constraint prevents duplicates)
            $stmt = $pdo->prepare("
                INSERT INTO reviews (booking_id, client_id, driver_id, rating, comment) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$booking_id, $client_id, $booking['driver_id'], $rating, $comment]);

            $_SESSION['booking_success'] = 'Thank you! Your review has been submitted successfully.';
        } catch (PDOException $e) {
            $_SESSION['booking_error'] = 'You have already submitted a review for this trip.';
        }
    }
}

redirect('client/dashboard.php');
?>
