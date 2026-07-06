<?php
// Start clean output buffer to prevent PHP warnings from corrupting the JSON response
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// SakayPH - AJAX OCR Processing Controller
require_once __DIR__ . '/ocr_helper.php';

header('Content-Type: application/json');

// Check if request is POST and file is uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $doc_type = isset($_POST['type']) ? $_POST['type'] : 'license'; // 'license', 'or', or 'cr'
    
    // Validate file upload
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload failed. File error code: ' . $_FILES['file']['error']]);
        exit;
    }
    
    // Check file mime type or fallback file extension
    $allowed_mime_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    $file_mime = isset($_FILES['file']['type']) ? strtolower($_FILES['file']['type']) : '';
    $file_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    
    // If mime type doesn't match and extension doesn't match either, reject it
    if (!in_array($file_mime, $allowed_mime_types) && !in_array($file_ext, $allowed_extensions)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Only JPG, JPEG, PNG, and GIF images are allowed. (Mime: ' . $file_mime . ')']);
        exit;
    }
    
    // If file has no extension (e.g. mobile blob upload), assign a default one
    if (empty($file_ext)) {
        if ($file_mime === 'image/png') $file_ext = 'png';
        elseif ($file_mime === 'image/gif') $file_ext = 'gif';
        else $file_ext = 'jpg';
    }
    
    // Check file size (5MB limit)
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
        exit;
    }
    
    // Temporarily save file to process OCR
    $temp_dir = __DIR__ . '/../uploads/temp/';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    $temp_filename = 'temp_ocr_' . uniqid() . '.' . $file_ext;
    $temp_target = $temp_dir . $temp_filename;
    
    if (move_uploaded_file($_FILES['file']['tmp_name'], $temp_target)) {
        // Run OCR on the temporary image
        $ocr_text = ocr_parse_document($temp_target);
        
        // Delete temporary file to clean up space
        if (file_exists($temp_target)) {
            unlink($temp_target);
        }
        
        if (empty($ocr_text)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'OCR failed to read any text. Please ensure the image is clear and well-lit.']);
            exit;
        }
        
        $extracted_data = [
            'raw_text' => $ocr_text,
            'license_number' => '',
            'license_expiration' => '',
            'restriction_code' => '',
            'plate_number' => '',
            'brand' => ''
        ];
        
        // ----------------------------------------------------
        // REGEX EXTRACTIONS BASED ON TYPE
        // ----------------------------------------------------
        if ($doc_type === 'license') {
            $license_found = false;
            $license_num_raw = '';
            
            // Match LTO License Format: A07-16-003717 (supports optional spaces and dashes)
            if (preg_match('/([A-Z]\s*[0-9]{2}\s*[\-\s]\s*[0-9]{2}\s*[\-\s]\s*[0-9]{6})/i', $ocr_text, $matches)) {
                $license_num_raw = $matches[1];
                $raw_num = strtoupper(str_replace(' ', '', $license_num_raw));
                if (strpos($raw_num, '-') === false && strlen($raw_num) === 11) {
                    $raw_num = substr($raw_num, 0, 3) . '-' . substr($raw_num, 3, 2) . '-' . substr($raw_num, 5, 6);
                }
                $extracted_data['license_number'] = $raw_num;
                $license_found = true;
            }
            
            // Match Expiration Date: 
            // 1. Try to find a date that is directly adjacent to the license number on the same line (e.g. A07-16-003717 2031/03/14)
            if ($license_found && preg_match('/' . preg_quote($license_num_raw, '/') . '\s+([0-9]{4}[\/\-][0-9]{2}[\/\-][0-9]{2})/i', $ocr_text, $matches)) {
                $extracted_data['license_expiration'] = str_replace('/', '-', $matches[1]);
            } else {
                // 2. Fallback: Search all dates, but only pick a FUTURE date (Year >= 2026) to avoid picking the birthday
                preg_match_all('/([0-9]{4}[\/\-][0-9]{2}[\/\-][0-9]{2})|([0-9]{2}[\/\-][0-9]{2}[\/\-][0-9]{4})/', $ocr_text, $date_matches, PREG_SET_ORDER);
                foreach ($date_matches as $dm) {
                    $raw_date = $dm[0];
                    $formatted_date = '';
                    
                    if (strpos($raw_date, '/') !== false || strpos($raw_date, '-') !== false) {
                        $parts = preg_split('/[\/\-]/', $raw_date);
                        if (count($parts) === 3) {
                            if (strlen($parts[0]) === 4) { // YYYY-MM-DD
                                $year = intval($parts[0]);
                                $formatted_date = str_replace('/', '-', $raw_date);
                            } else { // MM-DD-YYYY
                                $year = intval($parts[2]);
                                $formatted_date = $parts[2] . '-' . $parts[0] . '-' . $parts[1];
                            }
                            
                            // Only select if it is a future expiration date (e.g., year >= 2026)
                            if ($year >= 2026) {
                                $extracted_data['license_expiration'] = $formatted_date;
                                break;
                            }
                        }
                    }
                }
            }

            // Match DL / Restriction Codes from Front Card text (e.g. DL Codes: A, A1, B, B1, B2)
            // LTO Card text usually puts conditions on the next line or right after a space
            if (preg_match('/(?:DL\s?Codes|DL\s?Codes:?)\s*([A-Z0-9,\s\-]+)/i', $ocr_text, $matches)) {
                $raw_codes = trim($matches[1]);
                // Stop matching if we encounter conditions, name, or new line
                $clean_lines = explode("\n", $raw_codes);
                $first_line = $clean_lines[0];
                // Remove everything starting from keywords like Conditions, Name, etc.
                $first_line = preg_replace('/(?:CONDITIONS|NAME|SIGNATURE|NONE|A20).*$/i', '', $first_line);
                $extracted_data['restriction_code'] = strtoupper(trim(preg_replace('/[^A-Z0-9,\s]/i', '', $first_line)));
            } else {
                // Fallback: search for common DL patterns inside the scanned text
                $codes = [];
                foreach (['A1', 'B2', 'B1', 'BE', 'A', 'B', 'C', 'D'] as $c) {
                    if (preg_match('/\b' . $c . '\b/i', $ocr_text)) {
                        $codes[] = $c;
                    }
                }
                if (!empty($codes)) {
                    $extracted_data['restriction_code'] = implode(', ', $codes);
                }
            }
        } elseif ($doc_type === 'license_back') {
            // No automated parsing needed for back side, just verify existence.
            $extracted_data['restriction_code'] = '';
        } elseif ($doc_type === 'or') {
            // 1. Match Plate Number: "Plate No: IAG 5638"
            if (preg_match('/Plate\s*No:?\s*([A-Z]{3}\s*[0-9]{3,4})/i', $ocr_text, $matches)) {
                $extracted_data['plate_number'] = strtoupper(str_replace(' ', '', $matches[1]));
            }
            
            // 2. Match Color: "Color: WHITE PEARL SE/BLACK"
            if (preg_match('/Color:?\s*([A-Z\s\/]+)/i', $ocr_text, $matches)) {
                $extracted_data['color'] = strtoupper(trim($matches[1]));
            }
            
            // 3. Match Year Model: "Year Model: 2025"
            if (preg_match('/Year\s*Model:?\s*([0-9]{4})/i', $ocr_text, $matches)) {
                $extracted_data['year_model'] = $matches[1];
            }
        } elseif ($doc_type === 'cr') {
            // Split text to lines for structured column mapping
            $lines = explode("\n", $ocr_text);
            $lines = array_map('trim', $lines);
            
            // 1. Locate Plate Number: usually 3-4 lines below "PLATE NO." label
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'PLATE NO.') !== false) {
                    // Check next few lines for plate pattern
                    for ($j = $i + 1; $j <= $i + 6 && $j < count($lines); $j++) {
                        if (preg_match('/^([A-Z]{3}\s*[0-9]{3,4})$/i', $lines[$j], $matches)) {
                            $extracted_data['plate_number'] = strtoupper(str_replace(' ', '', $matches[1]));
                            break 2;
                        }
                    }
                }
            }
            // Fallback for Plate Number if not found by label mapping
            if (empty($extracted_data['plate_number'])) {
                if (preg_match('/([A-Z]{3}\s*[0-9]{4})/i', $ocr_text, $matches)) {
                    $extracted_data['plate_number'] = strtoupper(str_replace(' ', '', $matches[1]));
                }
            }

            // 2. Match Make/Brand
            $popular_brands = ['TOYOTA', 'HONDA', 'MITSUBISHI', 'NISSAN', 'HYUNDAI', 'ISUZU', 'FORD', 'SUZUKI', 'CHEVROLET', 'MAZDA', 'KIA', 'BMW', 'WULING', 'BYD', 'GEELY', 'CHERY'];
            foreach ($popular_brands as $b) {
                if (stripos($ocr_text, $b) !== false) {
                    $extracted_data['brand'] = $b;
                    break;
                }
            }

            // 3. Match Series/Model: usually under "SERIES" header
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'SERIES') !== false) {
                    for ($j = $i + 1; $j <= $i + 6 && $j < count($lines); $j++) {
                        // Check for common car series naming (contains words like RAIZE, FORTUNER, CIVIC, etc.)
                        if (preg_match('/^[A-Z0-9\.\-\s]{3,25}$/i', $lines[$j]) && !preg_match('/(?:GROSS|WEIGHT|NET|N\/A|1680)/i', $lines[$j])) {
                            $extracted_data['model'] = strtoupper($lines[$j]);
                            break 2;
                        }
                    }
                }
            }

            // 4. Match Color: usually under "COLOR" header
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'COLOR') !== false) {
                    for ($j = $i + 1; $j <= $i + 12 && $j < count($lines); $j++) {
                        // Skip headers and only target strings containing common color words or matches with slash "/"
                        $current_line = strtoupper($lines[$j]);
                        if (preg_match('/^[A-Z\s\/]+$/i', $current_line)) {
                            // Filter out system control keywords
                            if (preg_match('/(?:TYPE|OF|FUEL|GAS|DIESEL|PRIVATE|CLASSIFICATION|WAGON|BODY|SERIES|GROSS|NET)/i', $current_line)) {
                                continue;
                            }
                            
                            // Check if it matches a color signature or has a combination format like PEARL / BLACK / METAL
                            if (preg_match('/(?:WHITE|BLACK|RED|BLUE|GRAY|GREY|SILVER|GREEN|YELLOW|BROWN|PEARL|ORANGE|GOLD|\/)/i', $current_line)) {
                                $extracted_data['color'] = $current_line;
                                break 2;
                            }
                        }
                    }
                }
            }

            // 5. Match Year Model: usually under "YEAR MODEL" header
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'YEAR MODEL') !== false) {
                    for ($j = $i + 1; $j <= $i + 6 && $j < count($lines); $j++) {
                        if (preg_match('/^([0-9]{4})$/', $lines[$j], $matches)) {
                            $extracted_data['year_model'] = $matches[1];
                            break 2;
                        }
                    }
                }
            }

            // 6. Match Capacity: usually under "PASSENGER CAPACITY" header
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'PASSENGER CAPACITY') !== false) {
                    for ($j = $i + 1; $j <= $i + 12 && $j < count($lines); $j++) {
                        // Look for a standalone single-digit or double-digit number (e.g. 2, 4, 5, 7, 8, 15)
                        if (preg_match('/^([1-9]|1[0-9])$/', $lines[$j], $matches)) {
                            $extracted_data['capacity'] = $matches[1];
                            break 2;
                        }
                    }
                }
            }
            // General fallback: if still empty, find any single digit that could represent standard car seat count
            if (empty($extracted_data['capacity'])) {
                if (preg_match('/(?:PASSENGER CAPACITY|CAPACITY)[\s\n]*([1-9]|1[0-9])/i', $ocr_text, $matches)) {
                    $extracted_data['capacity'] = $matches[1];
                }
            }
        }
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $extracted_data
        ]);
        
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to save temporary file for OCR processing.']);
    }
} else {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
}
?>
