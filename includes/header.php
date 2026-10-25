<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sturdy Supplies</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

<style>
  
nav {
  display: flex;
  align-items: center;
}

.nav-user {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  line-height: normal;
}

.nav-profile-photo {
  display: block;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}

</style>
</head>
<body>
<header class="site-header">
  <a class="brand" href="<?= BASE_URL ?>/index.php">Sturdy Supplies</a>

  <nav>
    <a href="<?= BASE_URL ?>/index.php">Shop</a>
    <a href="<?= BASE_URL ?>/view_cart.php">Cart</a>

    <?php if (!empty($_SESSION['user_id'])): ?>

      <a href="<?= BASE_URL ?>/user/my_orders.php">My Orders</a>

      <?php
      // Fetch the current user's profile photo.
      $navPhotoResult = mysqli_execute_query(
          $conn,
          "SELECT profile_photo FROM users WHERE user_id = ?",
          [(int) $_SESSION['user_id']]
      );

      $navUser = $navPhotoResult
          ? mysqli_fetch_assoc($navPhotoResult)
          : null;

      $navPhoto = $navUser['profile_photo'] ?? '';
      $navPhotoPrefix = 'uploads/profile_photos/';
      $navPhotoUrl = null;

      if (
          is_string($navPhoto) &&
          str_starts_with($navPhoto, $navPhotoPrefix) &&
          basename($navPhoto) !== ''
      ) {
          $navPhotoUrl = BASE_URL . '/' . $navPhoto;
      }
      ?>

      <!-- Photo and My Profile are ONE link -->
      <a class="nav-user" href="<?= BASE_URL ?>/user/profile.php">
        <?php if ($navPhotoUrl !== null): ?>
          <img
            class="nav-profile-photo"
            src="<?= htmlspecialchars($navPhotoUrl) ?>"
            alt="Profile photo"
            width="32"
            height="32"
          >
        <?php endif; ?>
        <span>My Profile</span>
      </a>

      <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a href="<?= BASE_URL ?>/admin/index.php">Admin</a>
      <?php endif; ?>

      <!-- Logout remains a separate link -->
      <a href="<?= BASE_URL ?>/user/logout.php">
        Logout (<?= htmlspecialchars($_SESSION['username'] ?? '') ?>)
      </a>

    <?php else: ?>
      <a href="<?= BASE_URL ?>/user/login.php">Login</a>
      <a href="<?= BASE_URL ?>/user/register.php">Register</a>
    <?php endif; ?>
  </nav>
</header>

<main class="site-main">
<?php show_alert(); ?>