<?php
// users_list.php (Admin-only User Listing and Management)

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Handles auth, makes $current_user_id, is_admin() available
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/csrf_helper.php'; // For CSRF in action links if needed (e.g., activate/deactivate)
require_once __DIR__ . '/../includes/log_helper.php';

require_admin(); // Ensure only admins can access

$page_title = "User Management";
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']); // Clear flash messages

// Filtering
$filter_role = $_GET['filter_role'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'all'; // 'all', 'active', 'inactive'
$search_term = trim($_GET['search_term'] ?? '');

$filters = [];
if (!empty($filter_role)) {
    $filters['role'] = $filter_role;
}
if ($filter_status === 'active') {
    $filters['is_active'] = true;
} elseif ($filter_status === 'inactive') {
    $filters['is_active'] = false;
}
if (!empty($search_term)) {
    $filters['search_term'] = $search_term;
}

try {
    $users = User::readAll($pdo, $filters);
    $user_roles_options = User::getRoleOptions();
} catch (Exception $e) {
    $error_message = "Error fetching users: " . htmlspecialchars($e->getMessage());
    $users = [];
    $user_roles_options = []; // Initialize to prevent errors in foreach
    log_message('error', "Failed to fetch users list: " . $e->getMessage());
}

// Generate CSRF token for potential actions on this page (like delete, activate/deactivate links)
// While these actions are better as POST, if GET is used, CSRF is vital.
// For this list view, direct actions are "Edit" (links to user_edit.php) and "Add New User"
// Hard Delete will be a POST form on user_edit or a confirmation page.
// Activate/Deactivate will be handled in user_edit.php
generate_csrf_token();


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
                        danger: '#E53E3E', // Red for delete actions
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
    <?php // TODO: Include proper header template later
          // include __DIR__ . '/../templates/header.php';
    ?>
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <a href="register.php" class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
                Add New User
            </a>
        </div>

        <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger dismissable-alert">
                <?= $error_message ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
                <div class="alert alert-success dismissable-alert">
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <form method="GET" action="users_list.php" class="bg-white p-4 rounded-lg shadow mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="search_term" class="block text-sm font-medium text-gray-700">Search</label>
                    <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Username, email, name..."
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                </div>
                <div>
                    <label for="filter_role" class="block text-sm font-medium text-gray-700">Role</label>
                    <select name="filter_role" id="filter_role" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                        <option value="">All Roles</option>
                        <?php if (!empty($user_roles_options)): ?>
                            <?php foreach ($user_roles_options as $role_opt): ?>
                                <option value="<?= htmlspecialchars($role_opt) ?>" <?= ($filter_role === $role_opt) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst($role_opt)) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label for="filter_status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="filter_status" id="filter_status" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                        <option value="all" <?= ($filter_status === 'all') ? 'selected' : '' ?>>All Statuses</option>
                        <option value="active" <?= ($filter_status === 'active') ? 'selected' : '' ?>>Active Only</option>
                        <option value="inactive" <?= ($filter_status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 text-right">
                <a href="users_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-2">Clear Filters</a>
                <button type="submit" class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-secondary hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-secondary">
                    Apply Filters
                </button>
            </div>
        </form>

        <!-- Users Table -->
        <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Full Name</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Admin</th>
                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user_item): ?>
                            <tr class="<?= $user_item->is_active ? '' : 'bg-gray-50 opacity-70' ?>">
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($user_item->username) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars(trim($user_item->first_name . ' ' . $user_item->last_name)) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                    <a href="mailto:<?= htmlspecialchars($user_item->email) ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($user_item->email) ?></a>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars(ucfirst($user_item->role)) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 text-center">
                                    <?php if ($user_item->is_admin): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Yes</span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                    <?php if ($user_item->is_active): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                    <a href="user_edit.php?id=<?= htmlspecialchars((string)$user_item->id) ?>" class="text-secondary hover:text-orange-700 mr-3">Edit</a>
                                    <?php // Activate/Deactivate/Delete buttons will be more fleshed out in user_edit.php
                                          // Or handled via POST requests for safety.
                                          // For now, "Edit" is the primary action from the list.
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
         <p class="mt-4 text-xs text-gray-500">Total Users: <?= count($users) ?></p>
    </div>
    <?php // TODO: Include proper footer template later
          // include __DIR__ . '/../templates/footer.php';
    ?>
</body>
</html>
