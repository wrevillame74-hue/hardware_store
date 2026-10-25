<?php
// Include this after config.php at the top of protected customer pages.

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

$result = mysqli_execute_query(
    $conn,
    "SELECT role, is_active
     FROM users
     WHERE user_id = ?",
    [(int) $_SESSION['user_id']]
);

$currentUser = mysqli_fetch_assoc($result);

// Customers must still exist, be active, and have the customer role.
if (
    !$currentUser ||
    (int) $currentUser['is_active'] !== 1 ||
    $currentUser['role'] !== 'customer'
) {
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