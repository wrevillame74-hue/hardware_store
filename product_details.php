
<?php
include('includes/config.php');
include('includes/alert.php');

// Generate a CSRF token for review forms.
if (empty($_SESSION['review_csrf_token'])) {
    $_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
}

// Identify the logged-in customer.
$current_customer_id = null;

if (
    !empty($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'customer'
) {
    include('includes/guard_customer.php');

    $customer_stmt = mysqli_prepare($conn, "
        SELECT customer_id
        FROM customer
        WHERE user_id = ?
        LIMIT 1
    ");

    mysqli_stmt_bind_param(
        $customer_stmt,
        'i',
        $_SESSION['user_id']
    );

    mysqli_stmt_execute($customer_stmt);
    $customer_result = mysqli_stmt_get_result($customer_stmt);
    $customer = mysqli_fetch_assoc($customer_result);
    mysqli_stmt_close($customer_stmt);

    if ($customer) {
        $current_customer_id = (int) $customer['customer_id'];
    }
}

$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);

if (!$item_id || $item_id < 1) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Fetch product details, category, and stock.
$stmt = mysqli_prepare($conn, "
    SELECT
        i.item_id,
        i.item_name,
        i.description,
        i.price,
        i.image_path,
        c.category_name,
        s.quantity
    FROM item AS i
    JOIN category AS c ON c.category_id = i.category_id
    JOIN stock AS s ON s.item_id = i.item_id
    WHERE i.item_id = ?
      AND i.is_hidden = 0
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, 'i', $item_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$item = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$item) {
    http_response_code(404);
    include('includes/header.php');
    echo '<h2>Product not found</h2>';
    echo '<p>This product may no longer be available.</p>';
    echo '<p><a href="' . BASE_URL . '/index.php">Back to Shop</a></p>';
    include('includes/footer.php');
    exit;
}

// Check purchase eligibility and whether the customer has reviewed this product.
$can_review = false;
$already_reviewed = false;

if ($current_customer_id !== null) {
    $purchase_result = mysqli_execute_query(
        $conn,
        "SELECT 1
         FROM orderinfo AS o
         JOIN orderline AS ol ON ol.order_id = o.order_id
         WHERE o.customer_id = ?
           AND ol.item_id = ?
           AND o.status = 'delivered'
         LIMIT 1",
        [$current_customer_id, $item_id]
    );

    $has_delivered_order = (bool) mysqli_fetch_assoc($purchase_result);

    $existing_review_result = mysqli_execute_query(
        $conn,
        "SELECT review_id
         FROM reviews
         WHERE customer_id = ? AND item_id = ?
         LIMIT 1",
        [$current_customer_id, $item_id]
    );

    $already_reviewed = (bool) mysqli_fetch_assoc($existing_review_result);
    $can_review = $has_delivered_order && !$already_reviewed;
}

// Fetch existing reviews and reviewer names, including review IDs and owners.
$stmt = mysqli_prepare($conn, "
    SELECT
        r.review_id,
        r.customer_id,
        r.rating,
        r.review_text,
        r.created_at,
        r.updated_at,
        c.fname,
        c.lname
    FROM reviews AS r
    JOIN customer AS c ON c.customer_id = r.customer_id
    WHERE r.item_id = ?
    ORDER BY r.created_at DESC
");

mysqli_stmt_bind_param($stmt, 'i', $item_id);
mysqli_stmt_execute($stmt);
$reviews_result = mysqli_stmt_get_result($stmt);

$reviews = [];
$total_rating = 0;

while ($review = mysqli_fetch_assoc($reviews_result)) {
    $reviews[] = $review;
    $total_rating += (int) $review['rating'];
}

mysqli_stmt_close($stmt);

$review_count = count($reviews);
$average_rating = $review_count > 0
    ? $total_rating / $review_count
    : 0;

include('includes/header.php');
?>

<div class="product-details">
    <p><a href="<?= BASE_URL ?>/index.php">← Back to Shop</a></p>

    <div class="product-details-main">
        <img
            src="<?= BASE_URL ?>/item/images/<?= htmlspecialchars($item['image_path']) ?>"
            alt="<?= htmlspecialchars($item['item_name']) ?>"
            style="max-width: 300px; width: 100%; height: auto;"
        >

        <div>
            <h1><?= htmlspecialchars($item['item_name']) ?></h1>

            <p>
                Category:
                <?= htmlspecialchars($item['category_name']) ?>
            </p>

            <h2>₱<?= number_format((float) $item['price'], 2) ?></h2>

            <p>
                <?= nl2br(htmlspecialchars(
                    $item['description'] ?? 'No description available.'
                )) ?>
            </p>

            <?php if ((int) $item['quantity'] > 0): ?>
                <p>Stock available: <?= (int) $item['quantity'] ?></p>

                <form method="post" action="<?= BASE_URL ?>/cart_update.php">
                    <input type="hidden" name="action" value="add">
                    <input
                        type="hidden"
                        name="item_id"
                        value="<?= (int) $item['item_id'] ?>"
                    >
                    <label for="quantity">Quantity:</label>
                    <input
                        id="quantity"
                        type="number"
                        name="quantity"
                        value="1"
                        min="1"
                        max="<?= (int) $item['quantity'] ?>"
                        required
                    >
                    <button type="submit">Add to Cart</button>
                </form>
            <?php else: ?>
                <p class="out-of-stock">Out of Stock</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<hr>

<section class="product-reviews">
    <h2>Customer Reviews</h2>

    <?php if ($can_review): ?>
        <div class="review-form">
            <h3>Write a Review</h3>

            <form method="post" action="<?= BASE_URL ?>/submit_review.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['review_csrf_token']) ?>"
                >
                <input
                    type="hidden"
                    name="item_id"
                    value="<?= (int) $item['item_id'] ?>"
                >

                <label for="rating">Your Rating:</label>
                <select id="rating" name="rating" required>
                    <option value="">Select a rating</option>
                    <option value="5">5 - Excellent</option>
                    <option value="4">4 - Very Good</option>
                    <option value="3">3 - Good</option>
                    <option value="2">2 - Fair</option>
                    <option value="1">1 - Poor</option>
                </select>

                <label for="review_text">Your Review:</label>
                <textarea
                    id="review_text"
                    name="review_text"
                    rows="5"
                    maxlength="1000"
                    placeholder="Share your experience with this product..."
                    required
                ></textarea>

                <button type="submit">Submit Review</button>
            </form>
        </div>

    <?php elseif ($already_reviewed): ?>
        <p>You have already reviewed this product. You can manage your review below.</p>

    <?php elseif ($current_customer_id !== null): ?>
        <p>You can review this product after purchasing and receiving it.</p>

    <?php else: ?>
        <p>
            <a href="<?= BASE_URL ?>/user/login.php">Log in as a customer</a>
            to check your review eligibility.
        </p>
    <?php endif; ?>

    <hr>

    <?php if ($review_count > 0): ?>
        <p>
            Average rating:
            <strong><?= number_format($average_rating, 1) ?>/5</strong>
            (<?= $review_count ?>
            <?= $review_count === 1 ? 'review' : 'reviews' ?>)
        </p>

        <?php foreach ($reviews as $review): ?>
            <?php
            $is_own_review = (
                $current_customer_id !== null &&
                (int) $review['customer_id'] === $current_customer_id
            );
            ?>

            <article class="review">
                <h3>
                    <?= htmlspecialchars(
                        trim($review['fname'] . ' ' . $review['lname'])
                    ) ?>
                </h3>

                <p>
                    <strong>
                        <?= str_repeat('★', (int) $review['rating']) ?>
                        <?= str_repeat('☆', 5 - (int) $review['rating']) ?>
                    </strong>
                    (<?= (int) $review['rating'] ?>/5)
                </p>

                <p>
                    <?= nl2br(htmlspecialchars($review['review_text'])) ?>
                </p>

                <small>
                    Posted: <?= htmlspecialchars($review['created_at']) ?>

                    <?php if ($review['updated_at'] !== $review['created_at']): ?>
                        (edited)
                    <?php endif; ?>
                </small>

                <?php if ($is_own_review): ?>
                    <details class="edit-review">
                        <summary>Edit Review</summary>

                        <form method="post" action="<?= BASE_URL ?>/manage_review.php">
                            <input type="hidden" name="action" value="edit">
                            <input
                                type="hidden"
                                name="review_id"
                                value="<?= (int) $review['review_id'] ?>"
                            >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['review_csrf_token']) ?>"
                            >

                            <label for="rating-<?= (int) $review['review_id'] ?>">
                                Rating:
                            </label>
                            <select
                                id="rating-<?= (int) $review['review_id'] ?>"
                                name="rating"
                                required
                            >
                                <?php for ($star = 5; $star >= 1; $star--): ?>
                                    <option
                                        value="<?= $star ?>"
                                        <?= (int) $review['rating'] === $star ? 'selected' : '' ?>
                                    >
                                        <?= $star ?> star<?= $star === 1 ? '' : 's' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>

                            <label for="review-text-<?= (int) $review['review_id'] ?>">
                                Review:
                            </label>
                            <textarea
                                id="review-text-<?= (int) $review['review_id'] ?>"
                                name="review_text"
                                rows="4"
                                maxlength="1000"
                                required
                            ><?= htmlspecialchars($review['review_text']) ?></textarea>

                            <button type="submit">Save Changes</button>
                        </form>
                    </details>

                    <form
                        method="post"
                        action="<?= BASE_URL ?>/manage_review.php"
                        onsubmit="return confirm('Are you sure you want to delete this review?');"
                    >
                        <input type="hidden" name="action" value="delete">
                        <input
                            type="hidden"
                            name="review_id"
                            value="<?= (int) $review['review_id'] ?>"
                        >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['review_csrf_token']) ?>"
                        >

                        <button type="submit">Delete Review</button>
                    </form>
                <?php endif; ?>
            </article>
            <hr>
        <?php endforeach; ?>

    <?php else: ?>
        <p>No reviews yet. Be the first eligible customer to review this product!</p>
    <?php endif; ?>
</section>

<?php include('includes/footer.php'); ?>
