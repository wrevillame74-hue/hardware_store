<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/alert.php');

if (
    empty($_SESSION['user_id']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    set_alert('error', 'Please log in to cancel an order.');
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    set_alert('error', 'Invalid order.');
    header('Location: ' . BASE_URL . '/user/my_orders.php');
    exit;
}

// Confirm that this order belongs to the logged-in customer.
$customer_result = mysqli_execute_query(
    $conn,
    "SELECT customer_id FROM customer WHERE user_id = ?",
    [$_SESSION['user_id']]
);
$customer = mysqli_fetch_assoc($customer_result);

if (!$customer) {
    set_alert('error', 'Customer profile not found.');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Lock the order so concurrent cancellation requests cannot
    // both return the reserved stock.
    $order_result = mysqli_execute_query(
        $conn,
        "SELECT status
         FROM orderinfo
         WHERE order_id = ? AND customer_id = ?
         FOR UPDATE",
        [$order_id, $customer['customer_id']]
    );

    $order = mysqli_fetch_assoc($order_result);

    if (!$order || $order['status'] !== 'processing') {
        mysqli_rollback($conn);

        set_alert(
            'error',
            'This order can no longer be canceled.'
        );

        header('Location: ' . BASE_URL . '/user/my_orders.php');
        exit;
    }

    // Change the status before returning stock, inside the same
    // transaction. If anything fails, both changes roll back.
    mysqli_execute_query(
        $conn,
        "UPDATE orderinfo
         SET status = 'canceled'
         WHERE order_id = ?
           AND customer_id = ?
           AND status = 'processing'",
        [$order_id, $customer['customer_id']]
    );

    if (mysqli_affected_rows($conn) !== 1) {
        throw new RuntimeException('Order status was not changed.');
    }

    $lines_result = mysqli_execute_query(
        $conn,
        "SELECT item_id, quantity
         FROM orderline
         WHERE order_id = ?",
        [$order_id]
    );

    while ($line = mysqli_fetch_assoc($lines_result)) {
        mysqli_execute_query(
            $conn,
            "UPDATE stock
             SET quantity = quantity + ?
             WHERE item_id = ?",
            [$line['quantity'], $line['item_id']]
        );

        if (mysqli_affected_rows($conn) !== 1) {
            throw new RuntimeException('Stock could not be restored.');
        }
    }

    mysqli_commit($conn);

    set_alert('success', 'Order canceled and stock returned.');
} catch (Throwable $e) {
    mysqli_rollback($conn);

    set_alert(
        'error',
        'Something went wrong while canceling the order. Please try again.'
    );
}

header('Location: ' . BASE_URL . '/user/my_orders.php');
exit;