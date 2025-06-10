<?php
// Set session cookie parameters for security
$cookieParams = [
    'lifetime' => 0, // Expires when browser closes
    'path' => '/',
    'domain' => '', // Current domain
    'secure' => isset($_SERVER['HTTPS']), // Only send over HTTPS
    'httponly' => true, // Prevent JavaScript access to session cookie
    'samesite' => 'Lax' // CSRF protection: Lax or Strict
];
session_set_cookie_params($cookieParams);

// Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Session regeneration logic to prevent session fixation
// Regenerate session ID every 30 minutes
$session_regeneration_interval = 30 * 60; // 30 minutes in seconds
if (!isset($_SESSION['last_session_regenerated'])) {
    $_SESSION['last_session_regenerated'] = time();
}

if (time() - $_SESSION['last_session_regenerated'] > $session_regeneration_interval) {
    session_regenerate_id(true); // true to delete old session file
    $_SESSION['last_session_regenerated'] = time();
}

// Basic session timeout (e.g., 1 hour of inactivity)
// This is optional and can be more complex if needed
$session_activity_timeout = 60 * 60; // 1 hour in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_activity_timeout)) {
    session_unset();     // Unset $_SESSION variable for the run-time
    session_destroy();   // Destroy session data in storage
    // Optionally redirect to login or show a message
    // header('Location: login.php?message=session_expired');
    // exit;
}
$_SESSION['last_activity'] = time(); // Update last activity time stamp

?>
