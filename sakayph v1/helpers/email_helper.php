<?php
// SakayPH - Email Sending Helper Interface
// Supports both simulated log file (for localhost testing) and Brevo API (for production)

// Email sending configuration
define('EMAIL_PROVIDER', 'simulate'); // Options: 'simulate' or 'brevo'
define('BREVO_API_KEY', 'xkeysib-your-actual-brevo-api-key-here'); // Placeholder, fill when going live
define('SENDER_EMAIL', 'no-reply@sakayph.com');
define('SENDER_NAME', 'SakayPH Support');

/**
 * Send an email verification link to a user.
 * 
 * @param string $to_email Destination email address
 * @param string $to_name Destination recipient name
 * @param string $token Verification token
 * @return bool True if successfully dispatched, false otherwise
 */
function send_verification_email($to_email, $to_name, $token) {
    $verify_link = BASE_URL . "verify_email.php?token=" . $token;
    
    $subject = "Verify Your SakayPH Account";
    $text_content = "Hi " . $to_name . ",\n\nWelcome to SakayPH! Please verify your email address by clicking the link below to activate your passenger account:\n" . $verify_link . "\n\nSafe travels,\nSakayPH Team";
    
    $html_content = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
        <h2 style='color: #6366f1; text-align: center;'>Welcome to SakayPH!</h2>
        <p>Hi " . htmlspecialchars($to_name) . ",</p>
        <p>Thank you for registering. To ensure the security of our community of drivers and passengers, please activate your account by verifying your email address.</p>
        <div style='text-align: center; margin: 30px 0;'>
            <a href='" . $verify_link . "' style='background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; padding: 12px 30px; text-decoration: none; font-weight: bold; border-radius: 8px; display: inline-block;'>Verify Email Address</a>
        </div>
        <p style='font-size: 0.85rem; color: #64748b; text-align: center;'>If the button above does not work, copy and paste this link into your browser:<br><a href='" . $verify_link . "'>" . $verify_link . "</a></p>
        <hr style='border: none; border-top: 1px solid #f1f5f9; margin: 20px 0;'>
        <p style='font-size: 0.75rem; color: #94a3b8; text-align: center;'>This is an automated security message. Please do not reply to this email.</p>
    </div>
    ";

    // ----------------------------------------------------
    // SIMULATED SENDING (Localhost Testing)
    // ----------------------------------------------------
    if (EMAIL_PROVIDER === 'simulate') {
        $log_file = __DIR__ . '/../email_logs.txt';
        $log_entry = "=== SIMULATED EMAIL SENT ===\n" .
                     "Date: " . date('Y-m-d H:i:s') . "\n" .
                     "To: " . $to_email . " (" . $to_name . ")\n" .
                     "Subject: " . $subject . "\n" .
                     "Link: " . $verify_link . "\n" .
                     "=============================\n\n";
        
        return file_put_contents($log_file, $log_entry, FILE_APPEND) !== false;
    }

    // ----------------------------------------------------
    // PRODUCTION SENDING (Brevo REST API via cURL)
    // ----------------------------------------------------
    if (EMAIL_PROVIDER === 'brevo') {
        $url = 'https://api.brevo.com/v3/smtp/email';
        
        $data = [
            'sender' => ['name' => SENDER_NAME, 'email' => SENDER_EMAIL],
            'to' => [['email' => $to_email, 'name' => $to_name]],
            'subject' => $subject,
            'htmlContent' => $html_content,
            'textContent' => $text_content
        ];
        
        $payload = json_encode($data);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($http_code === 200 || $http_code === 201);
    }

    return false;
}
?>
