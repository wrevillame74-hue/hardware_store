<?php
include('../includes/config.php');
include('../includes/alert.php');

$_SESSION = [];
session_destroy();

session_start();
set_alert('success', 'You have been logged out.');

header('Location: ' . BASE_URL . '/user/login.php');
exit;