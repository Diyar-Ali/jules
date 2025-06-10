<?php
// public/contacts_list.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Contact.php';
require_once __DIR__ . '/../classes/Organisation.php'; // For organisation filter dropdown
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Contacts";
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Filtering
$search_term = trim($_GET['search_term'] ?? '');
$filter_organisation_id = filter_input(INPUT_GET, 'filter_organisation_id', FILTER_VALIDATE_INT) ?: '';
$filter_view_status = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? ($_GET['filter_view_status'] ?? 'active') : 'active';

$filters = [];
if (!empty($search_term)) {
    $filters['search_term'] = $search_term;
}
if (!empty($filter_organisation_id)) {
    $filters['organisation_id'] = $filter_organisation_id;
}

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    if ($filter_view_status === 'active') {
        $filters['is_active'] = true;
    } elseif ($filter_view_status === 'inactive') {
        $filters['is_active'] = false;
    } elseif ($filter_view_status === 'all') {
        $filters['view'] = 'all';
    }
} else {
    $filters['is_active'] = true; // Non-admins always see active
}

try {
    $contacts = Contact::readAll($pdo, $filters);
    // Fetch active organisations for the filter dropdown
    $organisations_for_filter = Organisation::readAll($pdo, ['is_active' => true], false); // false: don't fetch created_by for this list
} catch (Exception $e) {
    $error_message = "Error fetching contacts: " . htmlspecialchars($e->getMessage());
    $contacts = [];
    $organisations_for_filter = [];
    log_message('error', "Failed to fetch contacts list: " . $e->getMessage());
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
        <a href="<?= htmlspecialchars($app_url_base) ?>/contact_edit.php" class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
            Add New Contact
        </a>
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

    <!-- Filter Form -->
    <form method="GET" action="contacts_list.php" class="bg-white p-4 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="search_term" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name, email, title, org..."
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
            </div>
            <div>
                <label for="filter_organisation_id" class="block text-sm font-medium text-gray-700">Organisation</label>
                <select name="filter_organisation_id" id="filter_organisation_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm">
                    <option value="">All Organisations</option>
                    <?php foreach ($organisations_for_filter as $org_filter_item): ?>
                        <option value="<?= htmlspecialchars((string)$org_filter_item->id) ?>" <?= ($filter_organisation_id == $org_filter_item->id) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org_filter_item->name) ?>
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
            <a href="contacts_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-3">Clear Filters</a>
            <button type="submit" class="py-2 px-3 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-secondary hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-secondary">
                Apply Filters
            </button>
        </div>
    </form>

    <!-- Contacts Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone (Mobile)</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organisation</th>
                     <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <?php endif; ?>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($contacts)): ?>
                    <tr>
                        <td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '7' : '6' ?>" class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No contacts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contacts as $contact): ?>
                        <tr class="<?= $contact->is_active ? '' : 'bg-gray-50 opacity-70' ?>">
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/contact_view.php?id=<?= $contact->id ?>" class="text-primary hover:text-teal-700 font-semibold">
                                    <?= htmlspecialchars($contact->first_name . ' ' . $contact->last_name) ?>
                                </a>
                                <?php if ($contact->title): ?>
                                    <span class="block text-xs text-gray-500"><?= htmlspecialchars($contact->title) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                <?php if ($contact->email): ?>
                                <a href="mailto:<?= htmlspecialchars($contact->email) ?>" class="text-secondary hover:text-orange-700">
                                    <?= htmlspecialchars($contact->email) ?>
                                </a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)($contact->phone_mobile) ?: 'N/A') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                <?php if ($contact->organisation_id && $contact->organisation_name): ?>
                                    <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $contact->organisation_id ?>" class="text-primary hover:text-teal-600">
                                        <?= htmlspecialchars($contact->organisation_name) ?>
                                    </a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                <?php if ($contact->is_active): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars((string)($contact->created_by_username) ?: 'N/A') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/contact_view.php?id=<?= $contact->id ?>" class="text-primary hover:text-teal-600 mr-2">View</a>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/contact_edit.php?id=<?= $contact->id ?>" class="text-secondary hover:text-orange-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-xs text-gray-500">Total Contacts: <?= count($contacts) ?></p>
</div>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
