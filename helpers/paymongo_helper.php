<?php
// SakayPH Paymongo Integration Helper
require_once __DIR__ . '/../config.php';

/**
 * Creates a Paymongo Checkout Session for a booking
 * 
 * @param int $bookingId The local booking ID
 * @param string $origin Trip origin
 * @param string $destination Trip destination
 * @param float $priceTotal Total fare in PHP
 * @return array|null Returns response array with 'id' and 'checkout_url', or null on failure
 */
function paymongo_create_session($bookingId, $origin, $destination, $priceTotal) {
    $url = 'https://api.paymongo.com/v2/checkout_sessions';
    
    // Paymongo expects amounts in centavos (PHP * 100)
    $amountInCentavos = round($priceTotal * 100);
    
    $successUrl = BASE_URL . "client/payment_callback.php?booking_id=" . $bookingId;
    $cancelUrl = BASE_URL . "client/dashboard.php";
    
    $data = [
        'data' => [
            'attributes' => [
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $amountInCentavos,
                        'name' => "SakayPH Private Rental: " . $origin . " to " . $destination,
                        'quantity' => 1
                    ]
                ],
                'payment_method_types' => ['gcash', 'qrph', 'card'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => 'SAKAYPH-BOOKING-' . $bookingId
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development/XAMPP
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log("Paymongo Create Session Curl Error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (isset($result['data']['id']) && isset($result['data']['attributes']['checkout_url'])) {
        return [
            'id' => $result['data']['id'],
            'checkout_url' => $result['data']['attributes']['checkout_url']
        ];
    }
    
    // Log API error responses if any
    if (isset($result['errors'])) {
        error_log("Paymongo API Error: " . json_encode($result['errors']));
    }
    
    return null;
}

/**
 * Retrieves a checkout session status from Paymongo
 * 
 * @param string $sessionId Paymongo checkout session ID (cs_...)
 * @return bool True if paid, false otherwise
 */
function paymongo_is_session_paid($sessionId) {
    // Standard endpoint to retrieve checkout session is v1
    $url = 'https://api.paymongo.com/v1/checkout_sessions/' . $sessionId;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development/XAMPP
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        'Content-Type: application/json',
        'accept: application/json'
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log("Paymongo Retrieve Session Curl Error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (isset($result['data']['attributes']['payments'])) {
        $payments = $result['data']['attributes']['payments'];
        foreach ($payments as $payment) {
            if (isset($payment['attributes']['status']) && $payment['attributes']['status'] === 'paid') {
                return true;
            }
        }
    }
    
    return false;
}
?>
