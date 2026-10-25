<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/mailer.php';

$sent = send_mail(
    'test@email.com',
    'Test User',
    'Sturdy Supplies Mail Test',
    '<h2>Mailtrap Test</h2>
     <p>This is a test email from Sturdy Supplies.</p>'
);

echo $sent
    ? 'Email sent successfully!'
    : 'Email failed to send. Check the PHP error log.';