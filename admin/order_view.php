<?php
include('../includes/config.php');
include('../includes/guard_admin.php');
include('../includes/alert.php');

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'] ?? '';

    if ($order_id <= 0 || !in_array($new_status, ['delivered', 'canceled'], true)) {
        set_alert('error', 'Invalid order status.');
        header('Location: ' . BASE_URL . '/admin/orders.php');
        exit;
    }

    mysqli_begin_transaction($conn);

    try {
        // Lock the order while checking and changing its status.
        $current_result = mysqli_execute_query(
            $conn,
            "SELECT status
             FROM orderinfo
             WHERE order_id = ?
             FOR UPDATE",
            [$order_id]
        );

        $current_order = mysqli_fetch_assoc($current_result);

        if (!$current_order) {
            throw new RuntimeException('Order not found.');
        }

        // Only processing orders can be approved or canceled.
        if ($current_order['status'] !== 'processing') {
            mysqli_rollback($conn);

            set_alert(
                'error',
                'This order has already been finalized and cannot be changed.'
            );

            header(
                'Location: ' . BASE_URL .
                '/admin/order_view.php?order_id=' . $order_id
            );
            exit;
        }

        // If canceled, return reserved stock exactly once.
        if ($new_status === 'canceled') {
            $lines = mysqli_execute_query(
                $conn,
                "SELECT item_id, quantity
                 FROM orderline
                 WHERE order_id = ?",
                [$order_id]
            );

            while ($line = mysqli_fetch_assoc($lines)) {
                mysqli_execute_query(
                    $conn,
                    "UPDATE stock
                     SET quantity = quantity + ?
                     WHERE item_id = ?",
                    [$line['quantity'], $line['item_id']]
                );

                if (mysqli_affected_rows($conn) !== 1) {
                    throw new RuntimeException('Could not restore stock.');
                }
            }
        }

        // Finalize the status within the same transaction.
        mysqli_execute_query(
            $conn,
            "UPDATE orderinfo
             SET status = ?
             WHERE order_id = ?
               AND status = 'processing'",
            [$new_status, $order_id]
        );

        if (mysqli_affected_rows($conn) !== 1) {
            throw new RuntimeException('Order status could not be updated.');
        }

        mysqli_commit($conn);

        // Send the status notification after the order transaction commits.
        // Email failure must not undo the completed order update.
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            require_once __DIR__ . '/../includes/order_receipt_pdf.php';

            $recipient_result = mysqli_execute_query(
                $conn,
                "SELECT u.email, c.fname, c.lname, o.shipping_fee
                 FROM orderinfo o
                 JOIN customer c ON c.customer_id = o.customer_id
                 JOIN users u ON u.user_id = c.user_id
                 WHERE o.order_id = ?",
                [$order_id]
            );

            $recipient = mysqli_fetch_assoc($recipient_result);

            if (
                !$recipient ||
                !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException(
                    'No valid customer email address was found.'
                );
            }

            $customer_name = trim(
                $recipient['fname'] . ' ' . $recipient['lname']
            );

            $details_result = mysqli_execute_query(
                $conn,
                "SELECT item_name, quantity, price_each, line_total
                 FROM orderdetails
                 WHERE order_id = ?",
                [$order_id]
            );

            $subtotal = 0;
            $product_rows = '';

            while ($line = mysqli_fetch_assoc($details_result)) {
                $subtotal += (float)$line['line_total'];

                $product_rows .= '<tr>
                    <td>' . htmlspecialchars(
                        $line['item_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) . '</td>
                    <td>' . (int)$line['quantity'] . '</td>
                    <td>₱' . number_format(
                        (float)$line['price_each'],
                        2
                    ) . '</td>
                    <td>₱' . number_format(
                        (float)$line['line_total'],
                        2
                    ) . '</td>
                </tr>';
            }

            $shipping = (float)$recipient['shipping_fee'];
            $grand_total = $subtotal + $shipping;

            $status_label = $new_status === 'delivered'
                ? 'Approved'
                : 'Canceled';

            $subject = 'Sturdy Supplies - Order #' .
                $order_id . ' ' . $status_label;
