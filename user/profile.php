<?php
include('../includes/config.php');
include('../includes/alert.php');
include('../includes/guard_customer.php');


$userId = (int) $_SESSION['user_id'];
$errors = [];

// Profile photo upload settings.
$uploadDirectory = __DIR__ . '/../uploads/profile_photos/';
$photoPathPrefix = 'uploads/profile_photos/';
$maxFileSize = 2 * 1024 * 1024; // 2 MB

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

// Create a CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load the customer's profile.
$result = mysqli_execute_query(
    $conn,
    "SELECT fname, lname, address, contact
     FROM customer
     WHERE user_id = ?",
    [$userId]
);

$customer = $result ? mysqli_fetch_assoc($result) : null;

// Load the current profile photo.
$userResult = mysqli_execute_query(
    $conn,
    "SELECT profile_photo
     FROM users
     WHERE user_id = ?",
    [$userId]
);

$user = $userResult ? mysqli_fetch_assoc($userResult) : null;

if (!$user) {
    die('Unable to load your account.');
}

$profilePhoto = $user['profile_photo'] ?? null;

$fname = $customer['fname'] ?? '';
$lname = $customer['lname'] ?? '';
$address = $customer['address'] ?? '';
$contact = $customer['contact'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    // Validate the request token.
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    }

    // Validate the existing profile fields.
    if (
        $fname === '' ||
        $lname === '' ||
        $address === '' ||
        $contact === ''
    ) {
        $errors[] = 'Please fill in all fields.';
    }

    if (strlen($fname) > 45 || strlen($lname) > 45) {
        $errors[] = 'First and last names must not exceed 45 characters.';
    }

    if (strlen($address) > 150) {
        $errors[] = 'Address must not exceed 150 characters.';
    }

    if (
        strlen($contact) > 11 ||
        !preg_match('/^[0-9]{1,11}$/', $contact)
    ) {
        $errors[] = 'Contact number must contain only digits and be at most 11 digits long.';
    }

    // Validate an optional profile photo.
    $uploadedTempPath = null;
    $newPhotoPath = null;
    $newPhotoFilename = null;

    if (isset($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > $maxFileSize) {
                $errors[] = 'Profile photo must not exceed 2 MB.';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'Invalid uploaded file.';
            } else {
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($file['tmp_name']);

                $imageInfo = @getimagesize($file['tmp_name']);

                if (
                    !isset($allowedMimeTypes[$mimeType]) ||
                    $imageInfo === false ||
                    ($imageInfo['mime'] ?? '') !== $mimeType
                ) {
                    $errors[] = 'Please upload a valid JPEG, PNG, or WebP image.';
                } else {
                    $uploadedTempPath = $file['tmp_name'];

                    $newPhotoFilename =
                        bin2hex(random_bytes(16)) .
                        '.' .
                        $allowedMimeTypes[$mimeType];

                    $newPhotoPath = $photoPathPrefix . $newPhotoFilename;
                }
            }
        } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
            $errors[] = 'The photo upload failed. Please try again with an image under 2 MB.';
        }
    }

    // Save the profile and optional photo only if validation passed.
    if (!$errors) {
        $newFilePath = null;

        try {
            // Create the upload directory if it does not exist.
            if (
                !is_dir($uploadDirectory) &&
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                throw new Exception('Unable to create the photo upload directory.');
            }

            // Move the validated image to its permanent location.
            if ($newPhotoPath !== null) {
                $newFilePath = $uploadDirectory . $newPhotoFilename;

                if (!move_uploaded_file($uploadedTempPath, $newFilePath)) {
                    throw new Exception('Unable to save the uploaded photo.');
                }
            }

            mysqli_begin_transaction($conn);

            // Update or create the customer profile.
            if ($customer) {
                $savedCustomer = mysqli_execute_query(
                    $conn,
                    "UPDATE customer
                     SET fname = ?, lname = ?, address = ?, contact = ?
                     WHERE user_id = ?",
                    [$fname, $lname, $address, $contact, $userId]
                );
            } else {
                $savedCustomer = mysqli_execute_query(
                    $conn,
                    "INSERT INTO customer
                        (user_id, fname, lname, address, contact)
                     VALUES (?, ?, ?, ?, ?)",
                    [$userId, $fname, $lname, $address, $contact]
                );
            }

            if (!$savedCustomer) {
                throw new Exception('Unable to save your profile information.');
            }

            // Update the photo path only when a new photo was uploaded.
            if ($newPhotoPath !== null) {
                $savedPhoto = mysqli_execute_query(
                    $conn,
                    "UPDATE users
                     SET profile_photo = ?
                     WHERE user_id = ?",
                    [$newPhotoPath, $userId]
                );

                if (!$savedPhoto) {
                    throw new Exception('Unable to save your profile photo.');
                }
            }

            mysqli_commit($conn);

            // Remove the previous photo only after the database update succeeds.
            if (
                $newPhotoPath !== null &&
                is_string($profilePhoto) &&
                str_starts_with($profilePhoto, $photoPathPrefix)
            ) {
                $oldFilename = basename($profilePhoto);
                $oldFilePath = $uploadDirectory . $oldFilename;

                if (
                    $oldFilename !== '' &&
                    is_file($oldFilePath)
                ) {
                    @unlink($oldFilePath);
                }
            }

            set_alert('success', 'Profile updated successfully.');

            $redirect = $_SESSION['redirect_after_login']
                ?? (BASE_URL . '/user/profile.php');

            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirect);
            exit;
        } catch (Throwable $e) {
            try {
                mysqli_rollback($conn);
            } catch (Throwable $rollbackError) {
                // Preserve the original error.
            }

            // Remove a newly uploaded file if the database save failed.
            if ($newFilePath !== null && is_file($newFilePath)) {
                @unlink($newFilePath);
            }

            error_log('Profile update failed: ' . $e->getMessage());

            $errors[] = 'Unable to update your profile. Please try again.';
        }
    }
}

