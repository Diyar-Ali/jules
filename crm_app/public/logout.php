<?php
// crm_app/public/logout.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/session_config.php'; // Ensures session is started
require_once $base_path . 'includes/log_helper.php';   // Logging

$user_id_log = $_SESSION['user_id'] ?? null;
$username_log = $_SESSION['username'] ?? 'Unknown';

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

log_message('INFO', "User '{$username_log}' (ID: {$user_id_log}) logged out.", $user_id_log);

// Redirect to login page with a message
header("Location: login.php?message=You have been logged out successfully.");
exit;
?>
