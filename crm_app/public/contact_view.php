<?php
// public/contact_view.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Contact.php';
require_once __DIR__ . '/../includes/log_helper.php';

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

if (!$contact_id) {
    $_SESSION['error_message'] = 'Invalid contact ID specified.';
    header("Location: {$app_url_base}/contacts_list.php");
    exit;
}

$contact = new Contact($pdo);
if (!$contact->read($contact_id)) {
    $_SESSION['error_message'] = 'Contact not found.';
    header("Location: {$app_url_base}/contacts_list.php");
    exit;
}

// Authorization: If contact is inactive, only admins should be able to view it.
if (!$contact->is_active && !(isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) {
    $_SESSION['error_message'] = 'You do not have permission to view this contact.';
    log_message('warning', "User ID ".($_SESSION['user_id'] ?? 'N/A')." attempt to view inactive contact ID {$contact_id}");
    header("Location: {$app_url_base}/contacts_list.php");
    exit;
}

$page_title = "View Contact: " . htmlspecialchars($contact->first_name . ' ' . $contact->last_name);

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center flex-wrap">
                <span class="mr-3"><?= htmlspecialchars($contact->first_name . ' ' . $contact->last_name) ?></span>
                <?php if ($contact->title): ?>
                    <span class="ml-2 text-lg text-gray-500 font-normal">(<?= htmlspecialchars($contact->title) ?>)</span>
                <?php endif; ?>
                <?php if (!$contact->is_active): ?>
                    <span class="ml-3 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500">Contact Details</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($app_url_base) ?>/contact_edit.php?id=<?= $contact->id ?>" class="bg-secondary text-white py-2 px-4 rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-secondary transition duration-150 ease-in-out font-semibold text-sm">
                Edit Contact
            </a>
             <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php" class="text-sm text-gray-600 hover:text-gray-800 py-2 px-4 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition duration-150 ease-in-out">&larr; Back to List</a>
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
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Contact Information</h2>
            <div class="space-y-4">
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Full Name:</strong>
                    <p class="text-gray-800 text-lg"><?= htmlspecialchars($contact->first_name . ' ' . $contact->last_name) ?></p>
                </div>
                 <div>
                    <strong class="block text-sm font-medium text-gray-600">Title:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)($contact->title) ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Email:</strong>
                    <?php if ($contact->email): ?>
                        <a href="mailto:<?= htmlspecialchars($contact->email) ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($contact->email) ?></a>
                    <?php else: ?>
                        <p class="text-gray-500">N/A</p>
                    <?php endif; ?>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Mobile Phone:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)($contact->phone_mobile) ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Work Phone:</strong>
                    <p class="text-gray-800"><?= htmlspecialchars((string)($contact->phone_work) ?: 'N/A') ?></p>
                </div>
                <div>
                    <strong class="block text-sm font-medium text-gray-600">Organisation:</strong>
                    <?php if ($contact->organisation_id && $contact->organisation_name): ?>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $contact->organisation_id ?>" class="text-primary hover:text-teal-700">
                            <?= htmlspecialchars($contact->organisation_name) ?>
                        </a>
                    <?php else: ?>
                        <p class="text-gray-500">N/A</p>
                    <?php endif; ?>
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
                        <span class="text-gray-800"><?= htmlspecialchars((string)($contact->created_by_username) ?: 'N/A') ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Created At:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($contact->created_at))) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Last Updated:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($contact->updated_at))) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Version:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars((string)$contact->version) ?></span>
                    </div>
                    <div>
                        <strong class="text-gray-600">Status:</strong>
                        <span class="text-gray-800 font-semibold <?= $contact->is_active ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $contact->is_active ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Related Items</h2>
                <ul class="space-y-2">
                    <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php?filter_contact_id=<?= $contact->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Leads &rarr;
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php?filter_contact_id=<?= $contact->id ?>" class="block text-primary hover:text-teal-700">
                            View Related Deals &rarr;
                        </a>
                    </li>
                     <li>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php?filter_related_to_type=Contact&filter_related_to_id=<?= $contact->id ?>" class="block text-primary hover:text-teal-700">
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
