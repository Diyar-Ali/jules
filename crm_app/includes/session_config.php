<?php
// crm_app/includes/session_config.php

// Set session cookie parameters for security
$cookieParams = [
    'lifetime' => 0, // 0 means until browser is closed
    'path' => '/',
    'domain' => '', // Current domain
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // Send only over HTTPS
    'httponly' => true, // Prevent JavaScript access to session cookie
    'samesite' => 'Lax' // CSRF protection: Lax or Strict
];

session_set_cookie_params($cookieParams);

// Start the session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Regenerate session ID periodically to prevent session fixation
// For example, regenerate every 30 minutes
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes = 1800 seconds
    session_regenerate_id(true); // true to delete old session file
    $_SESSION['last_regeneration'] = time();
}

?>
