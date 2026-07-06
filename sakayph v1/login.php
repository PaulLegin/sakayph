<?php
require_once __DIR__ . '/config.php';
redirect_if_logged_in();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Client must have verified email
                if ($user['role'] === 'client' && $user['is_email_verified'] == 0) {
                    $error = 'Please verify your email address before logging in. Check your inbox (simulated in email_logs.txt).';
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_status'] = $user['status'];
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        redirect('admin/dashboard.php');
                    } elseif ($user['role'] === 'driver') {
                        redirect('driver/dashboard.php');
                    } else {
                        redirect('client/dashboard.php');
                    }
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Database connection error. Please make sure sakayph_db is created and imported.';
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card card-custom p-4 shadow">
                <div class="text-center mb-4">
                    <h2 class="fw-bold" style="background: var(--primary-gradient); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Welcome Back</h2>
                    <p class="text-white-50 small">Log in to manage your private rentals and chauffeur bookings.</p>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 rounded-3 border-0 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                        <?php if (strpos($error, 'verify your email') !== false): ?>
                            <br><a href="resend_verification.php" class="alert-link text-decoration-none mt-1 d-inline-block fw-bold"><i class="bi bi-envelope-exclamation-fill me-1"></i>Resend Verification Link here</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form action="login.php" method="POST">
                    <div class="mb-3">
                        <label for="email" class="form-label text-white small fw-bold">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control form-control-custom text-white" placeholder="name@domain.com" required>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="password" class="form-label text-white small mb-0 fw-bold">Password</label>
                        </div>
                        <input type="password" name="password" id="password" class="form-control form-control-custom text-white" placeholder="••••••••" required>
                    </div>
                    
                    <button type="submit" class="btn btn-gradient-primary w-100 py-2.5 mb-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Log In
                    </button>
                    
                    <div class="text-center mt-3">
                        <p class="text-white-50 small mb-0">New to SakayPH? <a href="register.php" class="text-decoration-none" style="color: var(--primary); font-weight:600;">Create Account</a></p>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
