
<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/guard_customer.php');

$order_id = filter_var(
    $_GET['order_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($order_id === false || $order_id === null) {
    set_alert('error', 'Invalid order number.');
    header('Location: ' . BASE_URL . '/user/my_orders.php');
    exit;
}


$order_result = mysqli_execute_query(
    $conn,
    "SELECT order_id, customer_id, order_date, status, shipping_fee
     FROM orderinfo
     WHERE order_id = ?",
    [$order_id]
);

$order = mysqli_fetch_assoc($order_result);

if (!$order) {
    set_alert('error', 'Order not found.');
    header('Location: ' . BASE_URL . '/user/my_orders.php');
    exit;
}

// Verify that the order belongs to the logged-in customer.
$owner_check = mysqli_execute_query(
    $conn,
    "SELECT customer_id
     FROM customer
     WHERE user_id = ?",
    [(int) $_SESSION['user_id']]
);

$owner = mysqli_fetch_assoc($owner_check);

if (
    !$owner ||
    (int) $owner['customer_id'] !== (int) $order['customer_id']
) {
    set_alert('error', 'You are not authorized to view this Order.');
    header('Location: ' . BASE_URL . '/user/my_orders.php');
    exit;
}


$lines_result = mysqli_execute_query(
    $conn,
    "SELECT *
     FROM orderdetails
     WHERE order_id = ?",
    [$order_id]
);

$lines = [];
$customer_info = null;
$subtotal = 0;

while ($row = mysqli_fetch_assoc($lines_result)) {
    $customer_info = $row;
    $subtotal += (float) $row['line_total'];
    $lines[] = $row;
}

if ($customer_info === null) {
    set_alert('error', 'Receipt details could not be found.');
    header('Location: ' . BASE_URL . '/user/my_orders.php');
    exit;
}

include('../includes/header.php');
?>

<h1>Order Details</h1>

<div class="Order">
    <p>
        <strong>Order #<?= (int) $order['order_id'] ?></strong>
        —
        <?= htmlspecialchars($order['order_date']) ?>
    </p>

    <p>
        Status: <?= htmlspecialchars(ucfirst($order['status'])) ?>
    </p>

    <p>
        <?= htmlspecialchars($customer_info['fname'] . ' ' . $customer_info['lname']) ?><br>
        <?= htmlspecialchars($customer_info['address']) ?><br>
        <?= htmlspecialchars($customer_info['contact']) ?>
    </p>

    <table class="cart-table">
        <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Line Total</th>
        </tr>

        <?php foreach ($lines as $line): ?>
            <tr>
                <td><?= htmlspecialchars($line['item_name']) ?></td>
                <td><?= (int) $line['quantity'] ?></td>
                <td>₱<?= number_format((float) $line['price_each'], 2) ?></td>
                <td>₱<?= number_format((float) $line['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p>Subtotal: ₱<?= number_format($subtotal, 2) ?></p>

    <p>
        Shipping:
        ₱<?= number_format((float) $order['shipping_fee'], 2) ?>
    </p>

    <p>
        <strong>
            Total:
            ₱<?= number_format(
                $subtotal + (float) $order['shipping_fee'],
                2
            ) ?>
        </strong>
    </p>
</div>


<a href="<?= BASE_URL ?>/user/my_orders.php">
    <button type="button">Back to My Orders</button>
</a>

<?php include('../includes/footer.php'); ?>
