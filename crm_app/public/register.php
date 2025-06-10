<?php
// register.php (Admin-only User Creation)

require_once __DIR__ . '/../vendor/autoload.php'; // For Dotenv
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Handles auth and makes $current_user_id, is_admin() available
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/csrf_helper.php'; // For CSRF token
require_once __DIR__ . '/../includes/log_helper.php';   // For logging

// Admin-only access
require_admin(); // Redirects or dies if not admin

$error_message = '';
$success_message = '';
$user_roles = User::getRoleOptions(); // Get roles for the dropdown

// Initialize form field variables
$form_username = '';
$form_email = '';
$form_first_name = '';
$form_last_name = '';
$form_role = 'viewer'; // Default role
$form_is_admin = false; // Default admin status
$form_is_active = true; // Default active status

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Please try again.';
        log_message('warning', 'CSRF token validation failed on user registration attempt by admin ' . ($_SESSION['user_id'] ?? 'N/A'));
    } else {
        // Repopulate form fields for sticky form
        $form_username = trim($_POST['username'] ?? '');
        $form_email = trim($_POST['email'] ?? '');
        $form_first_name = trim($_POST['first_name'] ?? '');
        $form_last_name = trim($_POST['last_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $form_role = $_POST['role'] ?? 'viewer';
        $form_is_admin = isset($_POST['is_admin']); // Checkbox value
        $form_is_active = isset($_POST['is_active']); // Checkbox value

        // Basic Validation
        if (empty($form_username) || empty($form_email) || empty($password) || empty($password_confirm) || empty($form_role)) {
            $error_message = 'Please fill in all required fields: Username, Email, Password, Confirm Password, and Role.';
        } elseif ($password !== $password_confirm) {
            $error_message = 'Passwords do not match.';
        } elseif (strlen($password) < 8) { // Example: Basic password strength
            $error_message = 'Password must be at least 8 characters long.';
        } elseif (!filter_var($form_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Invalid email format.';
        } elseif (!in_array($form_role, $user_roles)) {
            $error_message = 'Invalid role selected.';
        }
         else {
            try {
                $newUser = new User($pdo);
                $newUser->username = $form_username;
                $newUser->email = $form_email;
                $newUser->first_name = $form_first_name;
                $newUser->last_name = $form_last_name;
                $newUser->setPassword($password); // Hashes the password
                $newUser->role = $form_role;
                $newUser->is_admin = $form_is_admin;
                $newUser->is_active = $form_is_active;
                // Version is handled by DB/create method

                if ($newUser->create()) {
                    $success_message = "User '{$newUser->username}' created successfully!";
                    log_message('info', "Admin (ID: ".($current_user_id ?? 'N/A').") created new user '{$newUser->username}'.", ['new_user_id' => $newUser->id]);
                    // Clear form fields after successful creation
                    $form_username = $form_email = $form_first_name = $form_last_name = '';
                    $form_role = 'viewer'; $form_is_admin = false; $form_is_active = true;
                } else {
                    // The create() method should throw an exception on known failures like duplicate username/email
                    // This else might not be reached if exceptions are used for all failure cases.
                    $error_message = 'Failed to create user. Please check logs or try again.';
                }
            } catch (Exception $e) {
                $error_message = "Error creating user: " . htmlspecialchars($e->getMessage());
                log_message('error', "Admin (ID: ".($current_user_id ?? 'N/A').") failed to create user '{$form_username}': " . $e->getMessage());
            }
        }
    }
}

generate_csrf_token(); // Ensure token is available for the form

// Include header
$page_title = "Create New User";
// For simplicity, directly including HTML structure. In a larger app, use templates.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Connect CRM</title>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100">
    <?php // Normally, a proper header template would be included here ?>
    <?php // For now, a simple nav structure might be:
        // include __DIR__ . '/../templates/header.php'; // Assuming header.php contains nav
    ?>
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="bg-white p-6 sm:p-8 rounded-lg shadow-lg w-full max-w-2xl mx-auto">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 text-center"><?= htmlspecialchars($page_title) ?></h1>

            <?php if (!empty($error_message)): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 border border-red-400 rounded-md">
                    <?= $error_message // Already htmlspecialchars encoded if from exception, otherwise fine ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($success_message)): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-700 border border-green-400 rounded-md">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" novalidate class="space-y-6">
                <?= csrf_input_field() ?>

                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" id="username" required value="<?= htmlspecialchars($form_username) ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" id="email" required value="<?= htmlspecialchars($form_email) ?>"
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($form_first_name) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($form_last_name) ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password" id="password" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                </div>

                <div>
                    <label for="password_confirm" class="block text-sm font-medium text-gray-700">Confirm Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password_confirm" id="password_confirm" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                </div>

                <div>
                    <label for="role" class="block text-sm font-medium text-gray-700">Role <span class="text-red-500">*</span></label>
                    <select name="role" id="role" required
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                        <?php foreach ($user_roles as $role_option): ?>
                            <option value="<?= htmlspecialchars($role_option) ?>" <?= ($form_role === $role_option) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($role_option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <input id="is_admin" name="is_admin" type="checkbox" value="1" <?= $form_is_admin ? 'checked' : '' ?>
                               class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                        <label for="is_admin" class="ml-2 block text-sm text-gray-900">
                            Is Admin?
                        </label>
                    </div>
                    <div class="flex items-center">
                        <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?>
                               class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                        <label for="is_active" class="ml-2 block text-sm text-gray-900">
                            Is Active?
                        </label>
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php // Normally, a proper footer template would be included here
          // include __DIR__ . '/../templates/footer.php';
    ?>
</body>
</html>
