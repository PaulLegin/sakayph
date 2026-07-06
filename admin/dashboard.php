<?php
require_once __DIR__ . '/../config.php';
require_login(['admin']);

$error = '';
$success = '';

// Handle System Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $new_commission = floatval($_POST['commission_rate']);
    $new_min_payout = floatval($_POST['min_payout_amount']);
    
    if ($new_commission < 0 || $new_commission > 100 || $new_min_payout < 0) {
        $error = 'Invalid settings values.';
    } else {
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('commission_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$new_commission, $new_commission]);
                
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('min_payout_amount', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$new_min_payout, $new_min_payout]);
                
                $_SESSION['settings_success'] = 'System settings updated successfully!';
                redirect('admin/dashboard.php');
            } catch (PDOException $e) {
                $error = 'Failed to update settings: ' . $e->getMessage();
            }
        }
    }
}

// Handle Payout Approval
if (isset($_GET['action']) && $_GET['action'] === 'complete_payout') {
    $payout_id = intval($_GET['payout_id']);
    if ($payout_id > 0 && $pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE payouts SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$payout_id]);
            $_SESSION['admin_success'] = 'Payout marked as completed successfully!';
            redirect('admin/dashboard.php');
        } catch (PDOException $e) {
            $error = 'Failed to complete payout: ' . $e->getMessage();
        }
    }
}

// Handle Admin Trip Actions (Cancel or Complete Trip)
if (isset($_GET['action']) && in_array($_GET['action'], ['cancel_trip', 'complete_trip'])) {
    $trip_id = intval($_GET['trip_id']);
    $act = $_GET['action'];
    
    if ($trip_id > 0 && $pdo) {
        try {
            if ($act === 'cancel_trip') {
                $pdo->beginTransaction();
                // 1. Cancel Trip
                $stmt = $pdo->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$trip_id]);
                // 2. Cancel related booking
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE trip_id = ?");
                $stmt->execute([$trip_id]);
                
                $pdo->commit();
                $_SESSION['admin_success'] = 'Trip and its bookings have been cancelled successfully!';
            } elseif ($act === 'complete_trip') {
                $pdo->beginTransaction();
                // 1. Complete Trip
                $stmt = $pdo->prepare("UPDATE trips SET status = 'completed', completed_at = NOW() WHERE id = ?");
                $stmt->execute([$trip_id]);
                
                // 2. Get booking to credit driver
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE trip_id = ? AND status = 'confirmed'");
                $stmt->execute([$trip_id]);
                $booking = $stmt->fetch();
                
                if ($booking) {
                    // Update booking status
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
                    $stmt->execute([$booking['id']]);
                    
                    // Credit driver wallet
                    $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = (SELECT driver_id FROM trips WHERE id = ?)");
                    $stmt->execute([$booking['driver_earnings'], $trip_id]);
                }
                
                $pdo->commit();
                $_SESSION['admin_success'] = 'Trip manually marked as Completed. Wallet balance credited to driver!';
            }
            redirect('admin/dashboard.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Trip operation failed: ' . $e->getMessage();
        }
    }
}

// Load stats and tables
$stats = [
    'drivers' => 0,
    'clients' => 0,
    'earnings' => 0.00,
    'active_trips' => 0
];
$pending_drivers = [];
$pending_payouts = [];

