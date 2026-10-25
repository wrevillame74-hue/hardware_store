
<?php
include('../includes/config.php');
include('../includes/alert.php');

if (empty($_GET['token'])) {
    set_alert('error', 'Invalid verification link.');
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

$token = trim($_GET['token']);

$result = mysqli_execute_query(
    $conn,
    "SELECT user_id FROM users
     WHERE verification_token = ?
     AND email_verified = 0",
    [$token]
);

$user = mysqli_fetch_assoc($result);

if (!$user) {
    set_alert(
        'error',
        'This verification link is invalid or has already been used.'
    );
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

$updated = mysqli_execute_query(
    $conn,
    "UPDATE users
     SET email_verified = 1,
         verification_token = NULL
     WHERE user_id = ?
       AND email_verified = 0
       AND verification_token = ?",
    [$user['user_id'], $token]
);

if ($updated && mysqli_affected_rows($conn) === 1) {
    set_alert(
        'success',
        'Your email has been verified! You can now log in.'
    );
} else {
    set_alert(
        'error',
        'Verification failed. Please try the link again.'
    );
}

header('Location: ' . BASE_URL . '/user/login.php');
exit;