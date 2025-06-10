<?php
// public/activity_view.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Activity.php';
require_once __DIR__ . '/../includes/log_helper.php';

$activity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

// Construct file download base URL. This assumes 'uploads' is a sibling to 'public'.
// Example: APP_URL=http://localhost/crm_app/public -> $file_download_base_url=http://localhost/crm_app/uploads/activities/
// This is a simplified approach. A dedicated download script is more secure.
$app_url_scheme_host = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_SCHEME) . '://' . parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST);
$app_path_parts = explode('/', trim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH), '/'));
if (count($app_path_parts) > 1 && $app_path_parts[count($app_path_parts) -1] === 'public') {
    array_pop($app_path_parts); // Remove 'public'
}
$file_download_base_url = $app_url_scheme_host . '/' . implode('/', $app_path_parts) . '/uploads/activities/';


if (!$activity_id) {
    $_SESSION['error_message'] = 'Invalid activity ID specified.';
    header("Location: {$app_url_base}/activities_list.php");
    exit;
}

$activity = new Activity($pdo);
if (!$activity->read($activity_id)) { // read() also calls getRelatedEntityName()
    $_SESSION['error_message'] = 'Activity not found.';
    header("Location: {$app_url_base}/activities_list.php");
    exit;
}

// Authorization: If activity is inactive, only admins should view.
if (!$activity->is_active && !(isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) {
    $_SESSION['error_message'] = 'You do not have permission to view this activity.';
    log_message('warning', "User ID ".($_SESSION['user_id'] ?? 'N/A')." attempt to view inactive activity ID {$activity_id}");
    header("Location: {$app_url_base}/activities_list.php");
    exit;
}

$page_title = "View Activity: " . htmlspecialchars($activity->subject);

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

$files_metadata = json_decode($activity->files_json ?: '[]', true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $files_metadata = []; // Gracefully handle invalid JSON
    log_message('warning', "Invalid JSON in files_json for activity ID {$activity_id}. Value: {$activity->files_json}");
}


require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center flex-wrap">
                <span class="mr-2"><?= htmlspecialchars($activity->subject) ?></span>
                <span class="act-type-badge act-type-<?= strtolower(htmlspecialchars($activity->type)) ?> mr-2"><?= htmlspecialchars($activity->type) ?></span>
                <span class="act-status-badge act-status-<?= strtolower(htmlspecialchars($activity->status)) ?> mr-2"><?= htmlspecialchars($activity->status) ?></span>
                <?php if (!$activity->is_active): ?>
                    <span class="ml-3 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive Record</span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500">Activity Details</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($app_url_base) ?>/activity_edit.php?id=<?= $activity->id ?>" class="btn-secondary text-sm">
                Edit Activity
            </a>
             <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php" class="ml-2 text-sm text-gray-600 hover:text-gray-800 self-center py-2 px-4 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition duration-150 ease-in-out">&larr; Back to List</a>
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
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Activity Information</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <div><strong class="detail-label">Subject:</strong><p class="detail-value"><?= htmlspecialchars($activity->subject) ?></p></div>
                <div><strong class="detail-label">Type:</strong><p class="detail-value"><span class="act-type-badge act-type-<?= strtolower(htmlspecialchars($activity->type)) ?>"><?= htmlspecialchars($activity->type) ?></span></p></div>
                <div><strong class="detail-label">Status:</strong><p class="detail-value"><span class="act-status-badge act-status-<?= strtolower(htmlspecialchars($activity->status)) ?>"><?= htmlspecialchars($activity->status) ?></span></p></div>
                <div><strong class="detail-label">Due Date:</strong><p class="detail-value"><?= $activity->due_date ? htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->due_date))) : 'N/A' ?></p></div>
                <div class="sm:col-span-2"><strong class="detail-label">Description (General):</strong><p class="detail-value whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string)($activity->description) ?: 'N/A')) ?></p></div>
            </div>

            <h3 class="text-lg font-semibold text-gray-700 mt-6 mb-3 border-b pb-2">Detailed Notes</h3>
            <div class="prose max-w-none p-4 border rounded-md bg-gray-50 min-h-[100px]">
                <?= !empty($activity->notes_content) ? $activity->notes_content : '<p class="text-gray-500">No detailed notes provided.</p>' ?>
            </div>

            <h3 class="text-lg font-semibold text-gray-700 mt-6 mb-3 border-b pb-2">Attached Files</h3>
            <?php if (!empty($files_metadata)): ?>
                <ul class="list-disc list-inside space-y-2">
                    <?php foreach ($files_metadata as $file): ?>
                        <li>
                            <a href="<?= htmlspecialchars($file_download_base_url . rawurlencode($file['path'])) ?>"
                               target="_blank" class="text-primary hover:text-teal-700"
                               title="Type: <?= htmlspecialchars($file['type']) ?>, Size: <?= round($file['size']/1024,1) ?> KB">
                                <?= htmlspecialchars($file['name']) ?> (<?= round($file['size']/1024,1) ?> KB)
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-500">No files attached to this activity.</p>
            <?php endif; ?>

        </div>

        <!-- Sidebar / Audit & Related Card -->
        <div class="space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Related To & Assignment</h2>
                 <div class="space-y-2 text-sm">
                    <div>
                        <strong class="text-gray-600">Related To:</strong>
                        <?php
                        $related_link = '#';
                        if ($activity->related_to_type && $activity->related_to_id && $activity->related_to_entity_name) {
                            if ($activity->related_to_type === 'Lead') $related_link = "{$app_url_base}/lead_view.php?id={$activity->related_to_id}";
                            elseif ($activity->related_to_type === 'Deal') $related_link = "{$app_url_base}/deal_view.php?id={$activity->related_to_id}";
                            elseif ($activity->related_to_type === 'Organisation') $related_link = "{$app_url_base}/organisation_view.php?id={$activity->related_to_id}";
                            elseif ($activity->related_to_type === 'Contact') $related_link = "{$app_url_base}/contact_view.php?id={$activity->related_to_id}";
                            elseif ($activity->related_to_type === 'User' && (isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) $related_link = "{$app_url_base}/user_edit.php?id={$activity->related_to_id}";
                        ?>
                            <a href="<?= htmlspecialchars($related_link) ?>" class="text-primary hover:text-teal-700">
                                <?= htmlspecialchars($activity->related_to_type . ': ' . $activity->related_to_entity_name) ?>
                            </a>
                        <?php } else { echo '<span class="text-gray-800">N/A</span>'; } ?>
                    </div>
                    <div><strong class="text-gray-600">Assigned To:</strong>
                        <span class="text-gray-800"><?= htmlspecialchars((string)($activity->assigned_to_user_name) ?: 'N/A') ?></span>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Audit Information</h2>
                <div class="space-y-2 text-sm">
                    <div><strong class="text-gray-600">Created By:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)($activity->created_by_username) ?: 'N/A') ?></span></div>
                    <div><strong class="text-gray-600">Created At:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->created_at))) ?></span></div>
                    <div><strong class="text-gray-600">Last Updated:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->updated_at))) ?></span></div>
                    <div><strong class="text-gray-600">Version:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)$activity->version) ?></span></div>
                    <div><strong class="text-gray-600">Record Status:</strong> <span class="font-semibold <?= $activity->is_active ? 'text-green-600' : 'text-red-600' ?>"><?= $activity->is_active ? 'Active' : 'Inactive' ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
    .act-type-badge, .act-status-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height:1.2; }
    .act-type-call { background-color: #E0E7FF; color: #4338CA; }
    .act-type-meeting { background-color: #DBEAFE; color: #1D4ED8; }
    .act-type-email { background-color: #CCEFFE; color: #006BA2; }
    .act-type-task { background-color: #FEF3C7; color: #92400E; }
    .act-type-other { background-color: #E5E7EB; color: #374151; }
    .act-status-pending { background-color: #F3F4F6; color: #4B5563; border: 1px solid #D1D5DB; }
    .act-status-completed { background-color: #D1FAE5; color: #065F46; }
    .act-status-cancelled { background-color: #FEE2E2; color: #991B1B; }
    .detail-label { display: block; font-size: 0.875rem; font-weight: 500; color: #4B5563; }
    .detail-value { font-size: 1rem; color: #1F2937; margin-top: 0.125rem; }
    .btn-secondary { padding: 0.5rem 1rem; background-color: #E27B2B; color: white; border-radius: 0.375rem; font-weight: 500; text-decoration: none; transition: background-color 0.2s; display: inline-block; }
    .btn-secondary:hover { background-color: #D46F20; }
    .prose { font-size: 1rem; line-height: 1.75; }
    .prose :where(p) { margin-top: 0.75em; margin-bottom: 0.75em; }
    .prose :where(h1,h2,h3,h4) { margin-top: 1.2em; margin-bottom: 0.5em; font-weight: 600; line-height: 1.3; }
    .prose :where(ul,ol) { margin-top: 0.75em; margin-bottom: 0.75em; padding-left: 1.75em; }
    .prose :where(li) { margin-top: 0.25em; margin-bottom: 0.25em; }
    .prose :where(a) { color: #4FD1C5; text-decoration: underline; } /* Tailwind primary color */
    .prose :where(a:hover) { color: #3ABAB0; } /* Darker primary */
    .prose :where(strong) { font-weight: 600; }
    .prose :where(blockquote) { margin-top: 1em; margin-bottom: 1em; padding-left: 1em; border-left: 0.25em solid #e5e7eb; font-style: italic; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