if ($pdo) {
    try {
        // Stats queries
        $stats['drivers'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn();
        $stats['clients'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();
        $stats['earnings'] = floatval($pdo->query("SELECT IFNULL(SUM(admin_commission), 0.00) FROM bookings WHERE status = 'confirmed'")->fetchColumn());
        $stats['active_trips'] = $pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'active'")->fetchColumn();
        
        // Fetch pending drivers with documents and vehicle details
        $stmt = $pdo->query("
            SELECT u.*, d.license_number, d.license_expiration, d.restriction_code, d.license_photo, d.license_photo_back, d.ocr_license_text, d.ocr_license_back_text, 
                   v.brand, v.model, v.plate_number, v.capacity, v.color, v.year_model, v.official_receipt_photo, v.certificate_registration_photo, v.ocr_or_text, v.ocr_cr_text 
            FROM users u 
            JOIN driver_documents d ON u.id = d.driver_id 
            JOIN vehicles v ON u.id = v.driver_id 
            WHERE u.role = 'driver' AND u.status = 'pending_verification'
            ORDER BY u.created_at ASC
        ");
        $pending_drivers = $stmt->fetchAll();
        
        // Fetch pending payouts
        $stmt = $pdo->query("
            SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as driver_name 
            FROM payouts p 
            JOIN users u ON p.driver_id = u.id 
            WHERE p.status = 'pending' 
              ORDER BY p.created_at ASC
        ");
        $pending_payouts = $stmt->fetchAll();

        // Fetch ALL System Trips (for Admin Monitoring Panel)
        $stmt = $pdo->query("
            SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as driver_name,
                   (SELECT COUNT(*) FROM bookings WHERE trip_id = t.id) as booking_count 
            FROM trips t
            JOIN users u ON t.driver_id = u.id 
            ORDER BY t.departure_time DESC
        ");
        $all_trips = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        $error = 'Failed to load dashboard data: ' . $e->getMessage();
    }
}

// Flash messages
if (isset($_SESSION['settings_success'])) {
    $success = $_SESSION['settings_success'];
    unset($_SESSION['settings_success']);
}
if (isset($_SESSION['payout_success'])) {
    $success = $_SESSION['payout_success'];
    unset($_SESSION['payout_success']);
}
if (isset($_SESSION['vetting_success'])) {
    $success = $_SESSION['vetting_success'];
    unset($_SESSION['vetting_success']);
}
if (isset($_SESSION['admin_success'])) {
    $success = $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold text-white"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>Admin Control Panel</h2>
            <p class="text-muted font-monospace">SakayPH Administration Console & Gateway Settlement Center.</p>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 rounded-3 border-0 small mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success py-2 rounded-3 border-0 small mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Stats Row -->
    <div class="row g-3 mb-5">
        <div class="col-md-3 col-sm-6">
            <div class="card card-custom p-3 shadow-sm border-left" style="border-left: 4px solid var(--accent-success);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.7rem;">Admin Commissions</small>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?php echo format_peso($stats['earnings']); ?></h3>
                    </div>
                    <i class="bi bi-cash-coin text-success display-6"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card card-custom p-3 shadow-sm border-left" style="border-left: 4px solid var(--primary);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.7rem;">Total Drivers</small>
                        <h3 class="fw-bold text-white mb-0 mt-1"><?php echo $stats['drivers']; ?></h3>
                    </div>
                    <i class="bi bi-car-front-fill text-primary display-6"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card card-custom p-3 shadow-sm border-left" style="border-left: 4px solid #06b6d4;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.7rem;">Total Passengers</small>
                        <h3 class="fw-bold text-white mb-0 mt-1"><?php echo $stats['clients']; ?></h3>
                    </div>
                    <i class="bi bi-people-fill text-info display-6"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card card-custom p-3 shadow-sm border-left" style="border-left: 4px solid #f59e0b;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.7rem;">Active Listings</small>
                        <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo $stats['active_trips']; ?></h3>
                    </div>
                    <i class="bi bi-calendar2-week-fill text-warning display-6"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Main Console Operations -->
        <div class="col-lg-8">
            
            <!-- Driver vetting queue -->
            <div class="card card-custom p-4 shadow-sm mb-4">
                <h5 class="fw-bold text-white mb-3"><i class="bi bi-person-check-fill text-primary me-2"></i>Driver Vetting Queue (<?php echo count($pending_drivers); ?>)</h5>
                
                <?php if (empty($pending_drivers)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-check2-all display-6 mb-2"></i>
                        <p class="small mb-0">Vetting queue is currently empty. No new drivers to verify.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle border border-secondary" style="border-radius:12px; overflow:hidden;">
                            <thead>
                                <tr class="text-muted small">
                                    <th class="ps-3">Driver Info</th>
                                    <th>Vehicle Details</th>
                                    <th>Document IDs</th>
                                    <th class="text-center pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_drivers as $d): 
                                    // ----------------------------------------------------
                                    // AUTOMATED PRE-VERIFICATION CHECKS (Admin Helper)
                                    // ----------------------------------------------------
                                    $plate = preg_replace('/[^A-Z0-9]/i', '', $d['plate_number']);
                                    $license_num_clean = preg_replace('/[^A-Z0-9]/i', '', $d['license_number']);
                                    
                                    $ocr_or = preg_replace('/[^A-Z0-9]/i', '', $d['ocr_or_text']);
                                    $ocr_cr = preg_replace('/[^A-Z0-9]/i', '', $d['ocr_cr_text']);
                                    $ocr_license = preg_replace('/[^A-Z0-9]/i', '', $d['ocr_license_text']);
                                    
                                    // 1. Check OR/CR Plate Match
                                    $or_cr_matched = (!empty($plate) && (strpos(strtoupper($ocr_or), strtoupper($plate)) !== false || strpos(strtoupper($ocr_cr), strtoupper($plate)) !== false));
                                    
                                    // 2. Check License Number Match
                                    $license_matched = (!empty($license_num_clean) && strpos(strtoupper($ocr_license), strtoupper($license_num_clean)) !== false);
                                    
                                    // 3. Check Brand/Model Match in CR
                                    $brand_clean = preg_replace('/[^A-Z0-9]/i', '', $d['brand']);
                                    $model_clean = preg_replace('/[^A-Z0-9]/i', '', $d['model']);
                                    $vehicle_matched = false;
                                    if (!empty($brand_clean) && (strpos(strtoupper($ocr_cr), strtoupper($brand_clean)) !== false || strpos(strtoupper($ocr_or), strtoupper($brand_clean)) !== false)) {
                                        $vehicle_matched = true;
                                    }
                                    if (!empty($model_clean) && (strpos(strtoupper($ocr_cr), strtoupper($model_clean)) !== false || strpos(strtoupper($ocr_or), strtoupper($model_clean)) !== false)) {
                                        $vehicle_matched = true;
                                    }
                                    
                                    // 4. Check License Validity
                                    $license_valid = (strtotime($d['license_expiration']) > time());
                                ?>
                                    <tr class="small text-white">
                                        <td class="ps-3 py-3">
                                            <strong class="d-block text-white" style="font-size:0.95rem;"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></strong>
                                            <span class="text-muted d-block" style="font-size:0.8rem;"><i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($d['phone']); ?></span>
                                            <span class="text-muted d-block" style="font-size:0.8rem;"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($d['email']); ?></span>
                                            
                                            <!-- Automated Pre-check Badges -->
                                            <div class="mt-2 d-flex flex-column gap-1">
                                                <?php if ($license_valid): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-check-circle-fill me-1"></i>License Valid</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-x-circle-fill me-1"></i>License Expired</span>
                                                <?php endif; ?>
                                                
                                                <?php if ($license_matched): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-check-circle-fill me-1"></i>ID # Matched</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>ID # Match Failed</span>
                                                <?php endif; ?>

                                                <?php if ($or_cr_matched): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-check-circle-fill me-1"></i>Plate Matched</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Plate Match Failed</span>
                                                <?php endif; ?>
                                                
                                                <?php if ($vehicle_matched): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-check-circle-fill me-1"></i>Car Model Matched</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 w-fit" style="font-size:0.7rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Car Model Failed</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span><?php echo htmlspecialchars($d['brand'] . ' ' . $d['model']); ?></span>
                                            <span class="badge bg-secondary text-white font-monospace d-block mt-1 w-fit"><?php echo htmlspecialchars($d['plate_number']); ?></span>
                                        </td>
                                        <td>
                                            <span class="d-block">License: <strong><?php echo htmlspecialchars($d['license_number']); ?></strong></span>
                                            <span class="d-block text-muted" style="font-size:0.75rem;">Exp: <?php echo date('M d, Y', strtotime($d['license_expiration'])); ?></span>
                                            
                                            <!-- OCR details modal trigger button -->
                                            <button type="button" class="btn btn-outline-info btn-sm py-0.5 px-2 mt-2" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#ocrModal<?php echo $d['id']; ?>">
                                                <i class="bi bi-eye me-1"></i>Review Scans & OCR
                                            </button>
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex gap-2 justify-content-center">
                                                <a href="verify_driver.php?driver_id=<?php echo $d['id']; ?>&action=approve" class="btn btn-gradient-success btn-sm px-3 py-2">
                                                    <i class="bi bi-check-circle"></i> Approve
                                                </a>
                                                <button type="button" class="btn btn-outline-danger btn-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $d['id']; ?>">
                                                    <i class="bi bi-x-circle"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
</div>

<!-- Modals rendered outside of table to prevent HTML layout thrashing (blinking on hover) -->
<?php foreach ($pending_drivers as $d): ?>
    <!-- Reject Remarks Modal -->
    <div class="modal fade" id="rejectModal<?php echo $d['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white"><i class="bi bi-x-circle text-danger me-2"></i>Decline Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="verify_driver.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="driver_id" value="<?php echo $d['id']; ?>">
                        <input type="hidden" name="action" value="reject">
                        <p class="text-muted small mb-3">Please specify the reason for declining <?php echo htmlspecialchars($d['first_name']); ?>'s application. This will be shown to them so they can re-upload the correct documents.</p>
                        <div class="mb-3">
                            <label for="remarks<?php echo $d['id']; ?>" class="form-label text-white small">Admin Remarks (Reason)</label>
                            <textarea name="remarks" id="remarks<?php echo $d['id']; ?>" class="form-control form-control-custom text-white" rows="3" placeholder="e.g. License photo is too blurry. Please take a clearer picture." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm px-4">Submit Decline</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Vetting OCR Modal Dialog -->
    <div class="modal fade" id="ocrModal<?php echo $d['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content text-white" style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius:16px;">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-search me-2 text-info"></i>OCR Document Verification Check</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <!-- Column 1: Driver's License Info -->
                        <div class="col-md-4 border-end border-secondary">
                            <h6 class="fw-bold text-info border-bottom border-secondary pb-1"><i class="bi bi-card-heading me-1"></i>Driver's License</h6>
                            
                            <div class="mb-2">
                                <strong class="text-white-50 small">License Number:</strong>
                                <span class="text-white fw-bold ms-1"><?php echo htmlspecialchars($d['license_number']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Expiration Date:</strong>
                                <span class="text-white fw-bold ms-1"><?php echo htmlspecialchars($d['license_expiration']); ?></span>
                            </div>
                            <div class="mb-3">
                                <strong class="text-white-50 small">Restriction Code:</strong>
                                <span class="badge bg-info text-dark ms-1"><?php echo htmlspecialchars($d['restriction_code']); ?></span>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6 text-center">
                                    <span class="text-muted d-block small mb-1">FRONT</span>
                                    <img src="<?php echo BASE_URL . $d['license_photo']; ?>" class="img-fluid rounded border border-secondary" style="max-height: 120px;" alt="License Front">
                                </div>
                                <div class="col-6 text-center">
                                    <span class="text-muted d-block small mb-1">BACK</span>
                                    <?php if (!empty($d['license_photo_back'])): ?>
                                        <img src="<?php echo BASE_URL . $d['license_photo_back']; ?>" class="img-fluid rounded border border-secondary" style="max-height: 120px;" alt="License Back">
                                    <?php else: ?>
                                        <div class="text-danger small py-4 border border-secondary rounded">No Back Photo</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bg-dark bg-opacity-50 p-2 rounded border border-secondary font-monospace" style="font-size:0.7rem; max-height:100px; overflow-y:auto;">
                                <strong class="text-muted d-block small">OCR Front Text:</strong>
                                <?php echo !empty($d['ocr_license_text']) ? nl2br(htmlspecialchars($d['ocr_license_text'])) : 'No data'; ?>
                            </div>
                        </div>
                        
                        <!-- Column 2: Vehicle Information -->
                        <div class="col-md-4 border-end border-secondary">
                            <h6 class="fw-bold text-warning border-bottom border-secondary pb-1"><i class="bi bi-car-front-fill me-1"></i>Vehicle Information</h6>
                            
                            <div class="mb-2 mt-2">
                                <strong class="text-white-50 small">Plate Number:</strong>
                                <span class="text-white fw-bold ms-1"><?php echo htmlspecialchars($d['plate_number']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Vehicle Make (Brand):</strong>
                                <span class="text-white ms-1"><?php echo htmlspecialchars($d['brand']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Vehicle Model:</strong>
                                <span class="text-white ms-1"><?php echo htmlspecialchars($d['model']); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Vehicle Color:</strong>
                                <span class="text-white ms-1"><?php echo htmlspecialchars($d['color'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Year Model:</strong>
                                <span class="text-white ms-1"><?php echo htmlspecialchars($d['year_model'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mb-2">
                                <strong class="text-white-50 small">Capacity:</strong>
                                <span class="text-white ms-1"><?php echo htmlspecialchars($d['capacity']); ?> Seater <span class="text-muted" style="font-size:0.75rem;">(incl. driver)</span></span>
                            </div>
                        </div>

                        <!-- Column 3: Vehicle Documents -->
                        <div class="col-md-4">
                            <h6 class="fw-bold text-success border-bottom border-secondary pb-1"><i class="bi bi-file-earmark-check-fill me-1"></i>LTO OR & CR</h6>
                            
                            <div class="row g-2 mb-3 mt-1">
                                <div class="col-6 text-center">
                                    <span class="text-muted d-block small mb-1">CR DOCUMENT</span>
                                    <img src="<?php echo BASE_URL . $d['certificate_registration_photo']; ?>" class="img-fluid rounded border border-secondary" style="max-height: 120px;" alt="LTO CR Photo">
                                </div>
                                <div class="col-6 text-center">
                                    <span class="text-muted d-block small mb-1">OR RECEIPT</span>
                                    <img src="<?php echo BASE_URL . $d['official_receipt_photo']; ?>" class="img-fluid rounded border border-secondary" style="max-height: 120px;" alt="LTO OR Photo">
                                </div>
                            </div>
                            <div class="bg-dark bg-opacity-50 p-2 rounded border border-secondary font-monospace mb-2" style="font-size:0.7rem; max-height:80px; overflow-y:auto;">
                                <strong class="text-muted d-block small">CR OCR Text:</strong>
                                <?php echo !empty($d['ocr_cr_text']) ? nl2br(htmlspecialchars($d['ocr_cr_text'])) : 'No data'; ?>
                            </div>
                            <div class="bg-dark bg-opacity-50 p-2 rounded border border-secondary font-monospace" style="font-size:0.7rem; max-height:80px; overflow-y:auto;">
                                <strong class="text-muted d-block small">OR OCR Text:</strong>
                                <?php echo !empty($d['ocr_or_text']) ? nl2br(htmlspecialchars($d['ocr_or_text'])) : 'No data'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close Review</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

            <!-- Pending payouts -->
            <div class="card card-custom p-4 shadow-sm">
                <h5 class="fw-bold text-white mb-3"><i class="bi bi-cash-stack text-primary me-2"></i>Driver Payout Requests (<?php echo count($pending_payouts); ?>)</h5>
                
                <?php if (empty($pending_payouts)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-cash display-6 mb-2"></i>
                        <p class="small mb-0">No pending payout requests.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle border border-secondary" style="border-radius:12px; overflow:hidden;">
                            <thead>
                                <tr class="text-muted small">
                                    <th class="ps-3">Driver Name</th>
                                    <th>GCash Number</th>
                                    <th>GCash Account Name</th>
                                    <th>Amount</th>
                                    <th class="text-center pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_payouts as $p): ?>
                                    <tr class="small text-white">
                                        <td class="ps-3 py-3"><strong><?php echo htmlspecialchars($p['driver_name']); ?></strong></td>
                                        <td class="font-monospace text-info"><?php echo htmlspecialchars($p['gcash_number']); ?></td>
                                        <td class="text-uppercase"><?php echo htmlspecialchars($p['gcash_name']); ?></td>
                                        <td class="fw-bold text-success" style="font-size:0.95rem;"><?php echo format_peso($p['amount']); ?></td>
                                        <td class="text-center pe-3">
                                            <a href="dashboard.php?action=complete_payout&payout_id=<?php echo $p['id']; ?>" class="btn btn-gradient-success btn-sm px-3 py-2" onclick="return confirm('Please confirm you have manually transferred the GCash amount before marking it paid!');">
                                                <i class="bi bi-send-check"></i> Mark as Paid
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            
            <!-- Trips Monitoring panel -->
            <div class="card card-custom p-4 shadow-sm mt-4">
                <h5 class="fw-bold text-white mb-3"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Global Charter Trips Monitoring (<?php echo count($all_trips); ?>)</h5>
                
                <?php if (empty($all_trips)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-map display-6 mb-2"></i>
                        <p class="small mb-0">No trips posted in the system yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle border border-secondary" style="border-radius:12px; overflow:hidden;">
                            <thead>
                                <tr class="text-muted small">
                                    <th class="ps-3">Driver</th>
                                    <th>Route</th>
                                    <th>Schedule</th>
                                    <th>Fare</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center pe-3" style="width: 200px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_trips as $t): ?>
                                    <tr class="small text-white">
                                        <td class="ps-3 py-3"><strong><?php echo htmlspecialchars($t['driver_name']); ?></strong></td>
                                        <td>
                                            <span class="d-block font-monospace" style="font-size:0.75rem;"><i class="bi bi-geo-alt text-danger"></i> <?php echo htmlspecialchars($t['origin']); ?></span>
                                            <span class="d-block font-monospace" style="font-size:0.75rem;"><i class="bi bi-pin-map text-success"></i> <?php echo htmlspecialchars($t['destination']); ?></span>
                                        </td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($t['departure_time'])); ?></td>
                                        <td class="fw-bold text-success"><?php echo format_peso($t['price_total']); ?></td>
                                        <td class="text-center">
                                            <?php if ($t['status'] === 'active'): ?>
                                                <span class="badge bg-primary text-white rounded-pill px-2 py-1">Active (No Bookings)</span>
                                            <?php elseif ($t['status'] === 'booked'): ?>
                                                <span class="badge-verified px-2 py-1">Booked</span>
                                            <?php elseif ($t['status'] === 'in_progress'): ?>
                                                <span class="badge bg-warning text-dark rounded-pill px-2 py-1">In Progress</span>
                                            <?php elseif ($t['status'] === 'completed'): ?>
                                                <span class="badge bg-secondary text-white rounded-pill px-2 py-1">Completed</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger text-white rounded-pill px-2 py-1">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center pe-3">
                                            <?php if (in_array($t['status'], ['active', 'booked', 'in_progress'])): ?>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <?php if ($t['status'] === 'in_progress' || $t['status'] === 'booked'): ?>
                                                        <a href="dashboard.php?action=complete_trip&trip_id=<?php echo $t['id']; ?>" class="btn btn-success btn-xs px-2 py-1 font-monospace" style="font-size:0.7rem;" onclick="return confirm('Complete this trip manually? This credits driver wallet.');">
                                                            Complete
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="dashboard.php?action=cancel_trip&trip_id=<?php echo $t['id']; ?>" class="btn btn-danger btn-xs px-2 py-1 font-monospace" style="font-size:0.7rem;" onclick="return confirm('Cancel this trip and any confirmed bookings? This is irreversible.');">
                                                        Cancel
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>

        <!-- Sidebar (System Settings Controller) -->
        <div class="col-lg-4">
            <div class="card card-custom p-4 shadow-sm border border-secondary">
                <h5 class="fw-bold text-white mb-3 border-bottom border-secondary pb-2"><i class="bi bi-gear-fill text-primary me-2"></i>Dynamic Settings</h5>
                <p class="text-muted small">These values control business parameters across the platform in real-time.</p>
                
                <form action="dashboard.php" method="POST">
                    <div class="mb-3">
                        <label for="commission_rate" class="form-label text-muted small">Platform Commission Rate (%)</label>
                        <div class="input-group">
                            <input type="number" name="commission_rate" id="commission_rate" class="form-control form-control-custom text-white fw-bold" min="0" max="100" value="<?php echo COMMISSION_RATE; ?>" required>
                            <span class="input-group-text bg-transparent border-secondary text-muted">%</span>
                        </div>
                        <div class="form-text text-muted small mt-1">Percentage deducted from client's payment.</div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="min_payout_amount" class="form-label text-muted small">Minimum Payout Amount (₱)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-secondary text-muted">₱</span>
                            <input type="number" name="min_payout_amount" id="min_payout_amount" class="form-control form-control-custom text-white fw-bold" min="0" value="<?php echo MIN_PAYOUT_AMOUNT; ?>" required>
                        </div>
                        <div class="form-text text-muted small mt-1">Minimum wallet balance for drivers to cash-out.</div>
                    </div>
                    
                    <button type="submit" name="update_settings" class="btn btn-gradient-primary w-100 py-2.5">
                        <i class="bi bi-save me-2"></i>Save Configurations
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
