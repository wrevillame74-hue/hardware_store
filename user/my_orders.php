<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/guard_customer.php');


$customer_result = mysqli_execute_query($conn, "SELECT customer_id FROM customer WHERE user_id = ?", [$_SESSION['user_id']]);
$customer = mysqli_fetch_assoc($customer_result);

$orders = [];
if ($customer) {
    $orders_result = mysqli_execute_query(
        $conn,
        "SELECT order_id, order_date, status, shipping_fee FROM orderinfo WHERE customer_id = ? ORDER BY order_date DESC",
        [$customer['customer_id']]
    );
    while ($row = mysqli_fetch_assoc($orders_result)) {
        $orders[] = $row;
    }
}

include('../includes/header.php');
?>
<h1>My Orders</h1>

<?php if (!$orders): ?>
  <p>You haven't placed any orders yet. <a href="<?= BASE_URL ?>/index.php">Start shopping</a>.</p>
<?php else: ?>
  <table class="cart-table">
    <tr><th>Order #</th><th>Date</th><th>Status</th><th></th></tr>
    <?php foreach ($orders as $order): ?>
      <tr>
        <td>#<?= $order['order_id'] ?></td>
        <td><?= htmlspecialchars($order['order_date']) ?></td>
        <td><?= htmlspecialchars(ucfirst($order['status'])) ?></td>
        <td>
          <a href="<?= BASE_URL ?>/user/order_details.php?order_id=<?= $order['order_id'] ?>"><button>View Order</button></a>
          <?php if ($order['status'] === 'processing'): ?>
            <form method="post" action="<?= BASE_URL ?>/user/cancel_order.php" class="inline-form" onsubmit="return confirm('Cancel this order?');">
              <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
              <button type="submit">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php include('../includes/footer.php'); ?>