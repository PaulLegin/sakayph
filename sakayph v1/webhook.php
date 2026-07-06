<?php
// SakayPH Paymongo Webhook Listener
require_once __DIR__ . '/config.php';

// Set response header to JSON
header('Content-Type: application/json');

// Read raw POST body from Paymongo
$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// ---------------------------------------------------------------------
// WEBHOOK SIGNATURE VERIFICATION (Only when not in Simulation Mode)
// ---------------------------------------------------------------------
if (!PAYMONGO_TEST_MODE) {
    $headers = getallheaders();
    $signatureHeader = isset($headers['Paymongo-Signature']) ? $headers['Paymongo-Signature'] : '';
    
    if (empty($signatureHeader)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing signature header']);
        exit;
    }
    
    // Parse timestamp (t) and signatures
    $signatureParts = explode(',', $signatureHeader);
    $timestamp = '';
    $signatures = [];
    
    foreach ($signatureParts as $part) {
        $keyValue = explode('=', $part);
        if (count($keyValue) === 2) {
            $key = trim($keyValue[0]);
            $val = trim($keyValue[1]);
            if ($key === 't') {
                $timestamp = $val;
            } else {
                $signatures[] = $val;
            }
        }
    }
    
    // Construct signing baseline: timestamp + "." + raw payload
    $baseline = $timestamp . '.' . $payload;
    $computedSignature = hash_hmac('sha256', $baseline, PAYMONGO_WEBHOOK_SIGNING_SECRET);
    
    $isValid = false;
    foreach ($signatures as $sig) {
        if (hash_equals($computedSignature, $sig)) {
            $isValid = true;
            break;
        }
    }
    
    if (!$isValid) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit;
    }
}

$event = json_decode($payload, true);

// Log incoming webhook event for debugging purposes
error_log("Paymongo Webhook Received: " . json_encode($event));

if (isset($event['data']['attributes']['type']) && $event['data']['attributes']['type'] === 'checkout_session.payment.paid') {
    // Extract checkout session details
    $sessionData = $event['data']['attributes']['data'];
    $sessionId = $sessionData['id']; // cs_...
    
    if ($pdo && !empty($sessionId)) {
        try {
            // Find the matching booking record
            $stmt = $pdo->prepare("
                SELECT b.*, t.origin, t.destination, t.driver_id 
                FROM bookings b 
                JOIN trips t ON b.trip_id = t.id 
                WHERE b.paymongo_session_id = ? AND b.status = 'pending_payment'
            ");
            $stmt->execute([$sessionId]);
            $booking = $stmt->fetch();
            
            if ($booking) {
                // Fetch the payment ID if available in the webhook payload
                // Paymongo payments are array in attributes.payments
                $paymentId = null;
                if (isset($sessionData['attributes']['payments'][0]['id'])) {
                    $paymentId = $sessionData['attributes']['payments'][0]['id'];
                }
                
                // Begin transaction
                $pdo->beginTransaction();
                
                // 1. Update booking status
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', paymongo_payment_id = ? WHERE id = ?");
                $stmt->execute([$paymentId, $booking['id']]);
                
                // 2. Update trip status to booked
                $stmt = $pdo->prepare("UPDATE trips SET status = 'booked' WHERE id = ?");
                $stmt->execute([$booking['trip_id']]);
                
                // 3. Credit driver wallet
                $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                $stmt->execute([$booking['driver_earnings'], $booking['driver_id']]);
                
                $pdo->commit();
                
                error_log("Webhook processed successfully for booking ID: " . $booking['id']);
                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'Booking confirmed via webhook']);
                exit;
            } else {
                // Booking already confirmed or does not exist
                http_response_code(200);
                echo json_encode(['status' => 'ignored', 'message' => 'Booking not found or already processed']);
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Webhook Database Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error occurred']);
            exit;
        }
    }
}

// Default response for unhandled events
http_response_code(200);
echo json_encode(['status' => 'ignored', 'message' => 'Event type not handled']);
?>
