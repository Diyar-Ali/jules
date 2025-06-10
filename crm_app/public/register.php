<?php
// crm_app/public/register.php
// Placeholder for user registration. In a real CRM, this would typically be an admin-only function.

$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/session_config.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/User.php';
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$user_handler = new User($pdo);
$error_message = '';
$success_message = '';

// For now, anyone can register. Later, this should be restricted to admins.
// require_once $base_path . 'includes/auth_check.php'; // Include this
// if (!is_admin()) {
//     log_message('WARNING', 'Non-admin tried to access register.php', $_SESSION['user_id'] ?? null);
//     header("Location: dashboard.php?error=Access Denied: User registration is an admin function.");
//     exit;
// }


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('WARNING', 'CSRF validation failed for registration attempt.');
    } elseif (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['email']) || empty($_POST['first_name']) || empty($_POST['last_name'])) {
        $error_message = 'Please fill in all required fields.';
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $error_message = 'Passwords do not match.';
    } else {
        $username   = $_POST['username'];
        $password   = $_POST['password'];
        $email      = $_POST['email'];
        $first_name = $_POST['first_name'];
        $last_name  = $_POST['last_name'];
        // For simplicity, role and is_admin are defaulted in User class or can be passed if form fields exist
        // $role       = $_POST['role'] ?? 'viewer';
        // $is_admin   = isset($_POST['is_admin']);

        $result = $user_handler->create($username, $password, $email, $first_name, $last_name); // Uses defaults for role/is_admin

        if ($result['success']) {
            $success_message = $result['message'] . " You can now login.";
            log_message('INFO', "New user '{$username}' (ID: {$result['user_id']}) registered.", $result['user_id']);
            // Optionally redirect to login or a success page
            // header("Location: login.php?message=" . urlencode($success_message));
            // exit;
        } else {
            $error_message = $result['message'];
            log_message('ERROR', "User registration failed for '{$username}': {$error_message}");
        }
    }
}
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Basic CRM</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 500px; margin: 40px auto; }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-bottom: 5px; color: #555; }
        input[type="text"], input[type="email"], input[type="password"] { width: calc(100% - 22px); padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 3px; }
        button { background-color: #28a745; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; width: 100%; font-size: 16px; }
        button:hover { background-color: #218838; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 3px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .note { font-size: 0.9em; text-align: center; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Register New User (Admin Placeholder)</h2>
        <?php if ($error_message): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <p class="message success"><?php echo htmlspecialchars($success_message); ?></p>
        <?php endif; ?>
        <form action="register.php" method="POST">
            <?php echo csrf_input_field(); ?>
            <div>
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div>
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div>
                <label for="first_name">First Name:</label>
                <input type="text" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
            </div>
            <div>
                <label for="last_name">Last Name:</label>
                <input type="text" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
            </div>
            <div>
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div>
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit">Register</button>
        </form>
        <p class="note"><a href="login.php">Back to Login</a></p>
    </div>
</body>
</html>
