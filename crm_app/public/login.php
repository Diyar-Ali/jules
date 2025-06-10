<?php
// login.php

// Attempt to load .env if auth_check/session_config haven't already.
// This is to ensure APP_URL is available if this page is hit directly.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }
}

// It's crucial session_config.php is loaded first to set up secure session parameters.
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../config/db.php'; // For $pdo
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/csrf_helper.php'; // For CSRF token
require_once __DIR__ . '/../includes/log_helper.php'; // For logging

$app_url_path = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$dashboard_url = $app_url_path . '/index.php';
if (!str_starts_with($dashboard_url, '/')) $dashboard_url = '/' . $dashboard_url;


// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: " . $dashboard_url);
    exit;
}

$error_message = '';
$success_message = ''; // For logout message, etc.

if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success_message = 'You have been successfully logged out.';
}
if (isset($_GET['message']) && $_GET['message'] == 'session_expired') {
    $error_message = 'Your session has expired. Please log in again.';
}
 if (isset($_GET['message']) && $_GET['message'] == 'unauthorized') {
    $error_message = 'You are not authorized to view that page. Please log in.';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Please try again.';
        log_message('warning', 'CSRF token validation failed on login attempt.');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error_message = 'Username and password are required.';
        } else {
            try {
                $user = User::findByUsername($pdo, $username, true); // Find active user

                if ($user && $user->verifyPassword($password)) {
                    // Password is correct, user is active
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['username'] = $user->username;
                    $_SESSION['role'] = $user->role;
                    $_SESSION['is_admin'] = $user->is_admin;
                    $_SESSION['last_activity'] = time(); // Reset activity timer

                    // Regenerate session ID upon successful login for security
                    session_regenerate_id(true);
                    $_SESSION['last_session_regenerated'] = time(); // Reset regeneration timer

                    log_message('info', 'User logged in successfully.', ['user_id' => $user->id, 'username' => $user->username]);

                    // Redirect to intended URL or dashboard
                    $redirect_url = $_SESSION['redirect_url'] ?? $dashboard_url;
                    unset($_SESSION['redirect_url']); // Clear stored redirect URL

                    header("Location: " . $redirect_url);
                    exit;
                } else {
                    $error_message = 'Invalid username or password.';
                    log_message('warning', 'Failed login attempt.', ['username' => $username]);
                }
            } catch (Exception $e) {
                $error_message = 'An error occurred. Please try again later.';
                log_message('error', 'Exception during login: ' . $e->getMessage(), ['username' => $username]);
            }
        }
    }
    // Clear CSRF token after use to prevent replay on error page (optional, depends on desired flow)
    // unset($_SESSION['csrf_token']); // If you use one-time tokens strictly
}

// Generate a new CSRF token for the form if not already set or after processing POST
generate_csrf_token();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Connect CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4FD1C5', // Teal
                        secondary: '#E27B2B', // Orange
                    }
                }
            }
        }
    </script>
    <style>
        /* Additional custom styles if needed */
        body {
            font-family: 'Inter', sans-serif; /* Example: Using a Google Font */
        }
        /* Add a subtle background pattern or gradient if desired */
    </style>
     <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-primary">Connect CRM</h1>
            <p class="text-gray-600 mt-2">Please log in to access your account.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-700 border border-red-400 rounded-md">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-700 border border-green-400 rounded-md">
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" novalidate>
            <?= csrf_input_field() ?>
            <div class="mb-6">
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="username" required
                       class="w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-150"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" required
                       class="w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition duration-150">
            </div>
            <div class="mb-6">
                <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out">
                    Login
                </button>
            </div>
        </form>
        <!-- No "Forgot Password" or "Register" links as per spec -->
    </div>
</body>
</html>
