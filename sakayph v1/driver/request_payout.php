<?php
require_once __DIR__ . '/../config.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
$error = '';
$success = '';
$wallet_balance = 0.00;

if ($pdo) {
    try {
        // Fetch current wallet balance and verification status
        $stmt = $pdo->prepare("SELECT wallet_balance, status FROM users WHERE id = ?");
        $stmt->execute([$driver_id]);
        $driver = $stmt->fetch();
        
        if (!$driver || $driver['status'] !== 'verified') {
            redirect('driver/dashboard.php');
        }
        
        $wallet_balance = floatval($driver['wallet_balance']);
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Block entry if balance is below threshold
if ($wallet_balance < MIN_PAYOUT_AMOUNT) {
    redirect('driver/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount']);
    $gcash_number = trim($_POST['gcash_number']);
    $gcash_name = trim($_POST['gcash_name']);
    
    if (empty($gcash_number) || empty($gcash_name) || $amount <= 0) {
        $error = 'Please fill in all fields correctly.';
    } elseif ($amount < MIN_PAYOUT_AMOUNT) {
        $error = 'Requested amount must be at least ' . format_peso(MIN_PAYOUT_AMOUNT) . '.';
    } elseif ($amount > $wallet_balance) {
        $error = 'You cannot withdraw more than your current wallet balance of ' . format_peso($wallet_balance) . '.';
    } else {
        if ($pdo) {
            try {
                // Begin database transaction
                $pdo->beginTransaction();
                
                // 1. Deduct from driver's wallet immediately to lock the balance
                $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $driver_id]);
                
                // 2. Insert payout request into database
                $stmt = $pdo->prepare("
                    INSERT INTO payouts (driver_id, amount, gcash_number, gcash_name, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([$driver_id, $amount, $gcash_number, $gcash_name]);
                
                $pdo->commit();
                
                $_SESSION['payout_success'] = 'Payout request of ' . format_peso($amount) . ' submitted successfully. Admin will process it via GCash soon.';
                redirect('driver/dashboard.php');
                
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to submit payout request: ' . $e->getMessage();
            }
        } else {
            $error = 'Database connection failed.';
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <a href="dashboard.php" class="btn btn-outline-custom btn-sm rounded-pill px-3 mb-3"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
            
            <div class="card card-custom p-4 shadow">
                <div class="text-center mb-4">
                    <i class="bi bi-cash-coin text-success display-4 mb-2"></i>
                    <h3 class="fw-bold text-white">Request GCash Payout</h3>
                    <p class="text-muted small mb-0">Withdraw your earnings directly to your GCash wallet.</p>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 rounded-3 border-0 small mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Balance indicator -->
                <div class="bg-dark bg-opacity-25 border border-secondary rounded-3 p-3 text-center mb-4">
                    <span class="text-muted small d-block">Available Wallet Balance</span>
                    <h2 class="fw-bold text-success mb-0 mt-1"><?php echo format_peso($wallet_balance); ?></h2>
                </div>
                
                <form action="request_payout.php" method="POST">
                    <div class="mb-3">
                        <label for="amount" class="form-label text-muted small">Withdrawal Amount (₱)</label>
                        <input type="number" name="amount" id="amount" class="form-control form-control-custom text-white fw-bold" 
                               min="<?php echo MIN_PAYOUT_AMOUNT; ?>" max="<?php echo $wallet_balance; ?>" 
                               value="<?php echo $wallet_balance; ?>" step="0.01" required>
                        <div class="form-text text-muted small mt-1">Minimum payout is <strong><?php echo format_peso(MIN_PAYOUT_AMOUNT); ?></strong>.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="gcash_number" class="form-label text-muted small">GCash Account Number</label>
                        <input type="text" name="gcash_number" id="gcash_number" class="form-control form-control-custom" placeholder="e.g. 09171234567" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="gcash_name" class="form-label text-muted small">GCash Registered Name</label>
                        <input type="text" name="gcash_name" id="gcash_name" class="form-control form-control-custom" placeholder="e.g. JUAN DELA CRUZ" required>
                    </div>
                    
                    <button type="submit" class="btn btn-gradient-success w-100 py-3">
                        <i class="bi bi-send-check me-2"></i>Submit Payout Request
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>
