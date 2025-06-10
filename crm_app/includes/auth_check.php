<?php
// This file should be included at the top of all protected pages.
// Ensure session_config.php is included before this file,
// or ensure session is started.
if (session_status() == PHP_SESSION_NONE) {
    // Fallback if session_config.php wasn't included or didn't start session
    $cookieParams = ['httponly' => true, 'samesite' => 'Lax'];
    if(isset($_SERVER['HTTPS'])) $cookieParams['secure'] = true;
    session_set_cookie_params($cookieParams);
    session_start();
}

// Variables to hold user data from session, default to null if not set
$current_user_id = $_SESSION['user_id'] ?? null;
$current_username = $_SESSION['username'] ?? null;
$current_user_role = $_SESSION['role'] ?? null;
$current_is_admin = $_SESSION['is_admin'] ?? false;

// Define array of pages that do not require authentication
// Typically login page, password reset, etc.
// Note: Adjust APP_URL if your app is in a subdirectory
$app_url_path = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '';
if (str_ends_with($app_url_path, '/')) { // Ensure no double slashes
    $app_url_path = rtrim($app_url_path, '/');
}

$public_pages = [
    $app_url_path . '/login.php',
    // Add other public pages like password_reset.php if they exist
];

// Get the current script's path relative to the web root
$current_page = $_SERVER['PHP_SELF'];

if (!isset($current_user_id)) {
    // User is not logged in
    if (!in_array($current_page, $public_pages)) {
        // If trying to access a protected page, store intended URL and redirect to login
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];

        // Construct login URL carefully
        // If APP_URL is http://localhost/crm_app/public
        // login.php should be at /crm_app/public/login.php
        $login_url = rtrim($app_url_path, '/') . '/login.php';
        if (!str_starts_with($login_url, '/')) {
            $login_url = '/' . $login_url; // Ensure it's an absolute path from web root
        }

        header("Location: " . $login_url);
        exit;
    }
} else {
    // User is logged in
    // If user is logged in and tries to access login.php, redirect to dashboard
    if ($current_page === $app_url_path . '/login.php') {
        $dashboard_url = rtrim($app_url_path, '/') . '/index.php'; // Assuming index.php is dashboard
         if (!str_starts_with($dashboard_url, '/')) {
            $dashboard_url = '/' . $dashboard_url;
        }
        header("Location: " . $dashboard_url);
        exit;
    }
}

/**
 * Checks if the current user is an admin.
 * This function relies on $current_is_admin being populated by auth_check.php.
 * It also includes a check directly against the session for robustness,
 * though $current_is_admin should be authoritative if auth_check.php is included.
 *
 * @return bool True if the user is an admin, false otherwise.
 */
function is_admin() {
    global $current_is_admin; // Use the global variable populated in this file
    return $current_is_admin === true || (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);
}

/**
 * Restricts access to a page to admin users only.
 * If the user is not an admin, it can redirect them or show an error.
 *
 * @param string $redirect_url Optional URL to redirect non-admins.
 *                             If empty, shows a generic error message and exits.
 */
function require_admin($redirect_url = '') {
    if (!is_admin()) {
        if (function_exists('log_message')) {
            log_message('warning', 'Non-admin user attempted to access admin-only page: ' . $_SERVER['PHP_SELF']);
        }
        if (!empty($redirect_url)) {
            header("Location: " . $redirect_url);
        } else {
            // Consider a more user-friendly error page in a real application
            http_response_code(403); // Forbidden
            die('Access Denied. You do not have permission to view this page.');
        }
        exit;
    }
}
?>
