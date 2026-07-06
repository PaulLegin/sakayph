<?php
// SakayPH - Email Verification Handler
require_once __DIR__ . '/config.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$status_msg = '';
$status_type = 'error'; // error or success

if (empty($token)) {
    $status_msg = 'Invalid or missing verification token.';
} else {
    if ($pdo) {
        try {
            // Find user with this token
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, is_email_verified FROM users WHERE email_verification_token = ?");
            $stmt->execute([$token]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['is_email_verified'] == 1) {
                    $status_msg = 'Your email address is already verified. You can log in now.';
                    $status_type = 'success';
                } else {
                    // Update user as verified
                    $update = $pdo->prepare("UPDATE users SET is_email_verified = 1, email_verification_token = NULL WHERE id = ?");
                    $update->execute([$user['id']]);
                    
                    $status_msg = 'Congratulations, ' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . '! Your email has been successfully verified.';
                    $status_type = 'success';
                }
            } else {
                $status_msg = 'The verification link is invalid, expired, or has already been used.';
            }
        } catch (PDOException $e) {
            $status_msg = 'Database error occurred: ' . $e->getMessage();
        }
    } else {
        $status_msg = 'Database connection error.';
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom p-5 text-center shadow-lg">
                
                <?php if ($status_type === 'success'): ?>
                    <div class="mb-4">
                        <i class="bi bi-patch-check-fill text-success display-1"></i>
                    </div>
                    <h3 class="fw-bold text-white mb-2">Email Verified!</h3>
                    <p class="text-muted small mb-4"><?php echo htmlspecialchars($status_msg); ?></p>
                    <a href="login.php" class="btn btn-gradient-primary w-100 py-3 rounded-3 fw-bold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Log In Now
                    </a>
                <?php else: ?>
                    <div class="mb-4">
                        <i class="bi bi-exclamation-octagon-fill text-danger display-1"></i>
                    </div>
                    <h3 class="fw-bold text-white mb-2">Verification Failed</h3>
                    <p class="text-muted small mb-4"><?php echo htmlspecialchars($status_msg); ?></p>
                    <a href="register.php" class="btn btn-outline-light w-100 py-3 rounded-3 fw-bold">
                        Back to Sign Up
                    </a>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