// Only display a photo path from the expected upload directory.
$photoUrl = null;

if (
    is_string($profilePhoto) &&
    str_starts_with($profilePhoto, $photoPathPrefix) &&
    basename($profilePhoto) !== ''
) {
    $photoUrl = BASE_URL . '/' . $profilePhoto;
}

include('../includes/header.php');
?>

<div class="auth-box">
    <h1>My Profile</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endforeach; ?>

    <div style="text-align: center; margin-bottom: 24px;">
        <?php if ($photoUrl !== null): ?>
            <img
                src="<?= htmlspecialchars($photoUrl) ?>"
                alt="Profile photo"
                width="140"
                height="140"
                style="width: 140px; height: 140px; object-fit: cover; border-radius: 50%;"
            >
        <?php else: ?>
            <div
                style="width: 140px; height: 140px; margin: 0 auto; border-radius: 50%; background: #eee; display: flex; align-items: center; justify-content: center;"
            >
                No photo
            </div>
        <?php endif; ?>
    </div>

    <form
        method="post"
        action="<?= BASE_URL ?>/user/profile.php"
        enctype="multipart/form-data"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
        >

        <label for="profile_photo">Profile Photo</label>
        <input
            type="file"
            id="profile_photo"
            name="profile_photo"
            accept="image/jpeg,image/png,image/webp"
        >
        <small>JPEG, PNG, or WebP. Maximum file size: 2 MB.</small>

        <label>
            First Name
            <input
                type="text"
                name="fname"
                value="<?= htmlspecialchars($fname) ?>"
                maxlength="45"
                required
            >
        </label>

        <label>
            Last Name
            <input
                type="text"
                name="lname"
                value="<?= htmlspecialchars($lname) ?>"
                maxlength="45"
                required
            >
        </label>

        <label>
            Address
            <input
                type="text"
                name="address"
                value="<?= htmlspecialchars($address) ?>"
                maxlength="150"
                required
            >
        </label>

        <label>
            Contact Number
            <input
                type="text"
                name="contact"
                value="<?= htmlspecialchars($contact) ?>"
                required
                maxlength="11"
                inputmode="numeric"
            >
        </label>

        <button type="submit">Save Changes</button>
    </form>

    <p>
        <a href="<?= BASE_URL ?>/user/change_password.php">
            Change Password
        </a>
    </p>
</div>

<?php include('../includes/footer.php'); ?>
