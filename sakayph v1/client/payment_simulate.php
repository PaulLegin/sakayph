<?php
// SakayPH - Paymongo Payment Simulation Page
// This page replaces real Paymongo checkout when PAYMONGO_TEST_MODE = true
require_once __DIR__ . '/../config.php';
require_login(['client']);

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$client_id = $_SESSION['user_id'];

if ($booking_id <= 0) redirect('client/dashboard.php');

// Fetch booking and trip details, verify ownership
$booking = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.departure_time, t.estimated_hours, t.driver_id,
                   CONCAT(u.first_name, ' ', u.last_name) AS driver_name
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            JOIN users u ON t.driver_id = u.id
            WHERE b.id = ? AND b.client_id = ? AND b.status = 'pending_payment'
        ");
        $stmt->execute([$booking_id, $client_id]);
        $booking = $stmt->fetch();
    } catch (PDOException $e) {}
}

if (!$booking) redirect('client/dashboard.php');

// Handle simulation "Pay" button submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_pay'])) {
    try {
        $pdo->beginTransaction();

        // 1. Confirm the booking and generate PIN
        $pin = sprintf("%04d", mt_rand(0, 9999));
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', completion_pin = ? WHERE id = ?");
        $stmt->execute([$pin, $booking_id]);

        // 2. Mark trip as booked
        $stmt = $pdo->prepare("UPDATE trips SET status = 'booked' WHERE id = ?");
        $stmt->execute([$booking['trip_id']]);
        
        // 3. Dynamic Time Blocking (Auto-Cancel overlapping trips)
        $driver_id = $booking['driver_id'];
        $departure_start = $booking['departure_time'];
        $estimated_hours = intval($booking['estimated_hours']);
        $departure_end = date('Y-m-d H:i:s', strtotime($departure_start . " + {$estimated_hours} hours"));
        
        $stmt_cancel = $pdo->prepare("
            UPDATE trips 
            SET status = 'cancelled' 
            WHERE driver_id = ? 
            AND status = 'active' 
            AND id != ? 
            AND departure_time >= ? 
            AND departure_time <= ?
        ");
        $stmt_cancel->execute([$driver_id, $booking['trip_id'], $departure_start, $departure_end]);

        // 4. ESCROW SYSTEM: We DO NOT credit the driver's wallet here.
        // The funds are held by the Admin until the trip is completed.
        
        // 5. Send automated first chat message to initiate the conversation
        $sys_message = "Hello! Your charter booking has been successfully paid and confirmed. You can now chat to discuss the exact pickup details.";
        $stmt_chat = $pdo->prepare("INSERT INTO messages (booking_id, sender_id, message) VALUES (?, ?, ?)");
        $stmt_chat->execute([$booking_id, $booking['client_id'], $sys_message]);

        $pdo->commit();

        // Redirect to success/callback page
        header("Location: " . BASE_URL . "client/payment_callback.php?booking_id=" . $booking_id . "&status=success");
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Simulation error: ' . $e->getMessage();
    }
}

