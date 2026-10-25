<?php
include('../includes/config.php');
include('../includes/alert.php');

$errors = [];
$success = false;

$token = $_GET['token'] ?? '';
$user = null;

if (!is_string($token) || $token === '') {
    $errors[] = 'Invalid or missing password reset token.';
} else {
    // Find the user with this reset token.
    $result = mysqli_execute_query(
        $conn,
        "SELECT user_id, username
         FROM users
         WHERE reset_token = ?
           AND reset_expires_at > NOW()",
        [$token]
    );

    $user = $result ? mysqli_fetch_assoc($result) : null;

    if (!$user) {
        $errors[] = 'This password reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($password === '' || $confirm_password === '') {
        $errors[] = 'Please fill in both password fields.';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($hash === false) {
            $errors[] = 'Unable to update your password. Please try again.';
        } else {
            // Update the password and record when it was changed.
            $updated = mysqli_execute_query(
                $conn,
                "UPDATE users
                 SET password = ?,
                     password_changed_at = NOW(),
                     reset_token = NULL,
                     reset_expires_at = NULL,
                     reset_requested = 0
                 WHERE user_id = ?
                   AND reset_token = ?
                   AND reset_expires_at > NOW()",
                [$hash, $user['user_id'], $token]
            );

            if ($updated && mysqli_affected_rows($conn) === 1) {
                $success = true;
            } else {
                $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
            }
        }
    }
}

include('../includes/header.php');
?>

<div class="auth-box">
    <h1>Reset Password</h1>

    <?php if ($success): ?>

        <div class="alert alert-success">
            Your password has been reset successfully.
            You can now log in using your new password.
        </div>

        <p>
            <a href="<?= BASE_URL ?>/user/login.php">Go to Login</a>
        </p>

    <?php elseif ($errors): ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endforeach; ?>

        <p>
            <a href="<?= BASE_URL ?>/user/forgot_password.php">
                Request a new reset link
            </a>
        </p>

    <?php else: ?>

        <form method="post" action="?token=<?= urlencode($token) ?>">

            <label>
                New Password
                <input
                    type="password"
                    name="password"
                    required
                    minlength="6"
                >
            </label>

            <label>
                Confirm New Password
                <input
                    type="password"
                    name="confirm_password"
                    required
                    minlength="6"
                >
            </label>

            <button type="submit">Reset Password</button>

        </form>

    <?php endif; ?>
</div>

<?php include('../includes/footer.php'); ?>