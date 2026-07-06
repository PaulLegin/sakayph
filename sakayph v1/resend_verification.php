<?php
// SakayPH - Resend Email Verification Link
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email_helper.php';
redirect_if_logged_in();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        if ($pdo) {
            try {
                // Find client user that is currently unverified
                $stmt = $pdo->prepare("SELECT id, name, role, is_email_verified FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    if ($user['role'] !== 'client') {
                        $error = 'Verification resending is only required for passenger accounts.';
                    } elseif ($user['is_email_verified'] == 1) {
                        $error = 'This email address is already verified. You can log in directly.';
                    } else {
                        // Generate new token
                        $new_token = bin2hex(random_bytes(16));
                        
                        // Update DB
                        $stmt = $pdo->prepare("UPDATE users SET email_verification_token = ? WHERE id = ?");
                        $stmt->execute([$new_token, $user['id']]);
                        
                        // Dispatch email
                        send_verification_email($email, $user['name'], $new_token);
                        
                        $success = 'A new verification link has been sent to your email. Please check your inbox (simulated in email_logs.txt).';
                    }
                } else {
                    // Fail silently for security to avoid email harvesting
                    $success = 'If this email is registered and unverified, a verification link has been sent.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card card-custom p-4 shadow-lg">
                <div class="text-center mb-4">
                    <h3 class="fw-bold text-white"><i class="bi bi-envelope-exclamation-fill text-warning me-2"></i>Resend Link</h3>
                    <p class="text-muted small">Enter your email address to receive a new activation link.</p>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 rounded-3 border-0 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success py-2 rounded-3 border-0 small" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <form action="resend_verification.php" method="POST">
                    <div class="mb-4">
                        <label for="email" class="form-label text-muted small">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control form-control-custom text-white" placeholder="name@domain.com" required autocomplete="off">
                    </div>
                    
                    <button type="submit" class="btn btn-gradient-primary w-100 py-2.5 mb-3">
                        <i class="bi bi-send-fill me-2"></i>Resend Link
                    </button>
                    
                    <div class="text-center mt-3">
                        <p class="text-muted small mb-0"><a href="login.php" class="text-decoration-none text-white-50"><i class="bi bi-arrow-left me-1"></i>Back to Login</a></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
