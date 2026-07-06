<?php
require_once __DIR__ . '/../config.php';
require_login(['client']);

$client_id = $_SESSION['user_id'];
$bookings = [];

if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.departure_time, t.price_total, t.status AS trip_status,
                   CONCAT(u.first_name, ' ', u.last_name) as driver_name, u.phone as driver_phone, 
                   v.brand, v.model, v.plate_number,
                   r.rating AS review_rating, r.comment AS review_comment
            FROM bookings b 
            JOIN trips t ON b.trip_id = t.id 
            JOIN users u ON t.driver_id = u.id 
            JOIN vehicles v ON u.id = v.driver_id 
            LEFT JOIN reviews r ON b.id = r.booking_id
            WHERE b.client_id = ? 
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$client_id]);
        $bookings = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table errors
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="fw-bold text-white"><i class="bi bi-speedometer2 me-2 text-primary"></i>Client Dashboard</h2>
            <p class="text-muted">Manage your active charters, view reservation receipts, and contact your drivers.</p>
        </div>
    </div>

    <div class="row">
        <!-- Summary Stats -->
        <div class="col-12 mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card card-custom p-3 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Total Bookings</small>
                                <h3 class="fw-bold text-white mb-0 mt-1"><?php echo count($bookings); ?></h3>
                            </div>
                            <i class="bi bi-calendar2-check-fill text-primary display-6"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom p-3 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Confirmed Trips</small>
                                <h3 class="fw-bold text-success mb-0 mt-1">
                                    <?php 
                                    echo count(array_filter($bookings, function($b) {
                                        return $b['status'] === 'confirmed';
                                    }));
                                    ?>
                                </h3>
                            </div>
                            <i class="bi bi-shield-check-fill text-success display-6"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom p-3 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <small class="text-muted d-block uppercase fw-bold" style="font-size:0.75rem;">Pending Payments</small>
                                <h3 class="fw-bold text-warning mb-0 mt-1">
                                    <?php 
                                    echo count(array_filter($bookings, function($b) {
                                        return $b['status'] === 'pending_payment';
                                    }));
                                    ?>
                                </h3>
                            </div>
                            <i class="bi bi-hourglass-split text-warning display-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bookings List -->
        <div class="col-12">
            <!-- Flash Alerts -->
            <?php if (isset($_SESSION['booking_success'])): ?>
                <div class="alert alert-success border-0 py-2 px-4 mb-3 rounded-3 small" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['booking_success']); unset($_SESSION['booking_success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['booking_error'])): ?>
                <div class="alert alert-danger border-0 py-2 px-4 mb-3 rounded-3 small" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_SESSION['booking_error']); unset($_SESSION['booking_error']); ?>
                </div>
            <?php endif; ?>

            <h4 class="fw-bold text-white mb-3"><i class="bi bi-list-task me-2 text-primary"></i>My Charter Bookings</h4>
            
            <?php if (empty($bookings)): ?>
                <div class="card card-custom p-5 text-center">
                    <i class="bi bi-journal-x text-muted display-4 mb-3"></i>
                    <h5 class="text-white">You haven't booked any rides yet</h5>
                    <p class="text-muted mb-3">Search for available trips and book your premium private chauffeur service.</p>
                    <div class="d-flex justify-content-center">
                        <a href="../index.php" class="btn btn-gradient-primary px-4"><i class="bi bi-search me-2"></i>Find Trips</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($bookings as $booking): ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card card-custom p-3 shadow-sm h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <!-- Booking Header -->
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <small class="text-muted">Booking #<?php echo $booking['id']; ?></small>
                                        <?php if ($booking['status'] === 'confirmed'): ?>
                                            <span class="badge-verified"><i class="bi bi-check-circle-fill me-1"></i>Confirmed / Paid</span>
                                        <?php elseif ($booking['status'] === 'pending_payment'): ?>
                                            <span class="badge-pending"><i class="bi bi-hourglass-split me-1"></i>Pending Payment</span>
                                        <?php elseif ($booking['status'] === 'completed'): ?>
                                            <span class="badge-verified"><i class="bi bi-check-all me-1"></i>Trip Completed</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50 rounded-pill px-2.5 py-1.5 small"><i class="bi bi-x-circle-fill me-1"></i>Cancelled</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Trip Route -->
                                    <h5 class="fw-bold text-white mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($booking['origin']); ?></h5>
                                    <div class="ps-1 py-1 text-muted" style="border-left: 2px dashed var(--border-color); margin-left: 6px; font-size: 0.85rem;">to</div>
                                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-pin-map-fill text-success me-1"></i><?php echo htmlspecialchars($booking['destination']); ?></h5>
                                    
                                    <!-- Details Block -->
                                    <div class="bg-dark bg-opacity-25 rounded-3 p-3 mb-3 border border-secondary border-opacity-50">
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Departure Date & Time</small>
                                                <span class="text-white small fw-medium"><i class="bi bi-calendar-event me-1"></i><?php echo date('M d, Y h:i A', strtotime($booking['departure_time'])); ?></span>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Total Fare Paid</small>
                                                <span class="text-success small fw-bold"><i class="bi bi-credit-card me-1"></i><?php echo format_peso($booking['amount_paid']); ?></span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Vehicle Model</small>
                                                <span class="text-white small fw-medium"><i class="bi bi-car-front-fill me-1 text-info"></i><?php echo htmlspecialchars($booking['brand'] . ' ' . $booking['model']); ?></span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block" style="font-size:0.75rem;">Vehicle Plate No.</small>
                                                <span class="text-white small fw-medium"><i class="bi bi-postcard me-1 text-warning"></i><?php echo htmlspecialchars($booking['plate_number']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Contact Details if Confirmed -->
                                <div>
                                    <?php if ($booking['status'] === 'confirmed'): ?>
                                        <div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 p-3">
                                            <h6 class="fw-bold text-info mb-2"><i class="bi bi-telephone-fill me-2"></i>Driver Contact Information</h6>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="text-white fw-bold d-block"><?php echo htmlspecialchars($booking['driver_name']); ?></span>
                                                    <span class="text-muted small">Mobile: <strong><?php echo htmlspecialchars($booking['driver_phone']); ?></strong></span>
                                                </div>
                                                <div class="d-flex gap-2 w-100 mt-3">
                                                    <!-- In-App Trip Chat Only -->
                                                    <a href="<?php echo BASE_URL; ?>trip_chat.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-primary w-50 py-2 rounded-3 me-2" title="Open Trip Chat">
                                                        <i class="bi bi-chat-text-fill me-2"></i>Open Chat
                                                    </a>
                                                    <!-- Print Receipt Button -->
                                                    <a href="<?php echo BASE_URL; ?>print_receipt.php?booking_id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-outline-light w-50 py-2 rounded-3" title="Print Receipt">
                                                        <i class="bi bi-printer-fill me-2"></i>Receipt
                                                    </a>
                                                </div>
                                                <div class="mt-2">
                                                    <button type="button" class="btn btn-outline-danger w-100 py-2 rounded-3" data-bs-toggle="modal" data-bs-target="#clientCancelModal<?php echo $booking['id']; ?>">
                                                        <i class="bi bi-x-circle-fill me-2"></i>Cancel Booking
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="bg-warning bg-opacity-25 border border-warning border-opacity-50 rounded-3 p-3 mt-3 text-center">
                                                <small class="text-warning fw-bold d-block mb-1"><i class="bi bi-key-fill me-1"></i>TRIP COMPLETION PIN</small>
                                                <h2 class="display-5 fw-bold text-white mb-0 tracking-widest" style="letter-spacing: 4px;"><?php echo htmlspecialchars($booking['completion_pin']); ?></h2>
                                                <small class="text-muted" style="font-size:0.75rem;">Give this 4-digit PIN to your driver ONLY when you have safely arrived.</small>
                                            </div>
                                        </div>
                                    <?php elseif ($booking['status'] === 'pending_payment'): ?>
                                        <div class="d-flex gap-2">
                                            <a href="book_trip.php?trip_id=<?php echo $booking['trip_id']; ?>" class="btn btn-gradient-warning w-100 py-2">
                                                <i class="bi bi-credit-card-2-front me-2"></i>Retry Payment via Paymongo
                                            </a>
                                        </div>
                                    <?php elseif ($booking['status'] === 'completed'): ?>
                                        <div class="bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 p-3">
                                            <?php if (empty($booking['review_rating'])): ?>
                                                <h6 class="fw-bold text-success mb-2"><i class="bi bi-star-fill me-2"></i>Rate your Driver (<?php echo htmlspecialchars($booking['driver_name']); ?>)</h6>
                                                <form action="submit_review.php" method="POST">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    
                                                    <div class="mb-2">
                                                        <select name="rating" class="form-select bg-dark border-secondary text-white rounded-3 small" required>
                                                            <option value="" disabled selected>Select Stars</option>
                                                            <option value="5">⭐⭐⭐⭐⭐ Excellent (5 Stars)</option>
                                                            <option value="4">⭐⭐⭐⭐ Very Good (4 Stars)</option>
                                                            <option value="3">⭐⭐⭐ Good (3 Stars)</option>
                                                            <option value="2">⭐⭐ Fair (2 Stars)</option>
                                                            <option value="1">⭐ Poor (1 Star)</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="mb-2">
                                                        <textarea name="comment" rows="2" class="form-control bg-dark border-secondary text-white rounded-3 small" placeholder="Leave a short comment (optional)..." style="font-size:0.8rem;"></textarea>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-success btn-sm w-100 py-2 rounded-3">
                                                        Submit Review &rarr;
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <h6 class="fw-bold text-success mb-1"><i class="bi bi-patch-check-fill me-2"></i>Reviewed Driver</h6>
                                                <div class="text-warning small mb-1">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="bi <?php echo ($i <= $booking['review_rating']) ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <p class="text-white-50 small mb-0 italic" style="font-size:0.8rem;">
                                                    "<?php echo htmlspecialchars($booking['review_comment'] ?: 'No comment left.'); ?>"
                                                </p>
                                            <?php endif; ?>
                                            
                                            <!-- Completed Actions -->
                                            <div class="d-flex gap-2 mt-3 pt-2 border-top border-secondary border-opacity-25">
                                                <a href="<?php echo BASE_URL; ?>trip_chat.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-outline-primary btn-sm w-50 py-1.5 rounded-3" style="font-size: 0.8rem;">
                                                    <i class="bi bi-chat-text-fill me-1"></i>Chat History
                                                </a>
                                                <a href="<?php echo BASE_URL; ?>print_receipt.php?booking_id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-outline-light btn-sm w-50 py-1.5 rounded-3" style="font-size: 0.8rem;">
                                                    <i class="bi bi-printer-fill me-1"></i>View Receipt
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Render Modals Outside Transform Containers -->
<?php if (!empty($bookings)): ?>
    <?php foreach ($bookings as $booking): ?>
        <?php if ($booking['status'] === 'confirmed'): ?>
        <!-- Client Cancel Modal -->
        <div class="modal fade" id="clientCancelModal<?php echo $booking['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Cancel Booking?</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="cancel_booking.php" method="POST">
                        <div class="modal-body text-start">
                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                            <p class="text-white mb-2">Are you sure you want to cancel this booking?</p>
                            
                            <div class="bg-secondary bg-opacity-25 p-3 rounded-3 mt-3 border border-secondary">
                                <h6 class="text-warning fw-bold small"><i class="bi bi-info-circle-fill me-1"></i>Refund Policy Reminder:</h6>
                                <ul class="small text-muted mb-0 ps-3">
                                    <li>Cancel <strong>24+ hours</strong> before departure: <span class="text-success">100% Refund</span></li>
                                    <li>Cancel <strong>less than 24 hours</strong> before departure: <span class="text-danger">50% Refund only</span> (50% goes to driver as penalty).</li>
                                </ul>
                            </div>
                            
                            <p class="text-danger small fw-bold mt-3 mb-0">Note: Your refund will be credited directly to your SakayPH Wallet.</p>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Booking</button>
                            <button type="submit" class="btn btn-danger fw-bold px-4">Yes, Cancel Booking</button>
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
