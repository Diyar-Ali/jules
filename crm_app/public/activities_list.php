<?php
// public/activities_list.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Activity.php';
require_once __DIR__ . '/../classes/User.php'; // For assigned user filter
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Activities";
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Filtering
$search_term = trim($_GET['search_term'] ?? '');
$filter_type = $_GET['filter_type'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_assigned_to_user_id = filter_input(INPUT_GET, 'filter_assigned_to_user_id', FILTER_VALIDATE_INT) ?: '';
$filter_related_to_type = $_GET['filter_related_to_type'] ?? '';
$filter_related_to_id = filter_input(INPUT_GET, 'filter_related_to_id', FILTER_VALIDATE_INT) ?: '';
$filter_due_date_start = $_GET['filter_due_date_start'] ?? ''; // Corrected from due_date_from
$filter_due_date_end = $_GET['filter_due_date_end'] ?? '';     // Corrected from due_date_to
$filter_view_status = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? ($_GET['filter_view_status'] ?? 'active') : 'active';


$filters = [];
if (!empty($search_term)) $filters['search_term'] = $search_term;
if (!empty($filter_type)) $filters['type'] = $filter_type;
if (!empty($filter_status)) $filters['status'] = $filter_status;
if (!empty($filter_assigned_to_user_id)) $filters['assigned_to_user_id'] = $filter_assigned_to_user_id;
if (!empty($filter_related_to_type)) $filters['related_to_type'] = $filter_related_to_type;
if (!empty($filter_related_to_id)) $filters['related_to_id'] = $filter_related_to_id;
if (!empty($filter_due_date_start)) $filters['due_date_from'] = $filter_due_date_start; // Match Activity::readAll key
if (!empty($filter_due_date_end)) $filters['due_date_to'] = $filter_due_date_end;         // Match Activity::readAll key


if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    if ($filter_view_status === 'active') $filters['is_active'] = true;
    elseif ($filter_view_status === 'inactive') $filters['is_active'] = false;
    elseif ($filter_view_status === 'all') $filters['view'] = 'all';
} else {
    $filters['is_active'] = true;
}

$related_entity_name_display = '';
if ($filter_related_to_id && $filter_related_to_type) {
    $temp_activity = new Activity($pdo);
    $related_entity_name_display = $temp_activity->getRelatedEntityName($filter_related_to_type, $filter_related_to_id);
    if ($related_entity_name_display) {
        // $page_title is set before header.php, so this change won't reflect in <title> tag
        // But can be used for H1 on page.
    }
}


try {
    $activities = Activity::readAll($pdo, $filters);
    $activity_form_data = (new Activity($pdo))->getRelatedDataForForms();
    $users_for_filter = $activity_form_data['users'];
    $type_options = Activity::getActivityTypeOptions();
    $status_options = Activity::getActivityStatusOptions();
    $related_type_options = Activity::getRelatedToTypeOptions();

} catch (Exception $e) {
    $error_message = "Error fetching activities: " . htmlspecialchars($e->getMessage());
    $activities = [];
    $users_for_filter = []; $type_options = []; $status_options = []; $related_type_options = [];
    log_message('error', "Failed to fetch activities list: " . $e->getMessage());
}

$page_h1 = "Activities";
if ($filter_related_to_id && $filter_related_to_type && $related_entity_name_display) {
    $page_h1 = "Activities for " . htmlspecialchars($filter_related_to_type) . ": " . htmlspecialchars($related_entity_name_display);
}


