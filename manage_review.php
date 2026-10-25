
<?php
include('includes/config.php');
include('includes/alert.php');

// Only logged-in, active customers can manage reviews.
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

$action = $_POST['action'] ?? '';
$review_id = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
$csrf_token = $_POST['csrf_token'] ?? '';

if (
    !$review_id ||
    $review_id < 1 ||
    !is_string($csrf_token) ||
    empty($_SESSION['review_csrf_token']) ||
    !hash_equals($_SESSION['review_csrf_token'], $csrf_token)
) {
    set_alert('error', 'Invalid request. Please try again.');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Find the actual customer ID associated with this account.
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
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$customer_id = (int) $customer['customer_id'];

// Confirm that this review belongs to the logged-in customer.
$review_result = mysqli_execute_query(
    $conn,
    "SELECT review_id, item_id
     FROM reviews
     WHERE review_id = ? AND customer_id = ?
     LIMIT 1",
    [$review_id, $customer_id]
);

$review = mysqli_fetch_assoc($review_result);

if (!$review) {
    set_alert('error', 'Review not found or you are not allowed to manage it.');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$item_id = (int) $review['item_id'];
$redirect_url = BASE_URL . '/product_details.php?item_id=' . $item_id;

// Delete the customer's own review.
if ($action === 'delete') {
    try {
        $deleted = mysqli_execute_query(
            $conn,
            "DELETE FROM reviews
             WHERE review_id = ? AND customer_id = ?",
            [$review_id, $customer_id]
        );

        if ($deleted && mysqli_affected_rows($conn) === 1) {
            set_alert('success', 'Your review has been deleted.');
        } else {
            set_alert('error', 'The review could not be deleted.');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Review deletion failed: ' . $e->getMessage());
        set_alert('error', 'A database error occurred. Please try again.');
    }

    header('Location: ' . $redirect_url);
    exit;
}

// Edit the customer's own review.
if ($action === 'edit') {
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $review_text = trim($_POST['review_text'] ?? '');

    if (
        $rating === false ||
        $rating === null ||
        $rating < 1 ||
        $rating > 5
    ) {
        set_alert('error', 'Please select a rating from 1 to 5.');
        header('Location: ' . $redirect_url);
        exit;
    }

    if (
        $review_text === '' ||
        mb_strlen($review_text, 'UTF-8') < 5
    ) {
        set_alert('error', 'Your review must contain at least 5 characters.');
        header('Location: ' . $redirect_url);
        exit;
    }

    if (mb_strlen($review_text, 'UTF-8') > 1000) {
        set_alert('error', 'Your review cannot exceed 1,000 characters.');
        header('Location: ' . $redirect_url);
        exit;
    }

    // Basic whole-word profanity filter, consistent with submission.
    $prohibited_pattern =
        '/\b(?:fuck(?:er|ers|ed|ing|s)?|shit(?:ty|s)?|bitch(?:es)?|'
        . 'asshole(?:s)?|motherfucker(?:s)?)\b/iu';

    if (preg_match($prohibited_pattern, $review_text)) {
        set_alert('error', 'Please remove inappropriate language from your review.');
        header('Location: ' . $redirect_url);
        exit;
    }

    try {
        $updated = mysqli_execute_query(
            $conn,
            "UPDATE reviews
             SET rating = ?, review_text = ?
             WHERE review_id = ? AND customer_id = ?",
            [$rating, $review_text, $review_id, $customer_id]
        );

        if ($updated) {
            set_alert('success', 'Your review has been updated.');
        } else {
            set_alert('error', 'Your review could not be updated.');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Review update failed: ' . $e->getMessage());
        set_alert('error', 'A database error occurred. Please try again.');
    }

    header('Location: ' . $redirect_url);
    exit;
}

set_alert('error', 'Invalid review action.');
header('Location: ' . $redirect_url);
exit;
