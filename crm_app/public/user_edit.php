<?php
// user_edit.php (Admin-only User Editing, Activation/Deactivation, Deletion)

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Handles auth and makes $current_user_id, is_admin() available
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/csrf_helper.php'; // For CSRF token
require_once __DIR__ . '/../includes/log_helper.php';   // For logging

require_admin(); // Ensure only admins can access

$page_title = "Edit User";
$error_message = '';
$success_message = '';
$user_roles = User::getRoleOptions();

$user_id_to_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$user_id_to_edit) {
    $_SESSION['error_message'] = 'Invalid user ID specified.';
    header('Location: users_list.php');
    exit;
}

$user_to_edit = new User($pdo);
if (!$user_to_edit->read($user_id_to_edit)) {
    $_SESSION['error_message'] = 'User not found.';
    header('Location: users_list.php');
    exit;
}

// Initial form values from the loaded user
$form_username = $user_to_edit->username;
$form_email = $user_to_edit->email;
$form_first_name = $user_to_edit->first_name;
$form_last_name = $user_to_edit->last_name;
$form_role = $user_to_edit->role;
$form_is_admin = $user_to_edit->is_admin;
$form_is_active = $user_to_edit->is_active;
$current_version = $user_to_edit->version;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Please try again.';
        log_message('warning', "CSRF token validation failed on user edit attempt for user ID {$user_id_to_edit} by admin " . ($_SESSION['user_id'] ?? 'N/A'));
    } else {
        $action = $_POST['action'] ?? 'update_details';

        try {
            // Always re-fetch user data before an action to ensure we have the latest version for optimistic locking
            // This is crucial if multiple admins could be editing or if actions are separate POSTs.
            $user_for_action = new User($pdo); // Use a fresh instance for the action
            if (!$user_for_action->read($user_id_to_edit)) {
                throw new Exception("User data could not be reloaded before action.");
            }
            // Use the version from the form for comparison against the just-loaded DB version
            $submitted_version = (int)($_POST['version'] ?? $current_version); // Fallback to loaded if somehow not in POST

            if ($user_for_action->version !== $submitted_version && $action !== 'hard_delete') {
                // For hard_delete, version check might be less critical or handled differently
                // but for updates and toggles, it's vital.
                throw new Exception("Data conflict. The user record was updated by someone else. Please refresh and try again. DB version: {$user_for_action->version}, Submitted version: {$submitted_version}");
            }
            // Now $user_for_action holds the true current state from DB, and its version is the one to increment from.

            if ($action === 'update_details') {
                $form_username = trim($_POST['username'] ?? '');
                $form_email = trim($_POST['email'] ?? '');
                $form_first_name = trim($_POST['first_name'] ?? '');
                $form_last_name = trim($_POST['last_name'] ?? '');
                $new_password = $_POST['password'] ?? '';
                $new_password_confirm = $_POST['password_confirm'] ?? '';
                $form_role = $_POST['role'] ?? $user_for_action->role;
                $form_is_admin = isset($_POST['is_admin']);
                $form_is_active = isset($_POST['is_active']);

                if (empty($form_username) || empty($form_email) || empty($form_role)) {
                    $error_message = 'Username, Email, and Role are required.';
                } elseif (!empty($new_password) && $new_password !== $new_password_confirm) {
                    $error_message = 'New passwords do not match.';
                } elseif (!empty($new_password) && strlen($new_password) < 8) {
                    $error_message = 'New password must be at least 8 characters long.';
                } elseif (!filter_var($form_email, FILTER_VALIDATE_EMAIL)) {
                    $error_message = 'Invalid email format.';
                } elseif (!in_array($form_role, $user_roles)) {
                    $error_message = 'Invalid role selected.';
                } else {
                    // Apply changes to the $user_for_action object (which has correct current version)
                    $user_for_action->username = $form_username;
                    $user_for_action->email = $form_email;
                    $user_for_action->first_name = $form_first_name;
                    $user_for_action->last_name = $form_last_name;
                    $user_for_action->role = $form_role;
                    $user_for_action->is_admin = $form_is_admin;
                    $user_for_action->is_active = $form_is_active;
                    // $user_for_action->version is already the current DB version

                    if (!empty($new_password)) {
                        $user_for_action->setPassword($new_password);
                    }

                    if ($user_for_action->update()) { // update() will use $user_for_action->version
                        $_SESSION['success_message'] = "User '{$user_for_action->username}' updated successfully!";
                        log_message('info', "Admin (ID: ".($current_user_id ?? 'N/A').") updated user '{$user_for_action->username}'.", ['edited_user_id' => $user_for_action->id]);
                        header('Location: users_list.php');
                        exit;
                    } else {
                        $error_message = 'Failed to update user. Optimistic lock failed or no data changed.';
                    }
                }
            } elseif ($action === 'toggle_active') {
                $new_status = !$user_for_action->is_active;
                // $user_for_action already has the current version from DB
                if ($user_for_action->setActiveStatus($new_status)) {
                    $_SESSION['success_message'] = "User '{$user_for_action->username}' status changed to " . ($new_status ? 'Active' : 'Inactive') . ".";
                    log_message('info', "Admin (ID: ".($current_user_id ?? 'N/A').") changed status for user '{$user_for_action->username}'.", ['edited_user_id' => $user_for_action->id, 'new_status' => $new_status]);
                    header('Location: user_edit.php?id=' . $user_id_to_edit);
                    exit;
                } else {
                    $error_message = 'Failed to change user status. Optimistic lock might have failed.';
                }
            } elseif ($action === 'hard_delete') {
                if ($user_for_action->id === ($current_user_id ?? null)) {
                    throw new Exception("You cannot delete your own account.");
                }
                $is_last_admin = false;
                if ($user_for_action->is_admin) {
                    $all_admins = User::readAll($pdo, ['role' => 'admin', 'is_active' => true]); // Check active admins specifically
                    if (count($all_admins) <= 1 && $all_admins[0]->id === $user_for_action->id) {
                        $is_last_admin = true;
                    }
                }
                if ($is_last_admin) {
                    throw new Exception("Cannot delete the last active administrator account.");
                }

                if (User::hardDelete($pdo, $user_for_action->id)) { // Pass $pdo and ID
                    $_SESSION['success_message'] = "User '{$user_for_action->username}' has been permanently deleted.";
                    log_message('info', "Admin (ID: ".($current_user_id ?? 'N/A').") hard deleted user '{$user_for_action->username}'.", ['deleted_user_id' => $user_for_action->id]);
                    header('Location: users_list.php');
                    exit;
                } else {
                    // This else might not be reached if hardDelete throws exception on FK constraint
                    $error_message = "Failed to delete user '{$user_for_action->username}'. They might have related records or user not found.";
                }
            }
        } catch (Exception $e) {
            $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
            log_message('error', "Exception during user edit/action for user ID {$user_id_to_edit} by admin ".($current_user_id ?? 'N/A').": " . $e->getMessage());
        }

        // After any POST action (even with error), re-fetch data to display the latest state
        // and to correctly populate form for next attempt or display.
        if (!$user_to_edit->read($user_id_to_edit)) {
             $_SESSION['error_message'] = 'User not found after action. Redirecting to list.';
             header('Location: users_list.php');
             exit;
        }
        // Update form variables to reflect the current state from DB,
        // unless there was a validation error, in which case POST data is preferred for stickiness.
        if (empty($error_message) || !isset($_POST['action'])) { // If no error or not a POST that failed validation
            $form_username = $user_to_edit->username;
            $form_email = $user_to_edit->email;
            $form_first_name = $user_to_edit->first_name;
            $form_last_name = $user_to_edit->last_name;
            $form_role = $user_to_edit->role;
            $form_is_admin = $user_to_edit->is_admin;
            $form_is_active = $user_to_edit->is_active;
        } else { // A validation error occurred on POST, keep submitted values for stickiness
             $form_username = trim($_POST['username'] ?? $user_to_edit->username);
             $form_email = trim($_POST['email'] ?? $user_to_edit->email);
             $form_first_name = trim($_POST['first_name'] ?? $user_to_edit->first_name);
             $form_last_name = trim($_POST['last_name'] ?? $user_to_edit->last_name);
             $form_role = $_POST['role'] ?? $user_to_edit->role;
             // For checkboxes, if 'action' was 'update_details', then use POST data, otherwise DB state
             if (isset($_POST['action']) && $_POST['action'] === 'update_details') {
                 $form_is_admin = isset($_POST['is_admin']);
                 $form_is_active = isset($_POST['is_active']);
             } else {
                 $form_is_admin = $user_to_edit->is_admin;
                 $form_is_active = $user_to_edit->is_active;
             }
        }
        $current_version = $user_to_edit->version; // Update current_version for the form
    }

    generate_csrf_token();

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($page_title) ?>: <?= htmlspecialchars($user_to_edit->username) ?> - Connect CRM</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            primary: '#4FD1C5',
                            secondary: '#E27B2B',
                            danger: '#E53E3E', // Ensure this is defined for bg-danger
                        }
                    }
                }
            }
            // confirmHardDelete function is now global, defined in footer.php
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Inter', sans-serif; }</style>
    </head>
    <body class="bg-gray-100">
        <?php // Include header template ?>
        <div class="container mx-auto p-4 sm:p-6 lg:p-8">
            <div class="max-w-3xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">
                        <?= htmlspecialchars($page_title) ?>: <span class="text-primary"><?= htmlspecialchars($user_to_edit->username) ?></span>
                    </h1>
            <a href="users_list.php" class="btn btn-muted">&larr; Back to User List</a>
                </div>

                <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert">
                        <?= $error_message ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <div class="bg-white p-6 sm:p-8 rounded-lg shadow-lg mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">User Details</h2>
                    <form action="user_edit.php?id=<?= $user_id_to_edit ?>" method="POST" novalidate class="space-y-6">
                        <?= csrf_input_field() ?>
                        <input type="hidden" name="version" value="<?= htmlspecialchars($current_version) ?>">
                        <input type="hidden" name="action" value="update_details">

                        <div>
                <label for="username" class="form-label">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="username" id="username" required value="<?= htmlspecialchars($form_username) ?>"
                       class="input-field">
                        </div>
                        <div>
                <label for="email" class="form-label">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" id="email" required value="<?= htmlspecialchars($form_email) ?>"
                       class="input-field">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                    <label for="first_name" class="form-label">First Name</label>
                                <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($form_first_name) ?>"
                           class="input-field">
                            </div>
                            <div>
                    <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($form_last_name) ?>"
                           class="input-field">
                            </div>
                        </div>
                        <hr class="my-4">
                        <p class="text-sm text-gray-600 mb-2">Leave password fields blank to keep current password.</p>
                        <div>
                <label for="password" class="form-label">New Password</label>
                            <input type="password" name="password" id="password"
                       class="input-field">
                        </div>
                        <div>
                <label for="password_confirm" class="form-label">Confirm New Password</label>
                            <input type="password" name="password_confirm" id="password_confirm"
                       class="input-field">
                        </div>
                        <hr class="my-4">
                         <div>
                <label for="role" class="form-label">Role <span class="text-red-500">*</span></label>
                <select name="role" id="role" required class="input-field-select">
                                <?php foreach ($user_roles as $role_option): ?>
                                    <option value="<?= htmlspecialchars($role_option) ?>" <?= ($form_role === $role_option) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucfirst($role_option)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-center space-x-6 mt-2">
                            <div class="flex items-center">
                                <input id="is_admin" name="is_admin" type="checkbox" value="1" <?= $form_is_admin ? 'checked' : '' ?>
                                       class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                                <label for="is_admin" class="ml-2 block text-sm text-gray-900">Is Admin?</label>
                            </div>
                             <div class="flex items-center">
                                <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?>
                                       class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                                <label for="is_active" class="ml-2 block text-sm text-gray-900">Is Active?</label>
                            </div>
                        </div>
                        <div class="pt-5">
            <button type="submit" class="btn-primary-full">
                                Save Changes
                            </button>
                        </div>
                         <p class="text-xs text-gray-500 mt-2">Created: <?= htmlspecialchars($user_to_edit->created_at) ?>, Last Updated: <?= htmlspecialchars($user_to_edit->updated_at) ?>, Version: <?= htmlspecialchars($current_version) ?></p>
                    </form>
                </div>

                <!-- Other Actions Section -->
                <div class="bg-white p-6 sm:p-8 rounded-lg shadow-lg">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Other Actions</h2>
                    <div class="space-y-4 md:space-y-0 md:flex md:items-center md:justify-between">
                        <!-- Toggle Active Status Form -->
                        <form action="user_edit.php?id=<?= $user_id_to_edit ?>" method="POST" class="inline-block">
                             <?= csrf_input_field() ?>
                             <input type="hidden" name="version" value="<?= htmlspecialchars($current_version) ?>">
                             <input type="hidden" name="action" value="toggle_active">
                             <button type="submit"
                             class="btn w-full md:w-auto <?= $form_is_active ? 'bg-yellow-500 hover:bg-yellow-600 text-white focus:ring-yellow-500' : 'bg-green-500 hover:bg-green-600 text-white focus:ring-green-500' ?>">
                                 <?= $form_is_active ? 'Deactivate User' : 'Activate User' ?>
                             </button>
                        </form>

                        <!-- Hard Delete Form -->
                        <?php if ($user_to_edit->id !== ($current_user_id ?? null)): ?>
                        <form action="user_edit.php?id=<?= $user_id_to_edit ?>" method="POST" class="inline-block" onsubmit="confirmHardDelete(event, 'user');">
                            <?= csrf_input_field() ?>
                            <input type="hidden" name="version" value="<?= htmlspecialchars($current_version) ?>">
                            <input type="hidden" name="action" value="hard_delete">
                    <button type="submit" class="btn btn-danger w-full md:w-auto">
                                Permanently Delete User
                            </button>
                        </form>
                        <?php else: ?>
                <button type="button" disabled class="btn btn-disabled w-full md:w-auto">
                            Cannot Delete Self
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php // Include footer template ?>
    </body>
    </html>
