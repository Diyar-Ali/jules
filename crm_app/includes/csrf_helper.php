<?php
if (session_status() == PHP_SESSION_NONE) {
    // This should ideally be handled by session_config.php being included first
    // but as a fallback:
    $cookieParams = ['httponly' => true, 'samesite' => 'Lax'];
    if(isset($_SERVER['HTTPS'])) $cookieParams['secure'] = true;
    session_set_cookie_params($cookieParams);
    session_start();
}

/**
 * Generates a CSRF token and stores it in the session.
 * @return string The generated CSRF token.
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a given CSRF token against the one stored in the session.
 * @param string $token The CSRF token from the form submission.
 * @return bool True if the token is valid, false otherwise.
 */
function validate_csrf_token($token) {
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        // Token is valid, clear it to prevent reuse (optional, but good practice for some flows)
        // unset($_SESSION['csrf_token']); // If you want one-time tokens
        return true;
    }
    return false;
}

/**
 * Generates an HTML hidden input field with the CSRF token.
 * @return string HTML input field.
 */
function csrf_input_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Call this function at the beginning of POST request processing
 * to check CSRF token.
 */
function check_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
            // Log the attempt
            if (function_exists('log_message')) {
                log_message('error', 'CSRF token validation failed.');
            }
            // Respond with an error, redirect, or die
            // Make sure session is started to unset token if you are using one-time tokens
            // unset($_SESSION['csrf_token']); // Clear token to prevent login with back button after failure
            die('CSRF token validation failed. Please try again.');
        }
        // If you are using one-time tokens, unset it after successful validation
        // unset($_SESSION['csrf_token']);
    }
}
?>
