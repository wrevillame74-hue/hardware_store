<?php
include('../includes/config.php');
include('../includes/alert.php');

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = mysqli_execute_query(
        $conn,
        "SELECT user_id, username, password, role, email_verified, is_active
         FROM users
         WHERE username = ?",
        [$username]
    );

    $user = mysqli_fetch_assoc($result);

    if ($user && password_verify($password, $user['password'])) {

        // Prevent deactivated accounts from logging in.
        if ((int)$user['is_active'] !== 1) {
            $error = 'Your account has been deactivated. Please contact the administrator.';
        } elseif (
            $user['role'] === 'customer' &&
            (int)$user['email_verified'] !== 1
        ) {
            $error = 'Please verify your email address before logging in.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            set_alert('success', 'Welcome back, ' . $user['username'] . '!');

            $redirect = $_SESSION['redirect_after_login']
                ?? (BASE_URL . '/index.php');

            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirect);
            exit;
        }

    } else {
        $error = 'Incorrect username or password.';
    }
}

include('../includes/header.php');
?>
<div class="auth-box">
<h1>Log In</h1>

<?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" action="">
  <label>Username
    <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" required>
  </label>
  <label>Password
    <input type="password" name="password" required>
  </label>
  <button type="submit">Log In</button>
</form>

<p>Don't have an account? <a href="<?= BASE_URL ?>/user/register.php">Register</a></p>
<p><a href="<?= BASE_URL ?>/user/forgot_password.php">Forgot your password?</a></p>
</div>
<?php include('../includes/footer.php'); ?>