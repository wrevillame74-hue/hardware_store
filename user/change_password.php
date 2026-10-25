<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/alert.php');

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $result = mysqli_execute_query($conn, "SELECT password FROM users WHERE user_id = ?", [$_SESSION['user_id']]);
    $user = mysqli_fetch_assoc($result);

    if (!$user || !password_verify($current_password, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if ($new_password !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }
    if (strlen($new_password) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }

    if (!$errors) {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        mysqli_execute_query($conn, "UPDATE users SET password = ? WHERE user_id = ?", [$hash, $_SESSION['user_id']]);

        set_alert('success', 'Password changed successfully.');
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

include('../includes/header.php');
?>
<div class="auth-box">
<h1>Change Password</h1>

<?php foreach ($errors as $error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endforeach; ?>

<form method="post" action="">
  <label>Current Password
    <input type="password" name="current_password" required>
  </label>
  <label>New Password
    <input type="password" name="new_password" required minlength="6">
  </label>
  <label>Confirm New Password
    <input type="password" name="confirm_password" required minlength="6">
  </label>
  <button type="submit">Change Password</button>
</form>
</div>
<?php include('../includes/footer.php'); ?>