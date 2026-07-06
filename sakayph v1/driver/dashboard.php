<?php
require_once __DIR__ . '/../config.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
$driver_status = '';

// Flash messages
$trip_success = '';
$trip_error = '';
if (isset($_SESSION['trip_success'])) {
    $trip_success = $_SESSION['trip_success'];
    unset($_SESSION['trip_success']);
}
if (isset($_SESSION['trip_error'])) {
    $trip_error = $_SESSION['trip_error'];
    unset($_SESSION['trip_error']);
}
if (isset($_SESSION['payout_success'])) {
    $trip_success = $_SESSION['payout_success'];
    unset($_SESSION['payout_success']);
}
$wallet_balance = 0.00;
$trips = [];
$bookings = [];

if ($pdo) {
    try {
        // Fetch current driver status and wallet balance
        $stmt = $pdo->prepare("SELECT status, wallet_balance, admin_remarks FROM users WHERE id = ?");
        $stmt->execute([$driver_id]);
        $driver = $stmt->fetch();
        $admin_remarks = '';
        if ($driver) {
            $driver_status = $driver['status'];
            $wallet_balance = floatval($driver['wallet_balance']);
            $admin_remarks = $driver['admin_remarks'];
        }
        
        // Fetch average rating for this driver
        $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(id) as total_reviews FROM reviews WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        $rating_data = $stmt->fetch();
        $avg_rating = $rating_data['avg_rating'] ? floatval($rating_data['avg_rating']) : 0.0;
        $total_reviews = intval($rating_data['total_reviews']);

        // Fetch driver reviews list
        $stmt = $pdo->prepare("
            SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) as client_name 
            FROM reviews r 
            JOIN users u ON r.client_id = u.id
            WHERE r.driver_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$driver_id]);
        $driver_reviews = $stmt->fetchAll();
        
        // Fetch posted trips
        $stmt = $pdo->prepare("SELECT * FROM trips WHERE driver_id = ? ORDER BY departure_time DESC");
        $stmt->execute([$driver_id]);
        $trips = $stmt->fetchAll();
        
        // Fetch bookings for this driver's trips
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.departure_time, t.price_total,
                   CONCAT(u.first_name, ' ', u.last_name) as client_name, u.phone as client_phone 
            FROM bookings b 
            JOIN trips t ON b.trip_id = t.id 
            JOIN users u ON b.client_id = u.id 
            WHERE t.driver_id = ? 
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$driver_id]);
        $bookings = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        // Table errors
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <!-- Flash Messages -->
    <?php if (!empty($trip_success)): ?>
        <div class="alert alert-success border-0 py-2 px-4 mb-3 rounded-3 small shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($trip_success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($trip_error)): ?>
        <div class="alert alert-danger border-0 py-2 px-4 mb-3 rounded-3 small shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($trip_error); ?>
        </div>
    <?php endif; ?>

    <!-- Verification Alert Banner / Restricted Dashboard -->
    <?php if ($driver_status === 'pending_verification'): ?>
        <div class="alert alert-warning border-0 p-5 mb-4 rounded-4 shadow-sm text-center" role="alert" style="background-color: rgba(245, 158, 11, 0.15);">
            <i class="bi bi-clock-history text-warning" style="font-size: 4rem;"></i>
            <h3 class="alert-heading fw-bold text-white mt-3 mb-2">Account Verification Pending</h3>
            <p class="mb-0 text-muted mx-auto" style="max-width: 600px;">Welcome to SakayPH! Our administrators are currently reviewing your Driver's License and Vehicle OR/CR documents. You will be able to access the full dashboard as soon as your profile is verified. This usually takes less than 24 hours.</p>
        </div>
    <?php elseif ($driver_status === 'action_required' || $driver_status === 'rejected'): ?>
        <div class="alert alert-danger border-0 p-5 mb-4 rounded-4 shadow-sm text-center" role="alert" style="background-color: rgba(239, 68, 68, 0.15);">
            <i class="bi bi-x-octagon-fill text-danger" style="font-size: 4rem;"></i>
            <h3 class="alert-heading fw-bold text-white mt-3 mb-2">Action Required: Documents Declined</h3>
            <p class="mb-4 text-muted mx-auto" style="max-width: 600px;">Your documents were reviewed but unfortunately did not pass our validation process.</p>
            
            <div class="bg-dark bg-opacity-50 p-4 rounded-3 border border-danger border-opacity-25 mx-auto mb-4" style="max-width: 600px; text-align: left;">
                <h6 class="fw-bold text-danger mb-2"><i class="bi bi-chat-left-text me-2"></i>Admin Remarks:</h6>
                <p class="text-white-50 mb-0 italic">"<?php echo htmlspecialchars($admin_remarks ?: 'No remarks provided. Please re-upload clearer copies of your documents.'); ?>"</p>
            </div>
            
            <a href="reupload_docs.php" class="btn btn-danger px-4 py-2 fw-bold"><i class="bi bi-upload me-2"></i>Re-upload Documents Now</a>
        </div>
    <?php endif; ?>

    <!-- Show main dashboard ONLY if verified -->
    <?php if ($driver_status === 'verified'): ?>

    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="fw-bold text-white"><i class="bi bi-speedometer2 me-2 text-primary"></i>Driver Dashboard</h2>
            <p class="text-muted">Monitor your charter earnings, post vehicle availability, and view passenger details.</p>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-md-end mt-3 mt-md-0">
            <a href="post_trip.php" class="btn btn-gradient-primary w-100 w-md-auto py-2.5 <?php echo ($driver_status !== 'verified') ? 'disabled' : ''; ?>">
                <i class="bi bi-plus-circle me-2"></i>Post New Charter Trip
            </a>
        </div>
    </div>

    <!-- Stats & Wallet Section -->
    <div class="row mb-5 g-4">
        <div class="col-lg-6">
            <div class="card card-custom p-4 shadow h-100 d-flex flex-column justify-content-between" style="border-left: 5px solid var(--primary);">
                <div>
                    <span class="text-muted uppercase fw-bold small">Driver Wallet Balance</span>
                    <h1 class="display-4 fw-bold text-success mt-1 mb-2"><?php echo format_peso($wallet_balance); ?></h1>
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>Minimum payout amount is <strong><?php echo format_peso(MIN_PAYOUT_AMOUNT); ?></strong>.</p>
                </div>
                <div class="mt-4">
                    <?php if ($wallet_balance >= MIN_PAYOUT_AMOUNT && $driver_status === 'verified'): ?>
                        <a href="request_payout.php" class="btn btn-gradient-success w-100 py-3">
                            <i class="bi bi-cash-stack me-2"></i>Request GCash Payout
                        </a>
                    <?php else: ?>
                        <button class="btn btn-outline-custom w-100 py-3" disabled>
                            <i class="bi bi-cash-stack me-2"></i>Request GCash Payout (Locked)
                        </button>
                        <?php if ($driver_status === 'verified'): ?>
                            <small class="text-muted text-center d-block mt-2">Earn <strong><?php echo format_peso(MIN_PAYOUT_AMOUNT - $wallet_balance); ?></strong> more to unlock cash-out.</small>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="payout_history.php" class="text-muted small text-decoration-none"><i class="bi bi-clock-history me-1"></i>View Payout History</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="row g-3 h-100">
                <!-- Driver Rating Card -->
                <div class="col-sm-6">
                    <div class="card card-custom p-3 shadow-sm h-100 d-flex flex-column justify-content-center" style="border-left: 3px solid var(--accent-success);">
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Average Rating</small>
                        <h3 class="fw-bold text-warning mb-0 mt-1">
                            <i class="bi bi-star-fill me-1"></i>
                            <?php echo number_format($avg_rating, 1); ?> <span class="text-muted fs-6">/ 5.0</span>
                        </h3>
                        <small class="text-muted mt-1">(<?php echo $total_reviews; ?> Passenger reviews)</small>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="card card-custom p-3 shadow-sm h-100 d-flex flex-column justify-content-center">
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Total Charter Trips</small>
                        <h3 class="fw-bold text-white mb-0 mt-1"><?php echo count($trips); ?></h3>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="card card-custom p-3 shadow-sm h-100 d-flex flex-column justify-content-center">
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Active Charters</small>
                        <h3 class="fw-bold text-info mb-0 mt-1">
                            <?php 
                            echo count(array_filter($trips, function($t) {
                                // Filter active trips that have not yet expired
                                return $t['status'] === 'active' && strtotime($t['departure_time']) > time();
                            }));
                            ?>
                        </h3>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="card card-custom p-3 shadow-sm h-100 d-flex flex-column justify-content-center">
                        <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Completed Trips</small>
                        <h3 class="fw-bold text-white mb-0 mt-1">
                            <?php 
                            $completed_trips = array_filter($trips, function($t) {
                                // Include completed trips and also active trips that have expired
                                return $t['status'] === 'completed' || ($t['status'] === 'active' && strtotime($t['departure_time']) <= time());
                            });
                            echo count($completed_trips);
                            ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active bookings and Trip requests -->
    <div class="row">
        <!-- Passenger list -->
        <div class="col-lg-7 mb-4">
            <h4 class="fw-bold text-white mb-3"><i class="bi bi-people-fill me-2 text-primary"></i>My Passenger Bookings</h4>
            
            <?php if (empty($bookings)): ?>
                <div class="card card-custom p-4 text-center">
                    <i class="bi bi-person-x text-muted display-6 mb-2"></i>
                    <h6 class="text-white">No passenger bookings yet.</h6>
                    <p class="text-muted small mb-0">Post your vehicle availability on the map to get clients.</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="card card-custom p-3 shadow-sm">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Booking #<?php echo $booking['id']; ?></span>
                                <?php if ($booking['status'] === 'confirmed'): ?>
                                    <span class="badge-verified"><i class="bi bi-shield-check me-1"></i>Fare Received (Escrow)</span>
                                <?php elseif ($booking['status'] === 'completed'): ?>
                                    <span class="badge-verified"><i class="bi bi-check-all me-1"></i>Completed / Paid</span>
                                <?php else: ?>
                                    <span class="badge-pending"><i class="bi bi-hourglass-split me-1"></i>Unpaid / Pending</span>
                                <?php endif; ?>
                            </div>
                            
                            <h6 class="fw-bold text-white mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($booking['origin']); ?> to <?php echo htmlspecialchars($booking['destination']); ?></h6>
                            <p class="text-muted small mb-2"><i class="bi bi-clock me-1"></i>Scheduled: <?php echo date('M d, Y h:i A', strtotime($booking['departure_time'])); ?></p>
                            
                            <?php if ($booking['status'] === 'confirmed'): ?>
                                <div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 p-2 mt-2">
                                    <small class="text-info d-block fw-bold mb-1"><i class="bi bi-chat-fill me-1"></i>Trip Messenger</small>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <span class="text-white small fw-bold d-block"><?php echo htmlspecialchars($booking['client_name']); ?></span>
                                            <span class="text-muted small" style="font-size:0.75rem;">Passenger</span>
                                        </div>
                                        <div class="w-100 ms-3">
                                            <!-- In-App Trip Chat Button -->
                                            <a href="<?php echo BASE_URL; ?>trip_chat.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm w-100 py-2" title="Trip Chat">
                                                <i class="bi bi-chat-text-fill me-2"></i>Open Trip Chat
                                            </a>
                                        </div>
                                    </div>
                                    <!-- Trip Action Buttons -->
                                    <?php
                                        $stmt2 = $pdo->prepare("SELECT status FROM trips WHERE id = ?");
                                        $stmt2->execute([$booking['trip_id']]);
                                        $trip_status = $stmt2->fetchColumn();
                                    ?>
                                    <?php if ($trip_status === 'booked'): ?>
                                        <div class="d-flex gap-2 mt-1">
                                            <a href="trip_action.php?trip_id=<?php echo $booking['trip_id']; ?>&action=start" 
                                               class="btn btn-gradient-primary w-100 py-2"
                                               onclick="return confirm('Start this trip? Make sure the passenger is already in the vehicle.')">
                                                <i class="bi bi-play-circle-fill me-2"></i>Start Trip
                                            </a>
                                            <button type="button" class="btn btn-outline-danger py-2 px-3 rounded-3" data-bs-toggle="modal" data-bs-target="#driverCancelModal<?php echo $booking['id']; ?>" title="Cancel Trip">
                                                <i class="bi bi-x-circle-fill"></i>
                                            </button>
                                        </div>
                                    <?php elseif ($trip_status === 'in_progress'): ?>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge bg-warning text-dark rounded-pill px-3 py-2 w-100 text-center">
                                                <i class="bi bi-geo-alt-fill me-1"></i>Trip In Progress...
                                            </span>
                                        </div>
                                        <button type="button" class="btn btn-gradient-success w-100 py-2" data-bs-toggle="modal" data-bs-target="#completeModal<?php echo $booking['trip_id']; ?>">
                                            <i class="bi bi-check-circle-fill me-2"></i>Complete Trip & Receive Earnings
                                        </button>


                                    <?php elseif ($trip_status === 'completed'): ?>
                                        <div class="text-center mt-1">
                                            <?php if ($booking['status'] === 'confirmed'): ?>
                                                <span class="badge bg-warning text-dark w-100 d-block py-2"><i class="bi bi-hourglass-split me-1"></i>Waiting for Passenger to Confirm Arrival</span>
                                            <?php else: ?>
                                                <span class="badge-verified w-100 d-block py-2"><i class="bi bi-patch-check-fill me-1"></i>Trip Completed — Earnings Released</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Posted Trips history -->
        <div class="col-lg-5 mb-4">
            <h4 class="fw-bold text-white mb-3"><i class="bi bi-clock-history me-2 text-primary"></i>Posted Charters</h4>
            
            <?php if (empty($trips)): ?>
                <div class="card card-custom p-4 text-center">
                    <i class="bi bi-calendar-x text-muted display-6 mb-2"></i>
                    <h6 class="text-white">You haven't posted any trips.</h6>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3" style="max-height: 400px; overflow-y: auto; padding-right: 5px;">
                    <?php foreach ($trips as $t): ?>
                        <div class="card card-custom p-3 shadow-sm" style="background-color: rgba(30, 41, 59, 0.5);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-success fw-bold small"><?php echo format_peso($t['price_total']); ?></span>
                                <?php 
                                $is_expired = ($t['status'] === 'active' && strtotime($t['departure_time']) <= time());
                                if ($is_expired): ?>
                                    <span class="badge bg-secondary text-white rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">Expired</span>
                                <?php elseif ($t['status'] === 'active'): ?>
                                    <span class="badge bg-success text-white rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">Active</span>
                                <?php elseif ($t['status'] === 'booked'): ?>
                                    <span class="badge bg-warning text-dark rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">Booked</span>
                                <?php elseif ($t['status'] === 'in_progress'): ?>
                                    <span class="badge bg-info text-dark rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">In Progress</span>
                                <?php elseif ($t['status'] === 'completed'): ?>
                                    <span class="badge bg-primary text-white rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">Completed</span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white rounded-pill small px-2 py-0.5" style="font-size:0.7rem;">Cancelled</span>
                                <?php endif; ?>
                            </div>
                            <h6 class="text-white small fw-bold mb-1"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($t['origin']); ?></h6>
                            <h6 class="text-white small fw-bold mb-2"><i class="bi bi-pin-map me-1"></i><?php echo htmlspecialchars($t['destination']); ?></h6>
                            <small class="text-muted"><i class="bi bi-calendar-event me-1"></i><?php echo date('M d, h:i A', strtotime($t['departure_time'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </div>

    <!-- Passenger Reviews Feed Section -->
    <div class="row mt-4">
        <div class="col-12 mb-4">
            <div class="card card-custom p-4 shadow">
                <h4 class="fw-bold text-white mb-3"><i class="bi bi-star-fill text-warning me-2"></i>Passenger Ratings & Reviews Feed</h4>
                
                <?php if (empty($driver_reviews)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-chat-left-heart fs-2 mb-2"></i>
                        <p class="small mb-0">No reviews received yet. Complete trips to receive feedback from passengers.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($driver_reviews as $rev): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card bg-dark bg-opacity-50 border border-secondary border-opacity-50 p-3 rounded-3 h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-white small fw-bold"><i class="bi bi-person-fill text-primary me-1"></i><?php echo htmlspecialchars($rev['client_name']); ?></span>
                                        <small class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></small>
                                    </div>
                                    <div class="text-warning small mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi <?php echo ($i <= $rev['rating']) ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="text-muted small mb-0 italic" style="font-size:0.85rem;">
                                        "<?php echo htmlspecialchars($rev['comment'] ?: 'No comment left.'); ?>"
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php endif; // End of $driver_status === 'verified' check ?>
</div>

<!-- Render Modals Outside Transform Containers -->
<?php if (!empty($bookings) && $driver_status === 'verified'): ?>
    <?php foreach ($bookings as $booking): ?>
        <?php
        $stmt2 = $pdo->prepare("SELECT status FROM trips WHERE id = ?");
        $stmt2->execute([$booking['trip_id']]);
        $trip_status = $stmt2->fetchColumn();
        if ($trip_status === 'in_progress'):
        ?>
        <!-- PIN Code Modal -->
        <div class="modal fade" id="completeModal<?php echo $booking['trip_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title text-white"><i class="bi bi-shield-lock-fill text-warning me-2"></i>Enter Completion PIN</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="trip_action.php" method="POST">
                        <div class="modal-body text-start">
                            <input type="hidden" name="action" value="complete">
                            <input type="hidden" name="trip_id" value="<?php echo $booking['trip_id']; ?>">
                            <p class="text-muted small">Ask the passenger for the 4-digit <strong>Completion PIN</strong> displayed on their app to verify the trip and release your earnings.</p>
                            <div class="mb-3 text-center">
                                <input type="text" name="completion_pin" class="form-control form-control-lg text-center bg-dark text-white border-primary fw-bold" style="letter-spacing: 15px; font-size: 2rem;" maxlength="4" placeholder="----" required autocomplete="off">
                            </div>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success fw-bold px-4">Verify PIN & Complete Trip</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($booking['status'] === 'confirmed' && $trip_status === 'booked'): ?>
        <!-- Driver Cancel Modal -->
        <div class="modal fade" id="driverCancelModal<?php echo $booking['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Cancel Trip?</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="cancel_trip.php" method="POST">
                        <div class="modal-body text-start">
                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                            <input type="hidden" name="trip_id" value="<?php echo $booking['trip_id']; ?>">
                            <p class="text-white mb-2">Are you sure you want to cancel this trip?</p>
                            
                            <div class="bg-secondary bg-opacity-25 p-3 rounded-3 mt-3 border border-secondary">
                                <h6 class="text-warning fw-bold small"><i class="bi bi-info-circle-fill me-1"></i>Driver Cancellation Policy:</h6>
                                <p class="small text-muted mb-0">Since the passenger has already paid for this booking, cancelling will **automatically refund 100% of the passenger's payment** back to their wallet. You will not receive any earnings for this trip.</p>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Trip</button>
                            <button type="submit" class="btn btn-danger fw-bold px-4">Yes, Cancel Trip</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
