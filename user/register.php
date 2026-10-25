<?php
include('../includes/config.php');
include('../includes/alert.php');
require_once('../includes/mailer.php');

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$errors = [];
$username = $email = $fname = $lname = $address = $contact = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $fname    = trim($_POST['fname'] ?? '');
    $lname    = trim($_POST['lname'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $contact  = trim($_POST['contact'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $fname === '' || $lname === '' || $address === '' || $contact === '') {
        $errors[] = 'Please fill in all fields.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    if (!$errors) {
        $existing_username = mysqli_execute_query(
    $conn,
    "SELECT user_id FROM users WHERE username = ?",
    [$username]
);

if (mysqli_fetch_assoc($existing_username)) {
    $errors[] = 'That username is already taken.';
}

$existing_email = mysqli_execute_query(
    $conn,
    "SELECT user_id FROM users WHERE email = ?",
    [$email]
);

if (mysqli_fetch_assoc($existing_email)) {
    $errors[] = 'That email is already registered.';
}
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        mysqli_begin_transaction($conn);
        try {
           $verification_token = bin2hex(random_bytes(32));

mysqli_execute_query(
    $conn,
    "INSERT INTO users (username, email, password, role, email_verified, verification_token) 
     VALUES (?, ?, ?, 'customer', 0, ?)",
    [$username, $email, $hash, $verification_token]
);
            $user_id = mysqli_insert_id($conn);
            mysqli_execute_query($conn, "INSERT INTO customer (user_id, fname, lname, address, contact) VALUES (?, ?, ?, ?, ?)", [$user_id, $fname, $lname, $address, $contact]);
            mysqli_commit($conn);

$verification_link = BASE_URL . '/user/verify_email.php?token=' . urlencode($verification_token);

$email_body = '
    <h2>Welcome to Sturdy Supplies!</h2>
    <p>Hello ' . htmlspecialchars($fname) . ',</p>
    <p>Thank you for creating an account with Sturdy Supplies.</p>
    <p>Please click the link below to verify your email address:</p>
    <p>
        <a href="' . htmlspecialchars($verification_link, ENT_QUOTES, 'UTF-8') . '"
           style="display:inline-block;padding:12px 20px;background:#DC2626;color:#ffffff;text-decoration:none;border-radius:5px;">
            Verify My Email
        </a>
    </p>
    <p>If you did not create this account, you can ignore this email.</p>
';

if (send_mail($email, $fname . ' ' . $lname, 'Verify Your Sturdy Supplies Account', $email_body)) {
    set_alert('success', 'Account created! Please check your email to verify your account.');
} else {
    set_alert('error', 'Your account was created, but the verification email could not be sent. Please contact the administrator.');
}

header('Location: ' . BASE_URL . '/user/login.php');
exit;
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

include('../includes/header.php');
?>
<div class="auth-box">
<h1>Create an Account</h1>

<?php foreach ($errors as $error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endforeach; ?>

<form method="post" action="">
  <label>Username
    <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" required>
  </label>
  <label>Email
    <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
  </label>
  <label>Password
    <input type="password" name="password" required minlength="6">
  </label>
  <label>Confirm Password
    <input type="password" name="confirm_password" required minlength="6">
  </label>
  <label>First Name
    <input type="text" name="fname" value="<?= htmlspecialchars($fname) ?>" required>
  </label>
  <label>Last Name
    <input type="text" name="lname" value="<?= htmlspecialchars($lname) ?>" required>
  </label>
  <label>Address
    <input type="text" name="address" value="<?= htmlspecialchars($address) ?>" required>
  </label>
  <label>Contact Number
    <input type="text" name="contact" value="<?= htmlspecialchars($contact) ?>" required maxlength="11">
  </label>
  <button type="submit">Register</button>
</form>

<p>Already have an account? <a href="<?= BASE_URL ?>/user/login.php">Log in</a></p>
</div>
<?php include('../includes/footer.php'); ?>