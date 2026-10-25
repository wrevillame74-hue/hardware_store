<?php
// Include this at the very top of every admin page,
// right after config.php.

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

// Confirm the account still exists, is active, and is an admin.
$result = mysqli_execute_query(
    $conn,
    "SELECT role, is_active
     FROM users
     WHERE user_id = ?",
    [(int) $_SESSION['user_id']]
);

$currentUser = mysqli_fetch_assoc($result);

if (
    !$currentUser ||
    (int) $currentUser['is_active'] !== 1 ||
    $currentUser['role'] !== 'admin'
) {
    // Remove the session if the account is inactive or no longer an admin.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

// Keep the session role synchronized with the database.
$_SESSION['role'] = $currentUser['role'];