// Handle cancel
if (isset($_GET['cancel'])) {
    redirect('client/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SakayPH Payment Simulation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f4ff; min-height: 100vh; display: flex; align-items: center; justify-content: center; }

        .sim-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
        }

        .pay-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.12);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }

        .pay-header {
            background: linear-gradient(135deg, #1a56db, #0e4bbd);
            padding: 28px;
            text-align: center;
        }

        .paymongo-logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.5px;
        }

        .pay-body { padding: 28px; }

        .amount-display {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border: 2px solid #bfdbfe;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-bottom: 24px;
        }

        .amount-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: #1a56db;
            line-height: 1;
        }

        .trip-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
        }

        .trip-info-row:last-child { border-bottom: none; }
        .trip-info-label { color: #64748b; }
        .trip-info-value { font-weight: 600; color: #1e293b; text-align: right; max-width: 60%; }

        .method-option {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .method-option:hover, .method-option.selected {
            border-color: #1a56db;
            background: #eff6ff;
        }

        .method-option input[type="radio"] { accent-color: #1a56db; }

        .method-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .btn-pay {
            background: linear-gradient(135deg, #1a56db, #0e4bbd);
            border: none;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            padding: 14px;
            border-radius: 12px;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-pay:hover {
            background: linear-gradient(135deg, #1e40af, #1a56db);
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(26, 86, 219, 0.35);
        }

        .btn-pay:active { transform: translateY(0); }

        .ssl-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #64748b;
            font-size: 0.75rem;
            margin-top: 12px;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e2e8f0;
            border-top-color: #1a56db;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <p class="fw-600 text-primary mb-0">Processing payment...</p>
    <small class="text-muted">Please wait, do not close this page.</small>
</div>

<div class="pay-card">
    <!-- Header -->
    <div class="pay-header">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="paymongo-logo">pay<span style="color:#7dd3fc">mongo</span></div>
            <span class="sim-badge">⚙ Simulation</span>
        </div>
        <small style="color:rgba(255,255,255,0.7);font-size:0.78rem;">Secure Payment Gateway</small>
    </div>

    <!-- Body -->
    <div class="pay-body">

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger small mb-3 rounded-3"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Amount -->
        <div class="amount-display">
            <div class="text-muted small fw-500 mb-1">Total Amount Due</div>
            <div class="amount-value">₱<?php echo number_format($booking['amount_paid'], 2); ?></div>
            <div class="text-muted" style="font-size:0.78rem;margin-top:4px;">SakayPH Charter Booking</div>
        </div>

        <!-- Trip Details -->
        <div class="mb-4">
            <div class="trip-info-row">
                <span class="trip-info-label">📍 From</span>
                <span class="trip-info-value"><?php echo htmlspecialchars($booking['origin']); ?></span>
            </div>
            <div class="trip-info-row">
                <span class="trip-info-label">🏁 To</span>
                <span class="trip-info-value"><?php echo htmlspecialchars($booking['destination']); ?></span>
            </div>
            <div class="trip-info-row">
                <span class="trip-info-label">🕐 Departure</span>
                <span class="trip-info-value"><?php echo date('M d, Y h:i A', strtotime($booking['departure_time'])); ?></span>
            </div>
            <div class="trip-info-row">
                <span class="trip-info-label">🚗 Driver</span>
                <span class="trip-info-value"><?php echo htmlspecialchars($booking['driver_name']); ?></span>
            </div>
        </div>

        <!-- Payment Method Selection (Simulated) -->
        <div class="mb-4">
            <div class="small fw-bold text-muted mb-2 text-uppercase" style="letter-spacing:1px;">Choose Payment Method</div>
            <label class="method-option selected">
                <input type="radio" name="method" checked>
                <div class="method-icon" style="background:#e0f2fe;">💳</div>
                <div>
                    <div class="fw-600 small">GCash / Maya</div>
                    <div class="text-muted" style="font-size:0.75rem;">E-Wallet Payment</div>
                </div>
            </label>
            <label class="method-option">
                <input type="radio" name="method">
                <div class="method-icon" style="background:#fce7f3;">🏦</div>
                <div>
                    <div class="fw-600 small">Credit / Debit Card</div>
                    <div class="text-muted" style="font-size:0.75rem;">Visa, Mastercard</div>
                </div>
            </label>
        </div>

        <!-- Pay Button -->
        <form method="POST" id="payForm">
            <input type="hidden" name="simulate_pay" value="1">
            <button type="submit" class="btn-pay" onclick="showLoading()">
                <i class="bi bi-shield-lock-fill me-2"></i>Pay ₱<?php echo number_format($booking['amount_paid'], 2); ?> Securely
            </button>
        </form>

        <!-- SSL Badge -->
        <div class="ssl-badge">
            <i class="bi bi-lock-fill text-success"></i>
            Secured by 256-bit SSL Encryption
        </div>

        <!-- Cancel Link -->
        <div class="text-center mt-3">
            <a href="dashboard.php" class="text-muted small text-decoration-none">
                <i class="bi bi-x-circle me-1"></i>Cancel and go back
            </a>
        </div>
    </div>
</div>

<script>
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'flex';
}

// Method selection visual
document.querySelectorAll('.method-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.method-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
    });
});
</script>
</body>
</html>
