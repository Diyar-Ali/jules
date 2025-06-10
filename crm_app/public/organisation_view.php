<?php
// public/organisation_view.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Organisation.php';
// Potentially load other classes if displaying related items directly: Contact, Lead, Deal, Activity
require_once __DIR__ . '/../includes/log_helper.php';

$organisation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

if (!$organisation_id) {
    $_SESSION['error_message'] = 'Invalid organisation ID specified.';
    header("Location: {$app_url_base}/organisations_list.php");
    exit;
}

$organisation = new Organisation($pdo);
if (!$organisation->read($organisation_id)) {
    $_SESSION['error_message'] = 'Organisation not found.';
    header("Location: {$app_url_base}/organisations_list.php");
    exit;
}

// Security Check: If the organisation is inactive, only admins should be able to view it.
if (!$organisation->is_active && !(isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) {
    $_SESSION['error_message'] = 'You do not have permission to view this organisation.';
    log_message('warning', "User ID ".($_SESSION['user_id'] ?? 'N/A')." attempt to view inactive organisation ID {$organisation_id}");
    header("Location: {$app_url_base}/organisations_list.php");
    exit;
}

$page_title = "View Organisation: " . htmlspecialchars($organisation->name);

// Flash messages from other operations
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center flex-wrap">
                <span class="mr-3"><?= htmlspecialchars($organisation->name) ?></span>
                <?php if (!$organisation->is_active): ?>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500">Organisation Details</p>
        </div>
        <div class="flex flex-wrap gap-2">
                <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_edit.php?id=<?= $organisation->id ?>" class="bg-secondary text-white py-2 px-4 rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-secondary transition duration-150 ease-in-out font-semibold text-sm">
                Edit Organisation
            </a>
                 <a href="<?= htmlspecialchars($app_url_base) ?>/organisations_list.php" class="ml-2 py-2 px-3 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">&larr; Back to List</a>
        </div>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Main Details Card -->
        <div class="md:col-span-2 bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Organisation Information</h2>
            <div class="space-y-4">
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Name:</strong>
                    <p class="text-gray-800 text-lg"><?= htmlspecialchars($organisation->name) ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Website:</strong>
                    <?php if ($organisation->website): ?>
                        <a href="<?= htmlspecialchars($organisation->website) ?>" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-teal-700 break-all"><?= htmlspecialchars($organisation->website) ?></a>
                    <?php else: ?>
                        <p class="text-gray-500">N/A</p>
                    <?php endif; ?>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Phone:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->phone ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Industry:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->industry ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Annual Revenue:</strong>
                    <p class="text-gray-800"><?= $organisation->annual_revenue !== null ? '$' . htmlspecialchars(number_format($organisation->annual_revenue, 2)) : 'N/A' ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Description:</strong>
                    <p class="text-gray-800 whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string)$organisation->description ?: 'N/A')) ?></p>
                </div>
            </div>

            <h3 class="text-lg font-semibold text-gray-700 mt-6 mb-3 border-b pb-2">Address</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Street:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->address_street ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">City:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->address_city ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">State / Province:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->address_state ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">ZIP / Postal Code:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->address_zip ?: 'N/A') ?></p>
                </div>
                <div class="sm:col-span-2">
                    <strong class="block text-sm font-medium text-gray-600">Country:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)$organisation->address_country ?: 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Sidebar / Related Info & Audit Card -->
        <div class="space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Audit Information</h2>
                <div class="space-y-2 text-sm">
                    <div>
                        <strong class="text-gray-600">Created By:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars((string)($organisation->created_by_username) ?: 'N/A') ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Created At:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($organisation->created_at))) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Last Updated:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($organisation->updated_at))) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Version:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars((string)$organisation->version) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Status:</strong>
                        <span class="text-gray-800 font-semibold <?= $organisation->is_active ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $organisation->is_active ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Related Items</h2>
                <ul class="space-y-2">
                    <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php?filter_organisation_id=<?= $organisation->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Contacts &rarr;
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php?filter_organisation_id=<?= $organisation->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Leads &rarr;
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php?filter_organisation_id=<?= $organisation->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Deals &rarr;
                        </a>
                    </li>
                     <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php?filter_related_to_type=Organisation&filter_related_to_id=<?= $organisation->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Activities &rarr;
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
