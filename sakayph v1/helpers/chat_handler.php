<?php
// SakayPH - Chat Handler Controller (AJAX Endpoint)
require_once __DIR__ . '/../config.php';

// JSON Response header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// ----------------------------------------------------
// FETCH MESSAGES ACTION
// ----------------------------------------------------
if ($action === 'fetch') {
    $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
    
    if ($booking_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid booking ID.']);
        exit;
    }

    try {
        // Validate user belongs to this booking
        $stmt = $pdo->prepare("
            SELECT b.client_id, t.driver_id 
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking || ($booking['client_id'] != $user_id && $booking['driver_id'] != $user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
            exit;
        }

        // Fetch messages
        $stmt = $pdo->prepare("
            SELECT * FROM messages 
            WHERE booking_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$booking_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'messages' => $messages]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Query error: ' . $e->getMessage()]);
        exit;
    }
}

// ----------------------------------------------------
// SEND MESSAGE ACTION
// ----------------------------------------------------
if ($action === 'send') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
        exit;
    }

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if ($booking_id <= 0 || $message === '') {
        echo json_encode(['status' => 'error', 'message' => 'Message or Booking ID cannot be empty.']);
        exit;
    }

    // Rate Limiting Protection (Anti-Spam)
    // Limits the user to send only 1 message per 3 seconds
    if (isset($_SESSION['last_chat_time'])) {
        $elapsed = time() - $_SESSION['last_chat_time'];
        if ($elapsed < 3) {
            echo json_encode(['status' => 'rate_limit', 'message' => 'Too fast! Please wait 3 seconds.']);
            exit;
        }
    }

    try {
        // Validate booking details and permissions
        $stmt = $pdo->prepare("
            SELECT b.client_id, b.status AS booking_status, t.driver_id, t.status AS trip_status
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking || ($booking['client_id'] != $user_id && $booking['driver_id'] != $user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
            exit;
        }

        // Lock chat if the trip is completed (Anti-bogus chat for closed transactions)
        if ($booking['booking_status'] === 'completed' || $booking['trip_status'] === 'completed') {
            echo json_encode(['status' => 'error', 'message' => 'Trip is already completed. Chat is locked.']);
            exit;
        }

        // Insert message
        $stmt = $pdo->prepare("
            INSERT INTO messages (booking_id, sender_id, message) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$booking_id, $user_id, $message]);

        // Update rate limiting timer
        $_SESSION['last_chat_time'] = time();

        echo json_encode(['status' => 'success', 'message' => 'Message sent.']);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save message: ' . $e->getMessage()]);
        exit;
    }
}

// Default fallback
echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
exit;
?>
