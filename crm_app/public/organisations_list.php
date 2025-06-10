<?php
// public/organisations_list.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Organisation.php';
// CSRF helper might be needed if we add quick actions like activate/deactivate directly on the list later
// require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Organisations";

// Flash messages from other operations (e.g., after creating/editing an org)
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Filtering
$search_term = trim($_GET['search_term'] ?? '');
$filter_industry = $_GET['filter_industry'] ?? '';
// Admin specific filter for viewing active/inactive/all
$filter_view_status = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? ($_GET['filter_view_status'] ?? 'active') : 'active'; // Default to 'active'

$filters = [];
if (!empty($search_term)) {
    $filters['search_term'] = $search_term;
}
if (!empty($filter_industry)) {
    $filters['industry'] = $filter_industry;
}

// Apply status filtering based on admin choice or default for non-admins
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    if ($filter_view_status === 'active') {
        $filters['is_active'] = true;
    } elseif ($filter_view_status === 'inactive') {
        $filters['is_active'] = false;
    } elseif ($filter_view_status === 'all') {
        $filters['view'] = 'all'; // Special flag for Organisation::readAll to show all for admin
    }
} else {
    $filters['is_active'] = true; // Non-admins always see active ones
}

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

try {
    $organisations = Organisation::readAll($pdo, $filters);
    $distinct_industries = Organisation::getDistinctIndustries($pdo);
} catch (Exception $e) {
    $error_message = "Error fetching organisations: " . htmlspecialchars($e->getMessage());
    $organisations = [];
    $distinct_industries = [];
    log_message('error', "Failed to fetch organisations list: " . $e->getMessage());
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
        <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_edit.php" class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
            Add New Organisation
        </a>
    </div>

    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert">
            <?= $error_message // Already htmlspecialchars encoded if from exception ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <!-- Filter Form -->
    <form method="GET" action="organisations_list.php" class="bg-white p-4 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="search_term" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name, website, phone, city..."
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
            </div>
            <div>
                <label for="filter_industry" class="block text-sm font-medium text-gray-700">Industry</label>
                <select name="filter_industry" id="filter_industry" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                    <option value="">All Industries</option>
                    <?php foreach ($distinct_industries as $industry): ?>
                        <option value="<?= htmlspecialchars($industry) ?>" <?= ($filter_industry === $industry) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($industry) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <div>
                <label for="filter_view_status" class="block text-sm font-medium text-gray-700">Status View</label>
                <select name="filter_view_status" id="filter_view_status" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                    <option value="active" <?= ($filter_view_status === 'active') ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= ($filter_view_status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                    <option value="all" <?= ($filter_view_status === 'all') ? 'selected' : '' ?>>Show All (Active & Inactive)</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="mt-4 text-right">
            <a href="organisations_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-3">Clear Filters</a>
            <button type="submit" class="py-2 px-3 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-secondary hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-secondary">
                Apply Filters
            </button>
        </div>
    </form>

    <!-- Organisations Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Website</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">City</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Industry</th>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <?php endif; ?>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($organisations)): ?>
                    <tr>
                        <td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '8' : '7' ?>" class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No organisations found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($organisations as $org): ?>
                        <tr class="<?= $org->is_active ? '' : 'bg-gray-50 opacity-70' ?>">
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $org->id ?>" class="text-primary hover:text-teal-700 font-semibold">
                                    <?= htmlspecialchars($org->name) ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                <a href="<?= htmlspecialchars((string)$org->website) ?>" target="_blank" rel="noopener noreferrer" class="text-secondary hover:text-orange-700">
                                    <?= htmlspecialchars((string)$org->website ?: 'N/A') ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)$org->phone ?: 'N/A') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)$org->address_city ?: 'N/A') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)$org->industry ?: 'N/A') ?></td>
                            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                <?php if ($org->is_active): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars((string)($org->created_by_username) ?: 'N/A') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $org->id ?>" class="text-primary hover:text-teal-600 mr-2">View</a>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_edit.php?id=<?= $org->id ?>" class="text-secondary hover:text-orange-700">Edit</a>
                                <?php // Admin-only actions like quick activate/deactivate or delete could go here,
                                      // ideally as POST forms/buttons for safety.
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-xs text-gray-500">Total Organisations: <?= count($organisations) ?></p>
</div>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
