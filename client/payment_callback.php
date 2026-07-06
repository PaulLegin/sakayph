<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/paymongo_helper.php';
require_login(['client']);

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$client_id = $_SESSION['user_id'];

$error = '';
$booking = null;

if ($booking_id <= 0) {
    redirect('client/dashboard.php');
}

if ($pdo) {
    try {
        // Fetch booking and check ownership
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.driver_id 
            FROM bookings b 
            JOIN trips t ON b.trip_id = t.id 
            WHERE b.id = ? AND b.client_id = ?
        ");
        $stmt->execute([$booking_id, $client_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            $error = 'Booking record not found or unauthorized.';
        } else {
            // If already confirmed, skip API checking
            if ($booking['status'] === 'confirmed') {
                // Already processed successfully
            } elseif ($booking['status'] === 'pending_payment') {
                $session_id = $booking['paymongo_session_id'];
                
                if (empty($session_id)) {
                    $error = 'No payment session associated with this booking.';
                } else {
                    // Call Paymongo API helper to verify payment status
                    $is_paid = paymongo_is_session_paid($session_id);
                    
                    if ($is_paid) {
                        // Begin database transaction to confirm booking and update driver wallet
                        $pdo->beginTransaction();
                        
                        // 1. Update Booking status to confirmed
                        $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                        $stmt->execute([$booking_id]);
                        
                        // 2. Update Trip status to booked (hides it from public searches)
                        $stmt = $pdo->prepare("UPDATE trips SET status = 'booked' WHERE id = ?");
                        $stmt->execute([$booking['trip_id']]);
                        
                        // 3. Credit Driver Wallet Balance
                        $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                        $stmt->execute([$booking['driver_earnings'], $booking['driver_id']]);
                        
                        $pdo->commit();
                        
                        // Reload booking record to show updated status
                        $booking['status'] = 'confirmed';
                    } else {
                        $error = 'Payment has not been completed yet. If you paid via GCash/Maya, it may take a few minutes to process. Please check your dashboard.';
                    }
                }
            } else {
                $error = 'This booking has been cancelled.';
            }
        }
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'System Database Error: ' . $e->getMessage();
    }
} else {
    $error = 'Database connection error.';
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom p-4 text-center shadow-lg">
                <?php if (empty($error) && $booking && $booking['status'] === 'confirmed'): ?>
                    <!-- SUCCESS CARD -->
                    <i class="bi bi-patch-check-fill text-success display-1 mb-3"></i>
                    <h2 class="fw-bold text-white mb-2">Booking Secured!</h2>
                    <p class="text-muted small mb-4">Your payment of <strong><?php echo format_peso($booking['amount_paid']); ?></strong> was successfully processed by Paymongo. Your vehicle charter is now active.</p>
                    
                    <div class="bg-dark bg-opacity-25 border border-secondary rounded-3 p-3 text-start mb-4">
                        <div class="small text-muted mb-1">Trip Details:</div>
                        <h6 class="text-white fw-bold mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($booking['origin']); ?></h6>
                        <h6 class="text-white fw-bold"><i class="bi bi-pin-map-fill text-success me-1"></i><?php echo htmlspecialchars($booking['destination']); ?></h6>
                    </div>
                    
                    <a href="dashboard.php" class="btn btn-gradient-primary w-100 py-3">
                        <i class="bi bi-speedometer2 me-2"></i>Go to Client Dashboard
                    </a>
                    
                <?php else: ?>
                    <!-- ERROR / PENDING CARD -->
                    <i class="bi bi-hourglass-split text-warning display-2 mb-3"></i>
                    <h3 class="fw-bold text-white mb-2">Verifying Payment</h3>
                    <p class="text-muted small mb-4">We are currently waiting for payment confirmation from Paymongo or verifying your booking details.</p>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-warning py-2 rounded-3 border-0 small text-start mb-4" role="alert">
                            <i class="bi bi-info-circle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="dashboard.php" class="btn btn-outline-custom px-4 w-50"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                        <a href="payment_callback.php?booking_id=<?php echo $booking_id; ?>" class="btn btn-gradient-primary px-4 w-50"><i class="bi bi-arrow-clockwise me-2"></i>Refresh Status</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
