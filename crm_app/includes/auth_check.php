<?php
// crm_app/includes/auth_check.php

// This script should be included at the top of pages that require authentication.

// Ensure session_config.php is included and session is started
if (session_status() == PHP_SESSION_NONE) {
    // Attempt to include it from a relative path, adjust if necessary
    // This assumes auth_check.php is in includes/ and session_config.php is also in includes/
    $session_config_path = __DIR__ . '/session_config.php';
    if (file_exists($session_config_path)) {
        require_once $session_config_path;
    } else {
        // Fallback if structure is different or if called from a different depth
        // This is a simple fallback, a more robust solution might be needed
        // depending on your include strategy (e.g., a central bootstrap file)
        if (file_exists('../includes/session_config.php')) {
             require_once '../includes/session_config.php';
        } else if (file_exists('includes/session_config.php')) {
             require_once 'includes/session_config.php';
        } else {
            die('Session configuration not found. Critical error.');
        }
    }
}


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // User is not logged in, redirect to login page
    // Store the intended destination to redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];

    // Adjust path to login.php based on where auth_check.php is included from
    // This simple check might need to be more robust depending on project structure
    $login_page_path = 'login.php'; // Assumes login.php is in the same directory as the script including this
    if (file_exists('public/login.php')) { // If included from root
        $login_page_path = 'public/login.php';
    } else if (basename(dirname($_SERVER['PHP_SELF'])) === 'public') { // If included from a file within public/
         $login_page_path = 'login.php';
    } else {
        // Fallback or more sophisticated path detection might be needed
        // For now, assume login.php is accessible from the current request URI's directory or a common public path
        // This is a common issue with include-based routing.
        // A front controller pattern often simplifies this.
        // Let's try a relative path that often works if files are in 'public'
        $public_path_prefix = (basename(getcwd()) === 'public') ? '' : '../';
        if (file_exists($public_path_prefix . 'login.php')) {
            $login_page_path = $public_path_prefix . 'login.php';
        } else {
            // Default if structure is hard to guess, assumes login.php is in root or current dir
            $login_page_path = 'login.php';
            // A more robust solution would be a global constant for the app base URL/path
            // define('BASE_URL', '/crm_app/public/'); header('Location: ' . BASE_URL . 'login.php');
        }
    }

    // Check if we are already on login.php to prevent redirect loop
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header("Location: " . $login_page_path . "?message=Please login to access this page.");
        exit;
    }
}

// Function to check if the logged-in user is an admin
function is_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

// Example usage for admin-only pages:
// if (!is_admin()) {
//     // Optionally, log this attempt or show a specific message
//     log_message('WARNING', 'Non-admin user tried to access admin page.', $_SESSION['user_id'] ?? null);
//     header("Location: dashboard.php?error=Access denied. Admin rights required."); // Or an access_denied.php page
//     exit;
// }
?>
