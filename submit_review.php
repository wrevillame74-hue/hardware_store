
<?php
include('includes/config.php');
include('includes/alert.php');

// Only customers may submit reviews.
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'customer'
) {
    header('Location: ' . BASE_URL . '/user/login.php');
    exit;
}

include('includes/guard_customer.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
$review_text = trim($_POST['review_text'] ?? '');
$csrf_token = $_POST['csrf_token'] ?? '';

function return_to_product(int $item_id): void
{
    header('Location: ' . BASE_URL . '/product_details.php?item_id=' . $item_id);
    exit;
}

// Validate the product ID before using it in a redirect.
if (!$item_id || $item_id < 1) {
    set_alert('error', 'Invalid product.');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Verify the CSRF token.
if (
    empty($_SESSION['review_csrf_token']) ||
    !is_string($csrf_token) ||
    !hash_equals($_SESSION['review_csrf_token'], $csrf_token)
) {
    set_alert('error', 'Your session token is invalid. Please try again.');
    return_to_product($item_id);
}

// Validate rating.
if ($rating === false || $rating === null || $rating < 1 || $rating > 5) {
    set_alert('error', 'Please select a rating from 1 to 5 stars.');
    return_to_product($item_id);
}

// Validate review text.
if ($review_text === '' || mb_strlen($review_text, 'UTF-8') < 5) {
    set_alert('error', 'Your review must contain at least 5 characters.');
    return_to_product($item_id);
}

if (mb_strlen($review_text, 'UTF-8') > 1000) {
    set_alert('error', 'Your review cannot exceed 1,000 characters.');
    return_to_product($item_id);
}

// Basic whole-word profanity filter.
// Adjust this list to match your project's requirements.
$prohibited_pattern =
    '/\b(?:fuck(?:er|ers|ed|ing|s)?|shit(?:ty|s)?|bitch(?:es)?|'
    . 'asshole(?:s)?|motherfucker(?:s)?)\b/iu';

if (preg_match($prohibited_pattern, $review_text)) {
    set_alert('error', 'Please remove inappropriate language from your review.');
    return_to_product($item_id);
}

// Verify the product exists and is visible.
$product_result = mysqli_execute_query(
    $conn,
    "SELECT item_id
     FROM item
     WHERE item_id = ? AND is_hidden = 0
     LIMIT 1",
    [$item_id]
);

if (!mysqli_fetch_assoc($product_result)) {
    set_alert('error', 'This product is unavailable for review.');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Get the actual customer ID linked to the logged-in user.
$customer_result = mysqli_execute_query(
    $conn,
    "SELECT customer_id
     FROM customer
     WHERE user_id = ?
     LIMIT 1",
    [(int) $_SESSION['user_id']]
);

$customer = mysqli_fetch_assoc($customer_result);

if (!$customer) {
    set_alert('error', 'Your customer profile could not be found.');
    return_to_product($item_id);
}

$customer_id = (int) $customer['customer_id'];

// Only customers with a delivered order containing this product qualify.
$purchase_result = mysqli_execute_query(
    $conn,
    "SELECT 1
     FROM orderinfo AS o
     JOIN orderline AS ol ON ol.order_id = o.order_id
     WHERE o.customer_id = ?
       AND ol.item_id = ?
       AND o.status = 'delivered'
     LIMIT 1",
    [$customer_id, $item_id]
);

if (!mysqli_fetch_assoc($purchase_result)) {
    set_alert(
        'error',
        'You can review this product only after receiving it.'
    );
    return_to_product($item_id);
}

// Prevent duplicate reviews.
$existing_result = mysqli_execute_query(
    $conn,
    "SELECT review_id
     FROM reviews
     WHERE customer_id = ? AND item_id = ?
     LIMIT 1",
    [$customer_id, $item_id]
);

if (mysqli_fetch_assoc($existing_result)) {
    set_alert(
        'error',
        'You have already reviewed this product. Editing reviews will be added next.'
    );
    return_to_product($item_id);
}

// Save the review using a prepared statement.
try {
    $insert_result = mysqli_execute_query(
        $conn,
        "INSERT INTO reviews
            (item_id, customer_id, rating, review_text)
         VALUES (?, ?, ?, ?)",
        [$item_id, $customer_id, $rating, $review_text]
    );

    if ($insert_result) {
        set_alert('success', 'Your review has been submitted successfully.');
    } else {
        set_alert('error', 'Unable to submit your review. Please try again.');
    }
} catch (mysqli_sql_exception $e) {
    // Handles a duplicate submission if two requests arrive together.
    if ((int) $e->getCode() === 1062) {
        set_alert('error', 'You have already reviewed this product.');
    } else {
        error_log('Review submission failed: ' . $e->getMessage());
        set_alert('error', 'A database error occurred. Please try again.');
    }
}

return_to_product($item_id);
