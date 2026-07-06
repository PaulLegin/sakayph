<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/ocr_helper.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Check if driver actually needs to re-upload (status should be action_required or rejected)
if ($pdo) {
    $stmt = $pdo->prepare("SELECT status, admin_remarks FROM users WHERE id = ?");
    $stmt->execute([$driver_id]);
    $user = $stmt->fetch();
    
    if (!$user || !in_array($user['status'], ['action_required', 'rejected'])) {
        // If they are verified or pending, they don't need this page.
        redirect('driver/dashboard.php');
    }
} else {
    $error = "Database connection error.";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $uploaded_license = '';
    $uploaded_license_back = '';
    $uploaded_or = '';
    $uploaded_cr = '';
    
    $driver_valid = true;
    
    // Check if files were uploaded
    if (isset($_FILES['license_photo']) && $_FILES['license_photo']['error'] === UPLOAD_ERR_OK &&
        isset($_FILES['license_photo_back']) && $_FILES['license_photo_back']['error'] === UPLOAD_ERR_OK &&
        isset($_FILES['official_receipt_photo']) && $_FILES['official_receipt_photo']['error'] === UPLOAD_ERR_OK &&
        isset($_FILES['certificate_registration_photo']) && $_FILES['certificate_registration_photo']['error'] === UPLOAD_ERR_OK) {
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $files = [
            'license' => $_FILES['license_photo'],
            'license_back' => $_FILES['license_photo_back'],
            'or' => $_FILES['official_receipt_photo'],
            'cr' => $_FILES['certificate_registration_photo']
        ];
        
        foreach ($files as $key => $file) {
            if (!in_array($file['type'], $allowed_types)) {
                $error = 'Invalid file format. Only JPG, JPEG, and PNG images are allowed.';
                $driver_valid = false;
                break;
            }
            if ($file['size'] > $max_size) {
                $error = 'Document file size is too large. Maximum limit is 5MB per image.';
                $driver_valid = false;
                break;
            }
        }
        
        if ($driver_valid) {
            // Generate filenames and targets
            $ext_l = pathinfo($files['license']['name'], PATHINFO_EXTENSION);
            $ext_lb = pathinfo($files['license_back']['name'], PATHINFO_EXTENSION);
            $ext_or = pathinfo($files['or']['name'], PATHINFO_EXTENSION);
            $ext_cr = pathinfo($files['cr']['name'], PATHINFO_EXTENSION);
            
            $l_name = 'license_front_reup_' . uniqid() . '.' . $ext_l;
            $lb_name = 'license_back_reup_' . uniqid() . '.' . $ext_lb;
            $or_name = 'or_reup_' . uniqid() . '.' . $ext_or;
            $cr_name = 'cr_reup_' . uniqid() . '.' . $ext_cr;
            
            if (move_uploaded_file($files['license']['tmp_name'], $upload_dir . $l_name)) $uploaded_license = 'uploads/' . $l_name;
            if (move_uploaded_file($files['license_back']['tmp_name'], $upload_dir . $lb_name)) $uploaded_license_back = 'uploads/' . $lb_name;
            if (move_uploaded_file($files['or']['tmp_name'], $upload_dir . $or_name)) $uploaded_or = 'uploads/' . $or_name;
            if (move_uploaded_file($files['cr']['tmp_name'], $upload_dir . $cr_name)) $uploaded_cr = 'uploads/' . $cr_name;
            
            if ($uploaded_license && $uploaded_license_back && $uploaded_or && $uploaded_cr) {
                try {
                    $pdo->beginTransaction();
                    
                    // Run OCR on new documents
                    $ocr_license_text = ocr_parse_document(__DIR__ . '/../' . $uploaded_license);
                    $ocr_license_back_text = ocr_parse_document(__DIR__ . '/../' . $uploaded_license_back);
                    $ocr_or_text = ocr_parse_document(__DIR__ . '/../' . $uploaded_or);
                    $ocr_cr_text = ocr_parse_document(__DIR__ . '/../' . $uploaded_cr);
                    
                    // Attempt to extract details again if possible (similar to register.php)
                    $plate_no = '';
                    if (preg_match('/([A-Z]{3}\s?[0-9]{3,4})|([0-9]{3}\s?[A-Z]{3})/i', $ocr_or_text, $matches)) {
                        $plate_no = strtoupper(str_replace(' ', '', $matches[0]));
                    }
                    if (empty($plate_no)) {
                        if (preg_match('/([A-Z]{3}\s?[0-9]{3,4})|([0-9]{3}\s?[A-Z]{3})/i', $ocr_cr_text, $matches)) {
                            $plate_no = strtoupper(str_replace(' ', '', $matches[0]));
                        }
                    }

                    // Update driver_documents
                    $stmt = $pdo->prepare("UPDATE driver_documents SET license_photo = ?, license_photo_back = ?, ocr_license_text = ?, ocr_license_back_text = ? WHERE driver_id = ?");
                    $stmt->execute([$uploaded_license, $uploaded_license_back, $ocr_license_text, $ocr_license_back_text, $driver_id]);
                    
                    // Update vehicles (only update plate if extracted, else leave as is)
                    if (!empty($plate_no)) {
                        $stmt = $pdo->prepare("UPDATE vehicles SET official_receipt_photo = ?, certificate_registration_photo = ?, ocr_or_text = ?, ocr_cr_text = ?, plate_number = ? WHERE driver_id = ?");
                        $stmt->execute([$uploaded_or, $uploaded_cr, $ocr_or_text, $ocr_cr_text, $plate_no, $driver_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE vehicles SET official_receipt_photo = ?, certificate_registration_photo = ?, ocr_or_text = ?, ocr_cr_text = ? WHERE driver_id = ?");
                        $stmt->execute([$uploaded_or, $uploaded_cr, $ocr_or_text, $ocr_cr_text, $driver_id]);
                    }
                    
                    // Reset user status to pending_verification and clear remarks
                    $stmt = $pdo->prepare("UPDATE users SET status = 'pending_verification', admin_remarks = NULL WHERE id = ?");
                    $stmt->execute([$driver_id]);
                    
                    $pdo->commit();
                    
                    $_SESSION['trip_success'] = 'Documents re-uploaded successfully! Please wait for Admin review.';
                    redirect('driver/dashboard.php');
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Database error during update: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to upload one or more files.';
            }
        }
    } else {
        $error = 'Please upload all 4 required document images.';
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card card-custom p-4 p-md-5 shadow border-danger">
                <div class="text-center mb-4">
                    <i class="bi bi-file-earmark-arrow-up-fill text-danger display-4 mb-2"></i>
                    <h2 class="fw-bold text-white">Re-upload Documents</h2>
                    <p class="text-muted">Please provide clearer scans of your documents as requested by the admin.</p>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 rounded-3 border-0 small mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div class="alert alert-warning mb-4 rounded-3 border-0">
                    <h6 class="fw-bold mb-1"><i class="bi bi-info-circle me-1"></i>Admin Remarks:</h6>
                    <p class="mb-0 small">"<?php echo htmlspecialchars($user['admin_remarks']); ?>"</p>
                </div>

                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-white small fw-bold">License Front Photo <span class="text-danger">*</span></label>
                            <input class="form-control form-control-custom text-white" type="file" name="license_photo" accept="image/*" required>
                            <div class="form-text text-muted small mt-1">Clear photo showing details.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white small fw-bold">License Back Photo <span class="text-danger">*</span></label>
                            <input class="form-control form-control-custom text-white" type="file" name="license_photo_back" accept="image/*" required>
                            <div class="form-text text-muted small mt-1">Showing restriction codes.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white small fw-bold">LTO Official Receipt (OR) <span class="text-danger">*</span></label>
                            <input class="form-control form-control-custom text-white" type="file" name="official_receipt_photo" accept="image/*" required>
                            <div class="form-text text-muted small mt-1">Latest OR document.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white small fw-bold">LTO Certificate of Registration (CR) <span class="text-danger">*</span></label>
                            <input class="form-control form-control-custom text-white" type="file" name="certificate_registration_photo" accept="image/*" required>
                            <div class="form-text text-muted small mt-1">Vehicle CR document.</div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-3 justify-content-between align-items-center border-top border-secondary pt-4 mt-2">
                        <a href="dashboard.php" class="btn btn-outline-secondary px-4 py-2">Cancel</a>
                        <button type="submit" class="btn btn-danger px-4 py-2 fw-bold"><i class="bi bi-cloud-upload me-2"></i>Submit Documents</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
