<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/ocr_helper.php';
redirect_if_logged_in();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $birthday = isset($_POST['birthday']) ? trim($_POST['birthday']) : '';
    $password = trim($_POST['password']);
    $role = $_POST['role']; // client or driver
    $terms = isset($_POST['terms']) ? intval($_POST['terms']) : 0;
    
    // Calculate Age
    $age = 0;
    if (!empty($birthday)) {
        $birthDate = new DateTime($birthday);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
    }

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($gender) || empty($birthday) || empty($password) || empty($role)) {
        $error = 'Please fill in all general fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = 'Please enter a valid Philippine mobile number (e.g. 09171234567, 11 digits).';
    } elseif ($age < 18) {
        $error = 'You must be 18 years or older to register an account on SakayPH.';
    } elseif ($terms !== 1) {
        $error = 'You must agree to the Terms of Service and Privacy Policy to register.';
    } else {
        if ($pdo) {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email address is already registered.';
            } else {
                // If Driver, validate driver fields and file uploads
                $driver_valid = true;
                $license_no = '';
                $brand = '';
                $model = '';
                $plate_no = '';
                $capacity = '';
                
                $uploaded_license = '';
                $uploaded_license_back = '';
                $uploaded_or = '';
                $uploaded_cr = '';
                
                if ($role === 'driver') {
                    $license_no = trim($_POST['license_number']);
                    $license_exp = trim($_POST['license_expiration']);
                    $restriction = trim($_POST['restriction_code']);
                    
                    $brand = trim($_POST['brand']);
                    $model = trim($_POST['model']);
                    $plate_no = trim($_POST['plate_number']);
                    $capacity = intval($_POST['capacity']);
                    $color = trim($_POST['color']);
                    $year_model = intval($_POST['year_model']);
                    
                    if (empty($license_no) || empty($license_exp) || empty($restriction) || empty($brand) || empty($model) || empty($plate_no) || empty($capacity) || empty($color) || empty($year_model)) {
                        $error = 'Please fill in all driver and vehicle fields.';
                        $driver_valid = false;
                    } elseif (!isset($_FILES['license_photo']) || $_FILES['license_photo']['error'] !== UPLOAD_ERR_OK) {
                        $error = 'Please upload a clear photo of the FRONT of your Driver\'s License.';
                        $driver_valid = false;
                    } elseif (!isset($_FILES['license_photo_back']) || $_FILES['license_photo_back']['error'] !== UPLOAD_ERR_OK) {
                        $error = 'Please upload a clear photo of the BACK of your Driver\'s License.';
                        $driver_valid = false;
                    } elseif (!isset($_FILES['official_receipt_photo']) || $_FILES['official_receipt_photo']['error'] !== UPLOAD_ERR_OK) {
                        $error = 'Please upload a clear photo of your LTO Official Receipt (OR).';
                        $driver_valid = false;
                    } elseif (!isset($_FILES['certificate_registration_photo']) || $_FILES['certificate_registration_photo']['error'] !== UPLOAD_ERR_OK) {
                        $error = 'Please upload a clear photo of your LTO Certificate of Registration (CR).';
                        $driver_valid = false;
                    }
                    
                    // Handle file uploads if general validation passed
                    if ($driver_valid) {
                        $upload_dir = __DIR__ . '/uploads/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                        $allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                        $max_size = 5 * 1024 * 1024; // 5MB
                        
                        // Process License Front Upload
                        $license_mime = isset($_FILES['license_photo']['type']) ? strtolower($_FILES['license_photo']['type']) : '';
                        $license_ext = strtolower(pathinfo($_FILES['license_photo']['name'], PATHINFO_EXTENSION));
                        $license_size = $_FILES['license_photo']['size'];
                        if (empty($license_ext) && $license_mime === 'image/png') $license_ext = 'png';
                        elseif (empty($license_ext)) $license_ext = 'jpg';

                        // Process License Back Upload
                        $license_back_mime = isset($_FILES['license_photo_back']['type']) ? strtolower($_FILES['license_photo_back']['type']) : '';
                        $license_back_ext = strtolower(pathinfo($_FILES['license_photo_back']['name'], PATHINFO_EXTENSION));
                        $license_back_size = $_FILES['license_photo_back']['size'];
                        if (empty($license_back_ext) && $license_back_mime === 'image/png') $license_back_ext = 'png';
                        elseif (empty($license_back_ext)) $license_back_ext = 'jpg';
                        
                        // Process OR Upload
                        $or_mime = isset($_FILES['official_receipt_photo']['type']) ? strtolower($_FILES['official_receipt_photo']['type']) : '';
                        $or_ext = strtolower(pathinfo($_FILES['official_receipt_photo']['name'], PATHINFO_EXTENSION));
                        $or_size = $_FILES['official_receipt_photo']['size'];
                        if (empty($or_ext) && $or_mime === 'image/png') $or_ext = 'png';
                        elseif (empty($or_ext)) $or_ext = 'jpg';

                        // Process CR Upload
                        $cr_mime = isset($_FILES['certificate_registration_photo']['type']) ? strtolower($_FILES['certificate_registration_photo']['type']) : '';
                        $cr_ext = strtolower(pathinfo($_FILES['certificate_registration_photo']['name'], PATHINFO_EXTENSION));
                        $cr_size = $_FILES['certificate_registration_photo']['size'];
                        if (empty($cr_ext) && $cr_mime === 'image/png') $cr_ext = 'png';
                        elseif (empty($cr_ext)) $cr_ext = 'jpg';
                        
                        // Validation Checks
                        $valid_types = true;
                        $files_to_check = [
                            ['mime' => $license_mime, 'ext' => $license_ext],
                            ['mime' => $license_back_mime, 'ext' => $license_back_ext],
                            ['mime' => $or_mime, 'ext' => $or_ext],
                            ['mime' => $cr_mime, 'ext' => $cr_ext]
                        ];
                        
                        foreach ($files_to_check as $f) {
                            if (!in_array($f['mime'], $allowed_mimes) && !in_array($f['ext'], $allowed_extensions)) {
                                $valid_types = false;
                                break;
                            }
                        }
                        
                        if (!$valid_types) {
                            $error = 'Invalid file format. Only JPG, JPEG, PNG, and GIF images are allowed for document uploads.';
                            $driver_valid = false;
                        } elseif ($license_size > $max_size || $license_back_size > $max_size || $or_size > $max_size || $cr_size > $max_size) {
                            $error = 'Document file size is too large. Maximum limit is 5MB per image.';
                            $driver_valid = false;
                        } else {
                            // Save License Front File
                            $license_filename = 'license_front_' . uniqid() . '.' . $license_ext;
                            $license_target = $upload_dir . $license_filename;

                            // Save License Back File
                            $license_back_filename = 'license_back_' . uniqid() . '.' . $license_back_ext;
                            $license_back_target = $upload_dir . $license_back_filename;
                            
                            // Save OR File
                            $or_filename = 'or_' . uniqid() . '.' . $or_ext;
                            $or_target = $upload_dir . $or_filename;

                            // Save CR File
                            $cr_filename = 'cr_' . uniqid() . '.' . $cr_ext;
                            $cr_target = $upload_dir . $cr_filename;
                            
                            if (move_uploaded_file($_FILES['license_photo']['tmp_name'], $license_target)) {
                                $uploaded_license = 'uploads/' . $license_filename;
                            } else {
                                $error = 'Failed to save License Front photo.';
                                $driver_valid = false;
                            }

                            if ($driver_valid && move_uploaded_file($_FILES['license_photo_back']['tmp_name'], $license_back_target)) {
                                $uploaded_license_back = 'uploads/' . $license_back_filename;
                            } else {
                                $error = 'Failed to save License Back photo.';
                                $driver_valid = false;
                            }
                            
                            if ($driver_valid && move_uploaded_file($_FILES['official_receipt_photo']['tmp_name'], $or_target)) {
                                $uploaded_or = 'uploads/' . $or_filename;
                            } else {
                                $error = 'Failed to save LTO Official Receipt photo.';
                                $driver_valid = false;
                            }

                            if ($driver_valid && move_uploaded_file($_FILES['certificate_registration_photo']['tmp_name'], $cr_target)) {
                                $uploaded_cr = 'uploads/' . $cr_filename;
                            } else {
                                $error = 'Failed to save LTO Certificate of Registration photo.';
                                $driver_valid = false;
                            }
                        }
                    }
                }
                
                // If everything is valid, proceed with insertion
                if (empty($error) && ($role === 'client' || ($role === 'driver' && $driver_valid))) {
                    try {
                        $pdo->beginTransaction();
                        
                        // Set status: drivers are pending, clients are verified instantly
                        $status = ($role === 'driver') ? 'pending_verification' : 'verified';
                        $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Email verification logic
                        $verification_token = bin2hex(random_bytes(16));
                        // For drivers, we can automatically set email as verified to avoid double validation (since admin reviews them manually anyway)
                        $is_email_verified = ($role === 'driver') ? 1 : 0;

                        // Insert User
                        $stmt = $pdo->prepare("
                            INSERT INTO users (first_name, last_name, email, phone, gender, birthday, password, role, status, is_email_verified, email_verification_token) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$first_name, $last_name, $email, $phone, $gender, $birthday, $hashed_pass, $role, $status, $is_email_verified, $verification_token]);
                        $new_user_id = $pdo->lastInsertId();
                        
                        // If driver, insert vehicle and documents + run OCR
                        if ($role === 'driver') {
                            // Run OCR on Driver's License Front
                            $license_path_absolute = __DIR__ . '/' . $uploaded_license;
                            $ocr_license_text = ocr_parse_document($license_path_absolute);

                            // Run OCR on Driver's License Back
                            $license_back_path_absolute = __DIR__ . '/' . $uploaded_license_back;
                            $ocr_license_back_text = ocr_parse_document($license_back_path_absolute);
                            
                            // Run OCR on LTO OR
                            $or_path_absolute = __DIR__ . '/' . $uploaded_or;
                            $ocr_or_text = ocr_parse_document($or_path_absolute);

                            // Run OCR on LTO CR
                            $cr_path_absolute = __DIR__ . '/' . $uploaded_cr;
                            $ocr_cr_text = ocr_parse_document($cr_path_absolute);
                            
                            // ----------------------------------------------------
                            // AUTOMATED OCR EXTRACTION & AUTOFILL OVERRIDES
                            // ----------------------------------------------------
                            // 1. Try to extract LTO License Number format from License Card FRONT scan
                            if (!empty($ocr_license_text)) {
                                if (preg_match('/([A-Z][0-9]{2}-[0-9]{2}-[0-9]{6})/i', $ocr_license_text, $matches)) {
                                    $license_no = strtoupper($matches[1]);
                                }
                            }

                            // 2. Try to extract restriction codes or other tags from License BACK scan
                            if (!empty($ocr_license_back_text)) {
                                // Match DL codes like A,A1,B,B1,B2
                                if (preg_match('/DL\s?Codes:?\s?([A-Z0-9,\s]+)/i', $ocr_license_back_text, $matches)) {
                                    $restriction = trim($matches[1]);
                                }
                            }
                            
                            // 3. Try to extract Plate Number from LTO OR text (OR details)
                            if (!empty($ocr_or_text)) {
                                // Match 3 letters + 3/4 digits OR 3 digits + 3 letters
                                if (preg_match('/([A-Z]{3}\s?[0-9]{3,4})|([0-9]{3}\s?[A-Z]{3})/i', $ocr_or_text, $matches)) {
                                    $plate_no = strtoupper(str_replace(' ', '', $matches[0]));
                                }
                            }
                            
                            // 4. Try to extract Brand/Make from LTO CR text
                            if (!empty($ocr_cr_text)) {
                                $popular_brands = ['TOYOTA', 'HONDA', 'MITSUBISHI', 'NISSAN', 'HYUNDAI', 'ISUZU', 'FORD', 'SUZUKI', 'CHEVROLET', 'MAZDA', 'KIA', 'BMW'];
                                foreach ($popular_brands as $b) {
                                    if (stripos($ocr_cr_text, $b) !== false) {
                                        $brand = $b;
                                        break;
                                    }
                                }

                                // Fallback plate extraction from CR if OR was blurry
                                if (empty($plate_no)) {
                                    if (preg_match('/([A-Z]{3}\s?[0-9]{3,4})|([0-9]{3}\s?[A-Z]{3})/i', $ocr_cr_text, $matches)) {
                                        $plate_no = strtoupper(str_replace(' ', '', $matches[0]));
                                    }
                                }
                            }
                            
                            // Insert Documents (with separated Front and Back columns)
                            $stmt = $pdo->prepare("
                                INSERT INTO driver_documents (driver_id, license_number, license_expiration, restriction_code, license_photo, license_photo_back, ocr_license_text, ocr_license_back_text) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$new_user_id, $license_no, $license_exp, $restriction, $uploaded_license, $uploaded_license_back, $ocr_license_text, $ocr_license_back_text]);
                            
                            // Insert Vehicle details (with color, year_model, and separated OR and CR columns)
                            $stmt = $pdo->prepare("
                                INSERT INTO vehicles (driver_id, brand, model, plate_number, capacity, color, year_model, official_receipt_photo, certificate_registration_photo, ocr_or_text, ocr_cr_text) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$new_user_id, $brand, $model, $plate_no, $capacity, $color, $year_model, $uploaded_or, $uploaded_cr, $ocr_or_text, $ocr_cr_text]);
                        }
                        
                        $pdo->commit();

                        // ----------------------------------------------------
                        // SEND VERIFICATION EMAIL (Simulated or API)
                        // ----------------------------------------------------
                        if ($role === 'client') {
                            require_once __DIR__ . '/helpers/email_helper.php';
                            $full_name = $first_name . ' ' . $last_name;
                            send_verification_email($email, $full_name, $verification_token);
                            
                            $success = 'Registration successful! We have sent a verification email to your address. Please check your inbox (simulated in email_logs.txt) to verify your account before logging in.';
                        } else {
                            $success = 'Registration successful! Please wait for Admin approval of your vehicle and license documents before posting trips.';
                        }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Failed to register account: ' . $e->getMessage();
                        
                        // Clean up files if failed
                        if (!empty($uploaded_license) && file_exists(__DIR__ . '/' . $uploaded_license)) {
                            unlink(__DIR__ . '/' . $uploaded_license);
                        }
                        if (!empty($uploaded_or_cr) && file_exists(__DIR__ . '/' . $uploaded_or_cr)) {
                            unlink(__DIR__ . '/' . $uploaded_or_cr);
                        }
                    }
                }
            }
        } else {
            $error = 'Database connection error.';
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>
<script>
// Global Javascript Error Logger to capture boot errors
window.onerror = function(message, source, lineno, colno, error) {
    alert("JS ERROR DETECTED:\nMessage: " + message + "\nLine: " + lineno + "\nSource: " + source);
    return false;
};
</script>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card card-custom p-4 shadow">
                <div class="text-center mb-4">
                    <h2 class="fw-bold" style="background: var(--primary-gradient); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Join SakayPH</h2>
                    <p class="text-muted small">Sign up as a client to rent rides, or as a verified driver to start earning.</p>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 rounded-3 border-0 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success py-2 rounded-3 border-0 small" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?> 
                        <a href="login.php" class="alert-link text-decoration-none ms-2" style="font-weight:700;">Log In Now &rarr;</a>
                    </div>
                <?php endif; ?>
                
                <form action="register.php" method="POST" enctype="multipart/form-data">
                    <h5 class="fw-bold text-white border-bottom border-secondary pb-2 mb-3">1. General Information</h5>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label text-white small fw-bold">First Name</label>
                            <input type="text" name="first_name" id="first_name" class="form-control form-control-custom text-white" placeholder="e.g. Juan" required autocomplete="off">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label text-white small fw-bold">Last Name</label>
                            <input type="text" name="last_name" id="last_name" class="form-control form-control-custom text-white" placeholder="e.g. Dela Cruz" required autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label text-white small fw-bold">Mobile Number</label>
                            <input type="text" name="phone" id="phone" class="form-control form-control-custom text-white" placeholder="e.g. 09171234567" required autocomplete="off">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label text-white small fw-bold">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control form-control-custom text-white" placeholder="e.g. juan.delacruz@gmail.com" required autocomplete="off">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="gender" class="form-label text-white small fw-bold">Gender</label>
                            <select name="gender" id="gender" class="form-select form-control-custom text-white" required>
                                <option value="" disabled selected>Select Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="birthday" class="form-label text-white small fw-bold">Birthday</label>
                            <input type="date" name="birthday" id="birthday" class="form-control form-control-custom text-white" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label for="password" class="form-label text-white small">Password</label>
                            <input type="password" name="password" id="password" class="form-control form-control-custom text-white" placeholder="••••••••" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-white small d-block fw-bold">Register As</label>
                        <div class="d-flex gap-4 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="role_client" value="client">
                                <label class="form-check-label text-white" for="role_client">
                                    <i class="bi bi-person-fill me-1 text-info"></i>Passenger / Client
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="role_driver" value="driver" checked>
                                <label class="form-check-label text-white" for="role_driver">
                                    <i class="bi bi-car-front-fill me-1 text-warning"></i>Verified Driver
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Driver Specific Fields (Visible by default for layout preview) -->
                    <div id="driver_fields" style="display: block;">
                        
                        <!-- STEP 2: DRIVER'S LICENSE -->
                        <div class="card p-4 mb-3 border border-secondary shadow-sm" style="background: rgba(255,255,255,0.02); border-radius:16px;">
                            <h5 class="fw-bold text-info border-bottom border-secondary pb-2 mb-3"><i class="bi bi-card-heading me-2"></i>Step 2: Driver's License Details</h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="license_number" class="form-label text-white small fw-bold">License Number</label>
                                    <input type="text" name="license_number" id="license_number" class="form-control form-control-custom text-white fw-bold" placeholder="Auto-filled (e.g. N01-12-345678)" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="license_expiration" class="form-label text-white small fw-bold">Expiration Date</label>
                                    <input type="date" name="license_expiration" id="license_expiration" class="form-control form-control-custom text-white" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="restriction_code" class="form-label text-white small fw-bold">Restriction Code (DL Codes)</label>
                                <input type="text" name="restriction_code" id="restriction_code" class="form-control form-control-custom text-white" placeholder="Auto-filled (e.g. A, A1, B, B1, B2)" required>
                            </div>
                            
                            
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="license_photo" class="form-label text-white small fw-bold">Upload License Front (Required)</label>
                                    <input type="file" name="license_photo" id="license_photo" class="form-control form-control-custom text-white" accept="image/*" capture="environment" required onchange="triggerOCR(this, 'license', 'license_ocr_status')">
                                    <small class="text-white-50 d-block mt-1" style="font-size:0.75rem;">Front side showing photo and name.</small>
                                    <div id="license_ocr_status" class="mt-2 text-info small d-none">
                                        <div class="spinner-border spinner-border-sm text-info me-1" role="status"></div>
                                        <span>Reading License Front...</span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="license_photo_back" class="form-label text-white small fw-bold">Upload License Back (Required)</label>
                                    <input type="file" name="license_photo_back" id="license_photo_back" class="form-control form-control-custom text-white" accept="image/*" capture="environment" required>
                                    <small class="text-white-50 d-block mt-1" style="font-size:0.75rem;">Back side showing DL codes.</small>
                                    <div id="license_back_ocr_status" class="mt-2 text-info small d-none">
                                        <div class="spinner-border spinner-border-sm text-info me-1" role="status"></div>
                                        <span>Reading License Back...</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 3: VEHICLE & DOCUMENTS DETAILS -->
                        <div class="card p-4 mb-3 border border-secondary shadow-sm" style="background: rgba(255,255,255,0.02); border-radius:16px;">
                            <h5 class="fw-bold text-success border-bottom border-secondary pb-2 mb-3"><i class="bi bi-car-front-fill me-2"></i>Step 3: Vehicle Information & Documents</h5>
                            
                            <!-- Documents Upload Fields (Placed first for easier OCR autofill flow) -->
                            <div class="row mb-3">
                                <div class="col-md-6 mb-3">
                                    <label for="certificate_registration_photo" class="form-label text-white small fw-bold">Upload Certificate of Registration (CR) (Required)</label>
                                    <input type="file" name="certificate_registration_photo" id="certificate_registration_photo" class="form-control form-control-custom text-white" accept="image/*" capture="environment" required onchange="triggerOCR(this, 'cr', 'cr_ocr_status')">
                                    <small class="text-white-50 d-block mt-1" style="font-size:0.75rem;">Clear photo of your LTO CR document.</small>
                                    <div id="cr_ocr_status" class="mt-2 text-info small d-none">
                                        <div class="spinner-border spinner-border-sm text-info me-1" role="status"></div>
                                        <span>Reading LTO CR...</span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="official_receipt_photo" class="form-label text-white small">Upload Official Receipt (OR) (Required)</label>
                                    <input type="file" name="official_receipt_photo" id="official_receipt_photo" class="form-control form-control-custom text-white" accept="image/*" capture="environment" required onchange="triggerOCR(this, 'or', 'or_ocr_status')">
                                    <small class="text-white-50 d-block mt-1" style="font-size:0.75rem;">Clear photo of your LTO OR paper.</small>
                                    <div id="or_ocr_status" class="mt-2 text-info small d-none">
                                        <div class="spinner-border spinner-border-sm text-info me-1" role="status"></div>
                                        <span>Reading LTO OR...</span>
                                    </div>
                                </div>
                            </div>

                            <hr class="border-secondary mb-4">
                            <h6 class="text-white-50 mb-3 small fw-bold">Vehicle Specifications (Auto-filled upon upload)</h6>

                            <!-- Vehicle Specifications Input Fields -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="plate_number" class="form-label text-white small fw-bold">Plate Number</label>
                                    <input type="text" name="plate_number" id="plate_number" class="form-control form-control-custom text-white fw-bold" placeholder="Auto-filled (e.g. WBO586)" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="brand" class="form-label text-white small fw-bold">Vehicle Make (Brand)</label>
                                    <input type="text" name="brand" id="brand" class="form-control form-control-custom text-white" placeholder="Auto-filled (e.g. TOYOTA)" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="model" class="form-label text-white small fw-bold">Vehicle Model</label>
                                    <input type="text" name="model" id="model" class="form-control form-control-custom text-white" placeholder="e.g. Fortuner" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="color" class="form-label text-white small fw-bold">Vehicle Color</label>
                                    <input type="text" name="color" id="color" class="form-control form-control-custom text-white" placeholder="e.g. White" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="year_model" class="form-label text-white small fw-bold">Year Model</label>
                                    <input type="number" name="year_model" id="year_model" class="form-control form-control-custom text-white" min="1990" max="2030" placeholder="e.g. 2022" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="capacity" class="form-label text-white small">Vehicle Seating Capacity <span class="text-muted">(incl. driver)</span></label>
                                    <input type="number" name="capacity" id="capacity" class="form-control form-control-custom text-white" min="1" max="20" placeholder="e.g. 5 or 7" required>
                                    <div class="form-text text-muted small mt-1">Include the driver in the count. Example: A standard sedan is a 5-Seater.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Terms of Service Checkbox -->
                    <div class="form-check mt-3 mb-2">
                        <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" required>
                        <label class="form-check-label text-white-50 small" for="terms">
                            I agree to the <a href="#" class="text-decoration-none text-white fw-medium">Terms of Service</a> and <a href="#" class="text-decoration-none text-white fw-medium">Privacy Policy</a> of SakayPH.
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-gradient-primary w-100 py-3 mt-2">
                        <i class="bi bi-person-plus-fill me-2"></i>Sign Up
                    </button>
                    
                    <div class="text-center mt-3">
                        <p class="text-muted small mb-0">Already registered? <a href="login.php" class="text-decoration-none" style="color: var(--primary); font-weight:600;">Login here</a></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Dynamic UI Radio Switcher & AJAX OCR Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const clientRadio = document.getElementById('role_client');
    const driverRadio = document.getElementById('role_driver');
    const driverFields = document.getElementById('driver_fields');
    
    // Inputs selectors
    const brandInput = document.getElementById('brand');
    const modelInput = document.getElementById('model');
    const plateInput = document.getElementById('plate_number');
    const capInput = document.getElementById('capacity');
    const licInput = document.getElementById('license_number');
    const licExpInput = document.getElementById('license_expiration');
    const licRestInput = document.getElementById('restriction_code');
    const colorInput = document.getElementById('color');
    const yearModelInput = document.getElementById('year_model');
    
    // File inputs selectors
    const licPhoto = document.getElementById('license_photo');
    const licPhotoBack = document.getElementById('license_photo_back');
    const orPhoto = document.getElementById('official_receipt_photo');
    const crPhoto = document.getElementById('certificate_registration_photo');
    
    function toggleDriverFields() {
        if (driverRadio.checked) {
            driverFields.style.display = 'block';
            brandInput.setAttribute('required', 'true');
            modelInput.setAttribute('required', 'true');
            plateInput.setAttribute('required', 'true');
            capInput.setAttribute('required', 'true');
            licInput.setAttribute('required', 'true');
            licExpInput.setAttribute('required', 'true');
            licRestInput.setAttribute('required', 'true');
            colorInput.setAttribute('required', 'true');
            yearModelInput.setAttribute('required', 'true');
            licPhoto.setAttribute('required', 'true');
            licPhotoBack.setAttribute('required', 'true');
            orPhoto.setAttribute('required', 'true');
            crPhoto.setAttribute('required', 'true');
        } else {
            driverFields.style.display = 'none';
            brandInput.removeAttribute('required');
            modelInput.removeAttribute('required');
            plateInput.removeAttribute('required');
            capInput.removeAttribute('required');
            licInput.removeAttribute('required');
            licExpInput.removeAttribute('required');
            licRestInput.removeAttribute('required');
            colorInput.removeAttribute('required');
            yearModelInput.removeAttribute('required');
            licPhoto.removeAttribute('required');
            licPhotoBack.removeAttribute('required');
            orPhoto.removeAttribute('required');
            crPhoto.removeAttribute('required');
        }
    }
    
    clientRadio.addEventListener('change', toggleDriverFields);
    driverRadio.addEventListener('change', toggleDriverFields);
    
    // Initial trigger
    toggleDriverFields();

    // --------------------------------------------------------
    // REAL-TIME AJAX OCR AUTOFILL TRIGGER HANDLERS
    // --------------------------------------------------------
    function processAJAXOCR(fileInput, docType, statusDivId, targetInputObj) {
        const file = fileInput.files[0];
        if (!file) return;

        const statusDiv = document.getElementById(statusDivId);
        statusDiv.classList.remove('d-none');
        statusDiv.querySelector('span').innerText = 'Reading document details using OCR...';
        statusDiv.querySelector('span').className = 'text-info';

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', docType);

        fetch('helpers/ajax_ocr.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP status ' + response.status);
            }
            return response.text(); // Get raw text first to debug PHP warnings
        })
        .then(responseText => {
            try {
                const result = JSON.parse(responseText);
                if (result.success) {
                    statusDiv.querySelector('span').innerText = 'OCR scan completed successfully!';
                    statusDiv.querySelector('span').className = 'text-success';
                    
                    // Display raw text in debug box on screen
                    const dbgBox = document.getElementById('ocr_debug_box');
                    const dbgContent = document.getElementById('ocr_debug_content');
                    if (dbgBox && dbgContent) {
                        dbgBox.style.display = 'block';
                        dbgContent.innerText = result.data.raw_text;
                    }
                    
                    // Autofill targets based on document type
                    if (docType === 'license') {
                        if (result.data.license_number) {
                            licInput.value = result.data.license_number;
                        }
                        if (result.data.license_expiration) {
                            licExpInput.value = result.data.license_expiration;
                        }
                        if (result.data.restriction_code) {
                            licRestInput.value = result.data.restriction_code;
                        }
                    } else if (docType === 'or' && result.data.plate_number) {
                        plateInput.value = result.data.plate_number;
                    } else if (docType === 'cr') {
                        if (result.data.brand) {
                            brandInput.value = result.data.brand;
                        }
                        if (result.data.plate_number) {
                            plateInput.value = result.data.plate_number;
                        }
                        if (result.data.model) {
                            modelInput.value = result.data.model;
                        }
                        if (result.data.capacity) {
                            capInput.value = result.data.capacity;
                        }
                        if (result.data.color) {
                            colorInput.value = result.data.color;
                        }
                        if (result.data.year_model) {
                            yearModelInput.value = result.data.year_model;
                        }
                    }
                } else {
                    statusDiv.querySelector('span').innerText = 'OCR Notice: ' + result.message;
                    statusDiv.querySelector('span').className = 'text-warning';
                }
            } catch (jsonError) {
                statusDiv.querySelector('span').innerText = 'Server Response Error.';
                statusDiv.querySelector('span').className = 'text-danger';
            }
            // Auto hide notice after 15 seconds so user can read debug
            setTimeout(() => { statusDiv.classList.add('d-none'); }, 15000);
        })
        .catch(err => {
            statusDiv.querySelector('span').innerText = 'OCR Connection error.';
            statusDiv.querySelector('span').className = 'text-danger';
            setTimeout(() => { statusDiv.classList.add('d-none'); }, 15000);
        });
    }

    // Expose wrapper function globally for inline onchange hooks
    window.triggerOCR = function(fileInput, docType, statusDivId) {
        let targetInput = null;
        if (docType === 'license') targetInput = licInput;
        else if (docType === 'or') targetInput = plateInput;
        else if (docType === 'cr') targetInput = brandInput;
        
        processAJAXOCR(fileInput, docType, statusDivId, targetInput);
    };

    window.copyDebugOCR = function() {
        const text = document.getElementById('ocr_debug_content').innerText;
        navigator.clipboard.writeText(text).then(() => {
            alert('Scanned raw text copied to clipboard successfully!');
        }).catch(err => {
            alert('Failed to copy text: ' + err);
        });
    };

    // Bind triggers to inputs change event (standard fallback)
    licPhoto.addEventListener('change', function() {
        processAJAXOCR(this, 'license', 'license_ocr_status', licInput);
    });

    orPhoto.addEventListener('change', function() {
        processAJAXOCR(this, 'or', 'or_ocr_status', plateInput);
    });

    crPhoto.addEventListener('change', function() {
        processAJAXOCR(this, 'cr', 'cr_ocr_status', brandInput);
    });
});
</script>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
