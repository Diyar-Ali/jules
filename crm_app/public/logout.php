<?php
// logout.php

// Attempt to load .env if session_config.php hasn't already.
// This is to ensure APP_URL is available for constructing the login URL.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }
}

// It's crucial session_config.php is loaded first to properly manage session destruction
// and cookie parameters.
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/log_helper.php'; // For logging

$user_id_for_log = $_SESSION['user_id'] ?? 'N/A';
$username_for_log = $_SESSION['username'] ?? 'N/A';

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session cookie
// Note: session_config.php sets secure cookie params, so this should respect them.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session
session_destroy();

log_message('info', 'User logged out successfully.', ['user_id' => $user_id_for_log, 'username' => $username_for_log]);

// 4. Redirect to login page with a success message
// Construct login URL carefully using APP_URL
$app_url_path = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$login_url = $app_url_path . '/login.php?logout=success';
if (!str_starts_with($login_url, '/')) {
    $login_url = '/' . $login_url; // Ensure it's an absolute path from web root
}

header("Location: " . $login_url);
exit;
?>
