<?php
// Free OCR.space API Configuration
if (!defined('OCR_SPACE_API_KEY')) {
    define('OCR_SPACE_API_KEY', 'K82788434088957');
}
if (!defined('OCR_API_URL')) {
    define('OCR_API_URL', 'https://api.ocr.space/parse/image');
}

/**
 * Sends a local file to OCR.space API and returns the parsed text.
 * 
 * @param string $filePath Absolute path to the local image file
 * @return string|null Parsed text from image, or null on failure
 */
function ocr_parse_document($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }

    $url = OCR_API_URL;

    // Initialize cURL
    $ch = curl_init();

    // Use CURLFile to send files securely in PHP
    $cfile = new CURLFile($filePath);

    // Form data fields (Using OCR Engine 3 for superior text recognition)
    $postData = [
        'apikey' => OCR_SPACE_API_KEY,
        'file'   => $cfile,
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'OCREngine' => '3'
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP environments
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout

    // Execute the request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    // Parse the JSON response
    $result = json_decode($response, true);

    if (isset($result['ParsedResults'][0]['ParsedText'])) {
        return trim($result['ParsedResults'][0]['ParsedText']);
    }

    // Check if there is an API error message
    if (isset($result['ErrorMessage'][0])) {
        error_log("OCR API Error: " . $result['ErrorMessage'][0]);
    }

    return null;
}
?>
