<?php
// crm_app/public/index.php
// This acts as the main entry point to the application.

// Start session handling early
// It's important session_config.php is robust enough to be included multiple times
// or a check like `if (session_status() == PHP_SESSION_NONE)` is used before `session_start()`.
// Assuming session_config.php handles this.
$base_path = __DIR__ . '/../'; // Define base_path relative to current file
require_once $base_path . 'includes/session_config.php';

// If user is logged in, redirect to dashboard.
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
} else {
    // If not logged in, redirect to login page.
    header("Location: login.php");
    exit;
}
?>