require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-2">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= $page_h1 ?></h1>
        <a href="<?= htmlspecialchars($app_url_base) ?>/activity_edit.php<?= ($filter_related_to_id && $filter_related_to_type) ? '?related_to_type='.urlencode($filter_related_to_type).'&related_to_id='.urlencode((string)$filter_related_to_id) : '' ?>"
           class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
            Add New Activity
        </a>
    </div>
    <?php if ($filter_related_to_id && $filter_related_to_type && $related_entity_name_display): ?>
        <p class="text-sm text-gray-600 mb-4">
            Filtered by:
            <strong><?= htmlspecialchars($filter_related_to_type) ?> - <?= htmlspecialchars($related_entity_name_display) ?></strong>
            (ID: <?= htmlspecialchars((string)$filter_related_to_id) ?>).
            <a href="activities_list.php" class="text-primary hover:underline ml-2">Show All Activities</a>
        </p>
    <?php endif; ?>


    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="GET" action="activities_list.php" class="bg-white p-4 rounded-lg shadow mb-6 text-sm">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-3">
            <div>
                <label for="search_term" class="block font-medium text-gray-700">Search</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Subject, desc..." class="mt-1 input-field">
            </div>
            <div>
                <label for="filter_type" class="block font-medium text-gray-700">Type</label>
                <select name="filter_type" id="filter_type" class="mt-1 input-field-select">
                    <option value="">All Types</option>
                    <?php foreach ($type_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_type === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_status" class="block font-medium text-gray-700">Status</label>
                <select name="filter_status" id="filter_status" class="mt-1 input-field-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($status_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_status === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_assigned_to_user_id" class="block font-medium text-gray-700">Assigned To</label>
                <select name="filter_assigned_to_user_id" id="filter_assigned_to_user_id" class="mt-1 input-field-select">
                    <option value="">Any User</option>
                    <?php foreach ($users_for_filter as $user): ?>
                        <option value="<?= htmlspecialchars((string)$user['id']) ?>" <?= ($filter_assigned_to_user_id == $user['id']) ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_related_to_type" class="block font-medium text-gray-700">Related Entity Type</label>
                <select name="filter_related_to_type" id="filter_related_to_type" class="mt-1 input-field-select">
                    <option value="">Any Type</option>
                    <?php foreach ($related_type_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_related_to_type === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
             <div>
                <label for="filter_related_to_id" class="block font-medium text-gray-700">Related Entity ID</label>
                <input type="number" name="filter_related_to_id" id="filter_related_to_id" value="<?= htmlspecialchars((string)$filter_related_to_id) ?>" placeholder="e.g., 123" class="mt-1 input-field">
            </div>
            <div>
                <label for="filter_due_date_start" class="block font-medium text-gray-700">Due Date From</label>
                <input type="date" name="filter_due_date_start" id="filter_due_date_start" value="<?= htmlspecialchars($filter_due_date_start) ?>" class="mt-1 input-field">
            </div>
            <div>
                <label for="filter_due_date_end" class="block font-medium text-gray-700">Due Date To</label>
                <input type="date" name="filter_due_date_end" id="filter_due_date_end" value="<?= htmlspecialchars($filter_due_date_end) ?>" class="mt-1 input-field">
            </div>

            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <div class="lg:col-start-4">
                <label for="filter_view_status" class="block font-medium text-gray-700">Record Status</label>
                <select name="filter_view_status" id="filter_view_status" class="mt-1 input-field-select">
                    <option value="active" <?= ($filter_view_status === 'active') ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= ($filter_view_status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                    <option value="all" <?= ($filter_view_status === 'all') ? 'selected' : '' ?>>Show All</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="mt-4 text-right">
            <a href="activities_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-3">Clear Filters</a>
            <button type="submit" class="py-2 px-3 btn-secondary">Apply Filters</button>
        </div>
    </form>

    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="th-cell">Subject</th>
                    <th class="th-cell">Type</th>
                    <th class="th-cell">Status</th>
                    <th class="th-cell">Due Date</th>
                    <th class="th-cell">Related To</th>
                    <th class="th-cell">Assigned To</th>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?><th class="th-cell text-center">Active</th><?php endif; ?>
                    <th class="th-cell">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($activities)): ?>
                    <tr><td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '8' : '7' ?>" class="td-cell text-center">No activities found.</td></tr>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <tr class="<?= $activity->is_active ? '' : 'bg-gray-50 opacity-70' ?> <?= ($activity->status === 'Completed' || $activity->status === 'Cancelled') ? 'opacity-80' : '' ?>">
                            <td class="td-cell font-medium">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/activity_view.php?id=<?= $activity->id ?>" class="text-primary hover:text-teal-700 font-semibold">
                                    <?= htmlspecialchars($activity->subject) ?>
                                </a>
                            </td>
                            <td class="td-cell"><span class="act-type-badge act-type-<?= strtolower(htmlspecialchars($activity->type)) ?>"><?= htmlspecialchars($activity->type) ?></span></td>
                            <td class="td-cell"><span class="act-status-badge act-status-<?= strtolower(htmlspecialchars($activity->status)) ?>"><?= htmlspecialchars($activity->status) ?></span></td>
                            <td class="td-cell"><?= $activity->due_date ? htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->due_date))) : 'N/A' ?></td>
                            <td class="td-cell">
                                <?php if ($activity->related_to_type && $activity->related_to_id && $activity->related_to_entity_name):
                                    $link = '#';
                                    if ($activity->related_to_type === 'Lead') $link = "{$app_url_base}/lead_view.php?id={$activity->related_to_id}";
                                    elseif ($activity->related_to_type === 'Deal') $link = "{$app_url_base}/deal_view.php?id={$activity->related_to_id}";
                                    elseif ($activity->related_to_type === 'Organisation') $link = "{$app_url_base}/organisation_view.php?id={$activity->related_to_id}";
                                    elseif ($activity->related_to_type === 'Contact') $link = "{$app_url_base}/contact_view.php?id={$activity->related_to_id}";
                                    elseif ($activity->related_to_type === 'User' && $is_admin) $link = "{$app_url_base}/user_edit.php?id={$activity->related_to_id}";
                                ?>
                                    <a href="<?= htmlspecialchars($link) ?>" class="text-primary hover:text-teal-600" title="<?= htmlspecialchars($activity->related_to_type) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($activity->related_to_entity_name, 0, 30, "...")) ?>
                                    </a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <td class="td-cell"><?= htmlspecialchars((string)($activity->assigned_to_user_name) ?: 'N/A') ?></td>
                            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <td class="td-cell text-center">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $activity->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $activity->is_active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="td-cell whitespace-nowrap">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/activity_view.php?id=<?= $activity->id ?>" class="text-primary hover:text-teal-600 mr-2">View</a>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/activity_edit.php?id=<?= $activity->id ?>" class="text-secondary hover:text-orange-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-xs text-gray-500">Total Activities: <?= count($activities) ?></p>
</div>
<style>
    .input-field, .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; font-size: 0.875rem;}
    .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
    .input-field-select { background-color: #fff; }
    .btn-secondary { padding: 0.5rem 0.75rem; border: 1px solid transparent; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: white; background-color: #E27B2B; }
    .btn-secondary:hover { background-color: #D46F20; }
    .th-cell { padding: 0.5rem 0.75rem; text-align: left; font-size: 0.75rem; font-weight: 500; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; }
    .td-cell { padding: 0.5rem 0.75rem; font-size: 0.875rem; color: #374151; }
    .act-type-badge, .act-status-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height:1.2 }
    .act-type-call { background-color: #E0E7FF; color: #4338CA; }
    .act-type-meeting { background-color: #DBEAFE; color: #1D4ED8; }
    .act-type-email { background-color: #CCEFFE; color: #006BA2; }
    .act-type-task { background-color: #FEF3C7; color: #92400E; }
    .act-type-other { background-color: #E5E7EB; color: #374151; }
    .act-status-pending { background-color: #F3F4F6; color: #4B5563; border: 1px solid #D1D5DB; }
    .act-status-completed { background-color: #D1FAE5; color: #065F46; }
    .act-status-cancelled { background-color: #FEE2E2; color: #991B1B; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
