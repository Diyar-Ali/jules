<?php
// public/deal_view.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Deal.php';
require_once __DIR__ . '/../includes/log_helper.php';

$deal_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

if (!$deal_id) {
    $_SESSION['error_message'] = 'Invalid deal ID specified.';
    header("Location: {$app_url_base}/deals_list.php");
    exit;
}

$deal = new Deal($pdo);
if (!$deal->read($deal_id)) {
    $_SESSION['error_message'] = 'Deal not found.';
    header("Location: {$app_url_base}/deals_list.php");
    exit;
}

// Authorization: If deal is inactive, only admins should view.
if (!$deal->is_active && !(isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) {
    $_SESSION['error_message'] = 'You do not have permission to view this deal.';
    log_message('warning', "User ID ".($_SESSION['user_id'] ?? 'N/A')." attempt to view inactive deal ID {$deal_id}");
    header("Location: {$app_url_base}/deals_list.php");
    exit;
}

$page_title = "View Deal: " . htmlspecialchars($deal->name);

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// $stage_options = Deal::getStageOptions(); // Not strictly needed for view, but useful for consistency if stage display logic gets complex

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center flex-wrap">
                <span class="mr-2"><?= htmlspecialchars($deal->name) ?></span>
                <span class="stage-badge stage-<?= strtolower(str_replace(array(' ', '/'), '-', htmlspecialchars($deal->stage))) ?>"><?= htmlspecialchars($deal->stage) ?></span>
                <?php if (!$deal->is_active): ?>
                    <span class="ml-3 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive Record</span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500">Deal Details</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($app_url_base) ?>/deal_edit.php?id=<?= $deal->id ?>" class="btn-secondary text-sm">
                Edit Deal
            </a>
             <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php" class="ml-2 text-sm text-gray-600 hover:text-gray-800 self-center py-2 px-4 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition duration-150 ease-in-out">&larr; Back to List</a>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Main Details Card -->
        <div class="md:col-span-2 bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Deal Information</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <div><strong class="detail-label">Name:</strong><p class="detail-value"><?= htmlspecialchars($deal->name) ?></p></div>
                <div><strong class="detail-label">Stage:</strong><p class="detail-value"><span class="stage-badge stage-<?= strtolower(str_replace(array(' ', '/'), '-', htmlspecialchars($deal->stage))) ?>"><?= htmlspecialchars($deal->stage) ?></span></p></div>
                <div><strong class="detail-label">Amount:</strong><p class="detail-value"><?= '$' . htmlspecialchars(number_format($deal->amount, 2)) ?></p></div>
                <div><strong class="detail-label">Probability:</strong><p class="detail-value"><?= $deal->probability !== null ? htmlspecialchars(number_format($deal->probability * 100, 0)) . '%' : 'N/A' ?></p></div>
                <div><strong class="detail-label">Expected Close Date:</strong><p class="detail-value"><?= $deal->close_date ? htmlspecialchars(date('M j, Y', strtotime($deal->close_date))) : 'N/A' ?></p></div>
                <div class="sm:col-span-2"><strong class="detail-label">Description:</strong><p class="detail-value whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string)($deal->description) ?: 'N/A')) ?></p></div>
            </div>

            <h3 class="text-lg font-semibold text-gray-700 mt-6 mb-3 border-b pb-2">Associated Parties</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <strong class="detail-label">Organisation:</strong>
                    <?php if ($deal->organisation_id && $deal->organisation_name): ?>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $deal->organisation_id ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($deal->organisation_name) ?></a>
                    <?php else: ?>
                         <p class="detail-value">N/A (Error: Organisation is required)</p>
                    <?php endif; ?>
                </div>
                <div>
                    <strong class="detail-label">Primary Contact:</strong>
                    <?php if ($deal->contact_id && $deal->contact_name): ?>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/contact_view.php?id=<?= $deal->contact_id ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($deal->contact_name) ?></a>
                    <?php else: ?><p class="detail-value">N/A</p><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar / Audit & Related Card -->
        <div class="space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Audit & Assignment</h2>
                <div class="space-y-2 text-sm">
                    <div><strong class="text-gray-600">Assigned To:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)($deal->assigned_user_name) ?: 'N/A') ?></span></div>
                    <div><strong class="text-gray-600">Created By:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)($deal->created_by_username) ?: 'N/A') ?></span></div>
                    <div><strong class="text-gray-600">Created At:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($deal->created_at))) ?></span></div>
                    <div><strong class="text-gray-600">Last Updated:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($deal->updated_at))) ?></span></div>
                    <div><strong class="text-gray-600">Version:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)$deal->version) ?></span></div>
                    <div><strong class="text-gray-600">Record Status:</strong> <span class="font-semibold <?= $deal->is_active ? 'text-green-600' : 'text-red-600' ?>"><?= $deal->is_active ? 'Active' : 'Inactive' ?></span></div>
                </div>
            </div>

             <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Related Activities</h2>
                 <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php?filter_related_to_type=Deal&filter_related_to_id=<?= $deal->id ?>" class="block text-primary hover:text-teal-700 text-sm">
                    View All Related Activities &rarr;
                </a>
                <?php // Placeholder for a few recent activities if needed later ?>
            </div>
        </div>
    </div>
</div>
<style>
    .stage-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height: 1.2; }
    .stage-prospecting { background-color: #E0E7FF; color: #4338CA; }
    .stage-qualification { background-color: #DBEAFE; color: #1D4ED8; }
    .stage-proposal { background-color: #FEF3C7; color: #92400E; }
    .stage-negotiation { background-color: #FCE7F3; color: #9D174D; }
    .stage-needs-analysis { background-color: #E0F2FE; color: #0C4A6E; }
    .stage-won { background-color: #D1FAE5; color: #065F46; }
    .stage-lost { background-color: #FEE2E2; color: #991B1B; }
    .detail-label { display: block; font-size: 0.875rem; font-weight: 500; color: #4B5563; }
    .detail-value { font-size: 1rem; color: #1F2937; margin-top: 0.125rem; }
    .btn-secondary { padding: 0.5rem 1rem; background-color: #E27B2B; color: white; border-radius: 0.375rem; font-weight: 500; text-decoration: none; transition: background-color 0.2s; display: inline-block; }
    .btn-secondary:hover { background-color: #D46F20; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
