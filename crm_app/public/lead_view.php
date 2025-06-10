<?php
// public/lead_view.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Lead.php';
require_once __DIR__ . '/../classes/LeadStatusHistory.php';
require_once __DIR__ . '/../includes/log_helper.php';

$lead_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

if (!$lead_id) {
    $_SESSION['error_message'] = 'Invalid lead ID specified.';
    header("Location: {$app_url_base}/leads_list.php");
    exit;
}

$lead = new Lead($pdo);
if (!$lead->read($lead_id)) {
    $_SESSION['error_message'] = 'Lead not found.';
    header("Location: {$app_url_base}/leads_list.php");
    exit;
}

// Authorization: If lead is inactive, only admins should view.
if (!$lead->is_active && !(isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) {
    $_SESSION['error_message'] = 'You do not have permission to view this lead.';
    log_message('warning', "User ID ".($_SESSION['user_id'] ?? 'N/A')." attempt to view inactive lead ID {$lead_id}");
    header("Location: {$app_url_base}/leads_list.php");
    exit;
}

$page_title = "View Lead: " . htmlspecialchars($lead->name);
$lead_history = LeadStatusHistory::readAllByLeadId($pdo, $lead_id);

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Status and Temperature options are not directly needed for viewing, but can be kept if any inline edit is planned
// $status_options = LeadStatusHistory::getStatusOptions();
// $temperature_options = LeadStatusHistory::getTemperatureOptions();


require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 flex items-center flex-wrap">
                <span class="mr-2"><?= htmlspecialchars($lead->name) ?></span>
                <span class="status-badge status-<?= strtolower(htmlspecialchars($lead->status)) ?> mr-2"><?= htmlspecialchars($lead->status) ?></span>
                <span class="temp-badge temp-<?= strtolower(htmlspecialchars($lead->temperature)) ?> mr-2"><?= htmlspecialchars($lead->temperature) ?></span>
                <?php if (!$lead->is_active): ?>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive Record</span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-gray-500">Lead Details</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if (!$lead->isConverted()): ?>
                <a href="<?= htmlspecialchars($app_url_base) ?>/lead_convert.php?id=<?= $lead->id ?>" class="btn-success text-sm">
                    Convert to Deal
                </a>
                <a href="<?= htmlspecialchars($app_url_base) ?>/lead_edit.php?id=<?= $lead->id ?>" class="btn-secondary text-sm">
                    Edit Lead
                </a>
            <?php else: ?>
                 <span class="px-3 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md">Lead Converted</span>
            <?php endif; ?>
             <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php" class="ml-2 text-sm text-gray-600 hover:text-gray-800 self-center py-2 px-4 border border-gray-300 rounded-md bg-white hover:bg-gray-50 transition duration-150 ease-in-out">&larr; Back to List</a>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($lead->isConverted()): ?>
            <div class="alert alert-info mb-6"> <?php // Changed to alert-info for converted lead message ?>
            This lead has been converted to a Deal.
            <?php if ($lead->converted_to_deal_id): ?>
                <a href="<?= htmlspecialchars($app_url_base) ?>/deal_view.php?id=<?= $lead->converted_to_deal_id ?>" class="font-semibold underline hover:text-green-800">
                    View Deal (ID: <?= htmlspecialchars((string)$lead->converted_to_deal_id) ?>) &rarr;
                </a>
            <?php endif; ?>
             Some actions on this lead may be disabled.
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Main Details Card -->
        <div class="md:col-span-2 bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Lead Information</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <div><strong class="detail-label">Name:</strong><p class="detail-value"><?= htmlspecialchars($lead->name) ?></p></div>
                <div><strong class="detail-label">Source:</strong><p class="detail-value"><?= htmlspecialchars((string)$lead->source ?: 'N/A') ?></p></div>
                <div><strong class="detail-label">Status:</strong><p class="detail-value"><span class="status-badge status-<?= strtolower(htmlspecialchars($lead->status)) ?>"><?= htmlspecialchars($lead->status) ?></span></p></div>
                <div><strong class="detail-label">Temperature:</strong><p class="detail-value"><span class="temp-badge temp-<?= strtolower(htmlspecialchars($lead->temperature)) ?>"><?= htmlspecialchars($lead->temperature) ?></span></p></div>
                <div><strong class="detail-label">Value:</strong><p class="detail-value"><?= $lead->value !== null ? '$' . htmlspecialchars(number_format($lead->value, 2)) : 'N/A' ?></p></div>
                <div><strong class="detail-label">Expected Close Date:</strong><p class="detail-value"><?= $lead->expected_close_date ? htmlspecialchars(date('M j, Y', strtotime($lead->expected_close_date))) : 'N/A' ?></p></div>
                <div class="sm:col-span-2"><strong class="detail-label">Description:</strong><p class="detail-value whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string)$lead->description ?: 'N/A')) ?></p></div>
            </div>

            <h3 class="text-lg font-semibold text-gray-700 mt-6 mb-3 border-b pb-2">Associated Parties</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <strong class="detail-label">Contact:</strong>
                    <?php if ($lead->contact_id && $lead->contact_name): ?>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/contact_view.php?id=<?= $lead->contact_id ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($lead->contact_name) ?></a>
                    <?php else: ?><p class="detail-value">N/A</p><?php endif; ?>
                </div>
                <div>
                    <strong class="detail-label">Organisation:</strong>
                    <?php if ($lead->organisation_id && $lead->organisation_name): ?>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $lead->organisation_id ?>" class="text-primary hover:text-teal-700"><?= htmlspecialchars($lead->organisation_name) ?></a>
                    <?php else: ?><p class="detail-value">N/A</p><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar / Audit & Related Card -->
        <div class="space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Audit & Assignment</h2>
                <div class="space-y-2 text-sm">
                    <div><strong class="text-gray-600">Assigned To:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)($lead->assigned_user_name) ?: 'N/A') ?></span></div>
                    <div><strong class="text-gray-600">Created By:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)($lead->created_by_username) ?: 'N/A') ?></span></div>
                    <div><strong class="text-gray-600">Created At:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($lead->created_at))) ?></span></div>
                    <div><strong class="text-gray-600">Last Updated:</strong> <span class="text-gray-800"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($lead->updated_at))) ?></span></div>
                    <div><strong class="text-gray-600">Version:</strong> <span class="text-gray-800"><?= htmlspecialchars((string)$lead->version) ?></span></div>
                    <div><strong class="text-gray-600">Record Status:</strong> <span class="font-semibold <?= $lead->is_active ? 'text-green-600' : 'text-red-600' ?>"><?= $lead->is_active ? 'Active' : 'Inactive' ?></span></div>
                </div>
            </div>

             <div class="bg-white p-6 rounded-lg shadow-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Related Activities</h2>
                 <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php?filter_related_to_type=Lead&filter_related_to_id=<?= $lead->id ?>" class="block text-primary hover:text-teal-700 text-sm">
                    View All Related Activities &rarr;
                </a>
                <?php // Placeholder for a few recent activities if needed later ?>
            </div>
        </div>
    </div>

    <!-- Lead Status History Section -->
    <div class="mt-8 bg-white p-6 rounded-lg shadow-lg main-container"> <?php // Added main-container for consistent padding with header for this section ?>
        <h2 class="text-xl font-semibold text-gray-700 mb-4">Lead Status & Temperature History</h2>
        <?php if (empty($lead_history)): ?>
            <p class="text-gray-500">No status or temperature history found for this lead.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="th-cell">Timestamp</th>
                            <th class="th-cell">Changed By</th>
                            <th class="th-cell">Old Status</th>
                            <th class="th-cell">New Status</th>
                            <th class="th-cell">Old Temperature</th>
                            <th class="th-cell">New Temperature</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($lead_history as $history_item): ?>
                            <tr>
                                <td class="td-cell"><?= htmlspecialchars(date('M j, Y, g:i a', strtotime($history_item->change_timestamp))) ?></td>
                                <td class="td-cell"><?= htmlspecialchars((string)($history_item->changed_by_username) ?: 'N/A') ?></td>
                                <td class="td-cell"><?= $history_item->old_status ? "<span class='status-badge status-".strtolower(htmlspecialchars($history_item->old_status))."'>".htmlspecialchars($history_item->old_status)."</span>" : 'N/A' ?></td>
                                <td class="td-cell"><span class="status-badge status-<?= strtolower(htmlspecialchars($history_item->new_status)) ?>"><?= htmlspecialchars($history_item->new_status) ?></span></td>
                                <td class="td-cell"><?= $history_item->old_temperature ? "<span class='temp-badge temp-".strtolower(htmlspecialchars($history_item->old_temperature))."'>".htmlspecialchars($history_item->old_temperature)."</span>" : 'N/A' ?></td>
                                <td class="td-cell"><?= $history_item->new_temperature ? "<span class='temp-badge temp-".strtolower(htmlspecialchars($history_item->new_temperature))."'>".htmlspecialchars($history_item->new_temperature)."</span>" : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
    /* Re-use badge styles from leads_list.php or define globally */
    .status-badge, .temp-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height:1.2; }
    .status-new { background-color: #E0E7FF; color: #4338CA; }
    .status-contacted { background-color: #DBEAFE; color: #1D4ED8; }
    .status-qualified { background-color: #D1FAE5; color: #065F46; }
    .status-unqualified { background-color: #FEE2E2; color: #991B1B; }
    .status-converted { background-color: #F0FDF4; color: #166534; border: 1px solid #A7F3D0;}
    .temp-cold { background-color: #CCEFFE; color: #006BA2; }
    .temp-warm { background-color: #FFF3C4; color: #9A6700; }
    .temp-hot { background-color: #FFD8D2; color: #B53D2A; }
    .detail-label { display: block; font-size: 0.875rem; font-weight: 500; color: #4B5563; /* text-gray-600 */ }
    .detail-value { font-size: 1rem; color: #1F2937; /* text-gray-800 */ margin-top: 0.125rem; }
    .btn-success { padding: 0.5rem 1rem; background-color: #10B981; color: white; border-radius: 0.375rem; font-weight: 500; text-decoration: none; transition: background-color 0.2s; display:inline-block; }
    .btn-success:hover { background-color: #059669; }
    .btn-secondary { padding: 0.5rem 1rem; background-color: #E27B2B; color: white; border-radius: 0.375rem; font-weight: 500; text-decoration: none; transition: background-color 0.2s; display:inline-block; }
    .btn-secondary:hover { background-color: #D46F20; }
    .th-cell { padding: 0.5rem 0.75rem; text-align: left; font-size: 0.75rem; font-weight: 500; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; }
    .td-cell { padding: 0.5rem 0.75rem; font-size: 0.875rem; color: #374151; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
