<?php
// SakayPH - Driver Payout History & Ledger
require_once __DIR__ . '/../config.php';
require_login(['driver']);

$driver_id = $_SESSION['user_id'];
$payouts = [];

if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM payouts 
            WHERE driver_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$driver_id]);
        $payouts = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table error
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container my-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="fw-bold text-white"><i class="bi bi-clock-history me-2 text-primary"></i>Payout History</h2>
            <p class="text-muted">Track all your past payout requests, pending settlements, and GCash transactions.</p>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-md-end mt-3 mt-md-0">
            <a href="dashboard.php" class="btn btn-outline-light w-100 w-md-auto py-2.5">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Payouts Table -->
    <div class="card card-custom p-4 shadow">
        <h5 class="fw-bold text-white mb-3"><i class="bi bi-list-stars text-primary me-2"></i>Withdrawal Ledger</h5>
        
        <?php if (empty($payouts)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-cash-stack display-5 mb-2"></i>
                <p class="small mb-0">You haven't requested any payouts yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border border-secondary" style="border-radius:12px; overflow:hidden;">
                    <thead>
                        <tr class="text-muted small">
                            <th class="ps-3">Request Date</th>
                            <th>GCash Account Name</th>
                            <th>GCash Number</th>
                            <th>Amount Requested</th>
                            <th>Status</th>
                            <th class="pe-3">Settled Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $p): ?>
                            <tr class="small text-white">
                                <td class="ps-3 py-3"><?php echo date('M d, Y h:i A', strtotime($p['created_at'])); ?></td>
                                <td class="text-uppercase"><?php echo htmlspecialchars($p['gcash_name']); ?></td>
                                <td class="font-monospace text-info"><?php echo htmlspecialchars($p['gcash_number']); ?></td>
                                <td class="fw-bold text-success" style="font-size:0.95rem;"><?php echo format_peso($p['amount']); ?></td>
                                <td>
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3 py-1">Pending Admin Approval</span>
                                    <?php elseif ($p['status'] === 'completed'): ?>
                                        <span class="badge bg-success text-white rounded-pill px-3 py-1">Paid / Settled</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger text-white rounded-pill px-3 py-1">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 text-muted">
                                    <?php echo $p['completed_at'] ? date('M d, Y h:i A', strtotime($p['completed_at'])) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