$currency = 'PHP ';
            $body = '
                <h2>Sturdy Supplies Order Update</h2>
                <p>Hello ' . htmlspecialchars(
                    $customer_name,
                    ENT_QUOTES,
                    'UTF-8'
                ) . ',</p>
                <p>Your order <strong>#' . (int)$order_id .
                '</strong> has been <strong>' . $status_label .
                '</strong>.</p>

                <h3>Order Details</h3>
                <table border="1" cellpadding="8"
                    cellspacing="0" style="border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>' . $product_rows . '</tbody>
                </table>

                <p>Subtotal: ₱' . number_format($subtotal, 2) . '</p>
                <p>Shipping: ₱' . number_format($shipping, 2) . '</p>
                <p><strong>Grand Total: ₱' .
                    number_format($grand_total, 2) . '</strong></p>

                <p>Your PDF receipt is attached to this email.</p>
                <p>Thank you for choosing Sturdy Supplies!</p>
            ';

            $pdf_path = generate_order_receipt_pdf(
                $conn,
                $order_id
            );

            try {
                $mail_sent = send_mail(
                    $recipient['email'],
                    $customer_name,
                    $subject,
                    $body,
                    [$pdf_path]
                );

                if (!$mail_sent) {
                    error_log(
                        'Order status email failed for order #' .
                        $order_id
                    );
                }
            } finally {
                if (is_file($pdf_path)) {
                    unlink($pdf_path);
                }
            }

        } catch (Throwable $mail_error) {
            error_log(
                'Order notification error for order #' .
                $order_id . ': ' . $mail_error->getMessage()
            );
        }

        if ($new_status === 'delivered') {
            set_alert(
                'success',
                'Order approved successfully. Status changed to Delivered.'
            );
        } else {
            set_alert(
                'success',
                'Order rejected. Status changed to Canceled and stock returned.'
            );
        }

    } catch (Throwable $e) {
        mysqli_rollback($conn);

        set_alert(
            'error',
            'Something went wrong while updating the order. Please try again.'
        );
    }

    header(
        'Location: ' . BASE_URL .
        '/admin/order_view.php?order_id=' . $order_id
    );
    exit;
}

$order_result = mysqli_execute_query(
    $conn,
    "SELECT o.*, c.fname, c.lname, c.address, c.contact
     FROM orderinfo o
     JOIN customer c ON c.customer_id = o.customer_id
     WHERE o.order_id = ?",
    [$order_id]
);

$order = mysqli_fetch_assoc($order_result);

if (!$order) {
    header('Location: ' . BASE_URL . '/admin/orders.php');
    exit;
}

$lines_result = mysqli_execute_query(
    $conn,
    "SELECT ol.*, i.item_name
     FROM orderline ol
     JOIN item i ON i.item_id = ol.item_id
     WHERE ol.order_id = ?",
    [$order_id]
);

$subtotal = 0;
$lines = [];

while ($line = mysqli_fetch_assoc($lines_result)) {
    $subtotal += $line['quantity'] * $line['price_each'];
    $lines[] = $line;
}

$total = $subtotal + $order['shipping_fee'];

include('../includes/admin_header.php');
?>

<h1>Order #<?= (int)$order['order_id'] ?></h1>

<p>
    <?= htmlspecialchars($order['fname'] . ' ' . $order['lname']) ?><br>
    <?= htmlspecialchars($order['address']) ?><br>
    <?= htmlspecialchars($order['contact']) ?>
</p>

<p>
    <strong>Current Status:</strong>
    <?= htmlspecialchars(ucfirst($order['status'])) ?>
</p>

<?php if ($order['status'] === 'processing'): ?>
    <form method="post" action="" class="filter-bar">
        <input
            type="hidden"
            name="order_id"
            value="<?= (int)$order['order_id'] ?>"
        >

        <button type="submit" name="status" value="delivered">
            Approve Order
        </button>

        <button
            type="submit"
            name="status"
            value="canceled"
            onclick="return confirm('Reject this order and return its reserved stock?');"
        >
            Reject Order
        </button>
    </form>
<?php else: ?>
    <p>This order is finalized. No further status changes are available.</p>
<?php endif; ?>

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
            <td><?= (int)$line['quantity'] ?></td>
            <td>₱<?= number_format((float)$line['price_each'], 2) ?></td>
            <td>
                ₱<?= number_format(
                    $line['quantity'] * $line['price_each'],
                    2
                ) ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p>Subtotal: ₱<?= number_format($subtotal, 2) ?></p>
<p>Shipping: ₱<?= number_format((float)$order['shipping_fee'], 2) ?></p>
<p><strong>Total: ₱<?= number_format($total, 2) ?></strong></p>

<a href="<?= BASE_URL ?>/user/receipt.php?order_id=<?= (int)$order['order_id'] ?>">
    <button type="button">View/Print Receipt</button>
</a>

<?php include('../includes/footer.php'); ?>