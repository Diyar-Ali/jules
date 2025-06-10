<?php
// crm_app/public/login.php

// Attempt to load necessary files using relative paths
// This assumes login.php is in public/ and other dirs are siblings
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/session_config.php'; // Starts session
require_once $base_path . 'config/db.php';             // PDO connection ($pdo)
require_once $base_path . 'classes/User.php';          // User class
require_once $base_path . 'includes/csrf_helper.php';  // CSRF functions
require_once $base_path . 'includes/log_helper.php';   // Logging

$user_handler = new User($pdo);
$error_message = '';
$success_message = '';

if (isset($_GET['message'])) {
    $success_message = htmlspecialchars($_GET['message']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Access denied.';
        log_message('WARNING', 'CSRF validation failed for login attempt.');
    } elseif (empty($_POST['username']) || empty($_POST['password'])) {
        $error_message = 'Username and password are required.';
    } else {
        $username = $_POST['username'];
        $password = $_POST['password'];

        $login_result = $user_handler->login($username, $password);

        if ($login_result['success']) {
            $logged_in_user = $login_result['user'];

            // Regenerate session ID upon successful login to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time(); // Reset regeneration timer

            $_SESSION['user_id'] = $logged_in_user['id'];
            $_SESSION['username'] = $logged_in_user['username'];
            $_SESSION['role'] = $logged_in_user['role'];
            $_SESSION['is_admin'] = (bool)$logged_in_user['is_admin'];

            log_message('INFO', "User '{$username}' (ID: {$logged_in_user['id']}) logged in successfully.", $logged_in_user['id']);

            // Redirect to a dashboard or intended page
            $redirect_url = $_SESSION['redirect_url'] ?? 'dashboard.php';
            unset($_SESSION['redirect_url']); // Clear the stored redirect URL
            header("Location: " . $redirect_url);
            exit;
        } else {
            $error_message = $login_result['message'];
            log_message('WARNING', "Login failed for user '{$username}': {$error_message}");
        }
    }
}

// Generate a new CSRF token for the login form
$csrf_token = generate_csrf_token();

// Simple HTML for login form (no external CSS as per prompt)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Basic CRM</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 400px; margin: 40px auto; }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input[type="text"], input[type="password"] { width: calc(100% - 22px); padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 3px; }
        button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; width: 100%; font-size: 16px; }
        button:hover { background-color: #0056b3; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 3px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .note { font-size: 0.9em; text-align: center; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>CRM Login</h2>
        <?php if ($error_message): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p class="message success"><?php echo htmlspecialchars($success_message); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <?php echo csrf_input_field(); ?>
            <div>
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
        </form>
        <p class="note">Don't have an account? <a href="register.php">Register here</a> (Admin function placeholder).</p>
    </div>
</body>
</html>
