<?php
// crm_app/includes/csrf_helper.php

// Ensure session_config.php is included and session is started
if (session_status() == PHP_SESSION_NONE) {
    // Attempt to include it from a relative path
    $session_config_path = __DIR__ . '/session_config.php';
    if (file_exists($session_config_path)) {
        require_once $session_config_path;
    } else {
        // Fallback for different include depths
        if (file_exists('../includes/session_config.php')) {
             require_once '../includes/session_config.php';
        } else if (file_exists('includes/session_config.php')) {
             require_once 'includes/session_config.php';
        } else {
            die('Session configuration for CSRF not found. Critical error.');
        }
    }
}

// Generate a CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate a CSRF token
function validate_csrf_token($token_from_form) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_from_form)) {
        // Token is invalid or not set
        // Log the attempt for security monitoring
        // log_message('WARNING', 'CSRF token validation failed.'); // Assuming log_helper is available
        return false;
    }
    // Token is valid, unset it to prevent reuse (optional, but good practice for some scenarios)
    // If you want tokens to be valid for the entire session lifetime until regenerated, don't unset.
    // For single-use tokens per form submission:
    // unset($_SESSION['csrf_token']);
    return true;
}

// Function to output CSRF token field in forms
function csrf_input_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// Usage:
// 1. In your form: echo csrf_input_field();
// 2. In your form processing script (e.g., on POST):
//    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//        if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
//            die('CSRF token validation failed. Access denied.');
//            // Or redirect to an error page, log the event, etc.
//        }
//        // Proceed with form processing
//    }
?>
