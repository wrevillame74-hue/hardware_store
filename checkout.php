<?php
include('includes/config.php');
include('includes/alert.php');

// Guest check: remember checkout as the redirect destination.
if (empty($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = BASE_URL . '/checkout.php';
    set_alert('error', 'Please log in to check out.');
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

include('includes/guard_customer.php');

$cart = $_SESSION['cart'] ?? [];

if (!$cart) {
    set_alert('error', 'Your cart is empty.');
    header('Location: ' . BASE_URL . '/view_cart.php');
    exit;
}

// Validate cart item IDs and quantities.
foreach ($cart as $item_id => $qty) {
    if (
        !ctype_digit((string)$item_id) ||
        (int)$item_id <= 0 ||
        filter_var($qty, FILTER_VALIDATE_INT) === false ||
        (int)$qty <= 0
    ) {
        set_alert('error', 'Your cart contains an invalid item or quantity.');
        header('Location: ' . BASE_URL . '/view_cart.php');
        exit;
    }
}

// Find the customer's profile.
$customer_result = mysqli_execute_query(
    $conn,
    "SELECT customer_id, fname, lname, address, contact
     FROM customer
     WHERE user_id = ?",
    [$_SESSION['user_id']]
);

$customer = mysqli_fetch_assoc($customer_result);

if (!$customer) {
    set_alert('error', 'Please complete your profile before checking out.');
    header('Location: ' . BASE_URL . '/user/profile.php');
    exit;
}

// Load current product details and stock.
$ids = array_map('intval', array_keys($cart));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$sql = "SELECT i.item_id, i.item_name, i.price, s.quantity AS stock_qty
        FROM item i
        JOIN stock s ON s.item_id = i.item_id
        WHERE i.item_id IN ($placeholders)";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$ids);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$cart_items = [];
$insufficient = [];

while ($row = mysqli_fetch_assoc($result)) {
    $item_id = (int)$row['item_id'];
    $qty = (int)$cart[$item_id];

    if ($qty > (int)$row['stock_qty']) {
        $insufficient[] = $row['item_name'];
    }

    $cart_items[] = array_merge($row, ['qty' => $qty]);
}

// Don't accidentally place an order for only part of the cart.
if (count($cart_items) !== count($cart)) {
    set_alert(
        'error',
        'One or more cart items are no longer available. Please review your cart.'
    );
    header('Location: ' . BASE_URL . '/view_cart.php');
    exit;
}

if ($insufficient) {
    set_alert(
        'error',
        'Not enough stock for: ' . implode(', ', $insufficient) .
        '. Please adjust your cart.'
    );
    header('Location: ' . BASE_URL . '/view_cart.php');
    exit;
}

// Create the order and reserve stock in one transaction.
mysqli_begin_transaction($conn);

try {
    mysqli_execute_query(
        $conn,
        "INSERT INTO orderinfo (customer_id, shipping_fee, status)
         VALUES (?, ?, 'processing')",
        [$customer['customer_id'], SHIPPING_FEE]
    );

    $order_id = mysqli_insert_id($conn);

    foreach ($cart_items as $row) {
        $item_id = (int)$row['item_id'];
        $qty = (int)$row['qty'];

        // Atomically deduct stock only if enough remains.
        mysqli_execute_query(
            $conn,
            "UPDATE stock
             SET quantity = quantity - ?
             WHERE item_id = ?
               AND quantity >= ?",
            [$qty, $item_id, $qty]
        );

        if (mysqli_affected_rows($conn) !== 1) {
            throw new RuntimeException(
                'Stock changed before checkout could complete.'
            );
        }

        mysqli_execute_query(
            $conn,
            "INSERT INTO orderline
                (order_id, item_id, quantity, price_each)
             VALUES (?, ?, ?, ?)",
            [$order_id, $item_id, $qty, $row['price']]
        );
    }

    mysqli_commit($conn);

    $_SESSION['cart'] = [];

    header(
        'Location: ' . BASE_URL .
        '/user/order_details.php?order_id=' . $order_id
    );
    exit;

} catch (Throwable $e) {
    mysqli_rollback($conn);

    if ($e instanceof RuntimeException) {
        set_alert(
            'error',
            'Stock changed while you were checking out. No order was placed. Please review your cart and try again.'
        );
    } else {
        set_alert(
            'error',
            'Something went wrong while placing your order. Please try again.'
        );
    }

    header('Location: ' . BASE_URL . '/view_cart.php');
    exit;
}