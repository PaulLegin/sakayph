<?php
require_once __DIR__ . '/../config.php';
require_login(['admin']);

// Allow both GET and POST requests for action
$driver_id = isset($_REQUEST['driver_id']) ? intval($_REQUEST['driver_id']) : 0;
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

if ($driver_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    redirect('admin/dashboard.php');
}

if ($pdo) {
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE users SET status = 'verified', admin_remarks = NULL WHERE id = ? AND role = 'driver'");
            $stmt->execute([$driver_id]);
            $_SESSION['vetting_success'] = 'Driver has been approved and verified successfully!';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET status = 'action_required', admin_remarks = ? WHERE id = ? AND role = 'driver'");
            $stmt->execute([$remarks, $driver_id]);
            $_SESSION['vetting_success'] = 'Driver application declined. They have been notified to re-upload documents.';
        }
    } catch (PDOException $e) {
        $_SESSION['vetting_error'] = 'Failed to update driver status: ' . $e->getMessage();
    }
}

redirect('admin/dashboard.php');
?>
