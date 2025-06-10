<?php
// public/leads_list.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Lead.php';
require_once __DIR__ . '/../classes/User.php'; // For assigned user filter
require_once __DIR__ . '/../classes/Organisation.php'; // For organisation filter
require_once __DIR__ . '/../classes/Contact.php'; // For contact filter
require_once __DIR__ . '/../classes/LeadStatusHistory.php'; // For status/temp options
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Leads";
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Filtering
$search_term = trim($_GET['search_term'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_temperature = $_GET['filter_temperature'] ?? '';
$filter_assigned_user_id = filter_input(INPUT_GET, 'filter_assigned_user_id', FILTER_VALIDATE_INT) ?: '';
$filter_organisation_id = filter_input(INPUT_GET, 'filter_organisation_id', FILTER_VALIDATE_INT) ?: '';
$filter_contact_id = filter_input(INPUT_GET, 'filter_contact_id', FILTER_VALIDATE_INT) ?: '';
$filter_view_status = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? ($_GET['filter_view_status'] ?? 'active') : 'active';

$filters = [];
if (!empty($search_term)) $filters['search_term'] = $search_term;
if (!empty($filter_status)) $filters['status'] = $filter_status;
if (!empty($filter_temperature)) $filters['temperature'] = $filter_temperature;
if (!empty($filter_assigned_user_id)) $filters['assigned_user_id'] = $filter_assigned_user_id;
if (!empty($filter_organisation_id)) $filters['organisation_id'] = $filter_organisation_id;
if (!empty($filter_contact_id)) $filters['contact_id'] = $filter_contact_id;

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    if ($filter_view_status === 'active') $filters['is_active'] = true;
    elseif ($filter_view_status === 'inactive') $filters['is_active'] = false;
    elseif ($filter_view_status === 'all') $filters['view'] = 'all';
} else {
    $filters['is_active'] = true; // Non-admins always see active
}

try {
    $leads = Lead::readAll($pdo, $filters);
    $lead_form_data = (new Lead($pdo))->getRelatedDataForForms(); // For filter dropdowns
    $users_for_filter = $lead_form_data['users'];
    $organisations_for_filter = $lead_form_data['organisations'];
    $contacts_for_filter = $lead_form_data['contacts'];
    $status_options = LeadStatusHistory::getStatusOptions();
    $temperature_options = LeadStatusHistory::getTemperatureOptions();
} catch (Exception $e) {
    $error_message = "Error fetching leads: " . htmlspecialchars($e->getMessage());
    $leads = [];
    $users_for_filter = []; $organisations_for_filter = []; $contacts_for_filter = [];
    $status_options = []; $temperature_options = [];
    log_message('error', "Failed to fetch leads list: " . $e->getMessage());
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
        <a href="<?= htmlspecialchars($app_url_base) ?>/lead_edit.php" class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
            Add New Lead
        </a>
    </div>

    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="GET" action="leads_list.php" class="bg-white p-4 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div>
                <label for="search_term" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name, source, desc..." class="mt-1 input-field">
            </div>
            <div>
                <label for="filter_status" class="block text-sm font-medium text-gray-700">Status</label>
                <select name="filter_status" id="filter_status" class="mt-1 input-field-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($status_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_status === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_temperature" class="block text-sm font-medium text-gray-700">Temperature</label>
                <select name="filter_temperature" id="filter_temperature" class="mt-1 input-field-select">
                    <option value="">All Temperatures</option>
                    <?php foreach ($temperature_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_temperature === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_assigned_user_id" class="block text-sm font-medium text-gray-700">Assigned To</label>
                <select name="filter_assigned_user_id" id="filter_assigned_user_id" class="mt-1 input-field-select">
                    <option value="">Any User</option>
                    <?php foreach ($users_for_filter as $user): ?>
                        <option value="<?= htmlspecialchars((string)$user['id']) ?>" <?= ($filter_assigned_user_id == $user['id']) ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="filter_organisation_id" class="block text-sm font-medium text-gray-700">Organisation</label>
                <select name="filter_organisation_id" id="filter_organisation_id" class="mt-1 input-field-select">
                    <option value="">Any Organisation</option>
                    <?php foreach ($organisations_for_filter as $org): ?>
                        <option value="<?= htmlspecialchars((string)$org['id']) ?>" <?= ($filter_organisation_id == $org['id']) ? 'selected' : '' ?>><?= htmlspecialchars($org['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
             <div>
                <label for="filter_contact_id" class="block text-sm font-medium text-gray-700">Contact</label>
                <select name="filter_contact_id" id="filter_contact_id" class="mt-1 input-field-select">
                    <option value="">Any Contact</option>
                    <?php foreach ($contacts_for_filter as $contact_filter): ?>
                        <option value="<?= htmlspecialchars((string)$contact_filter['id']) ?>" <?= ($filter_contact_id == $contact_filter['id']) ? 'selected' : '' ?>><?= htmlspecialchars($contact_filter['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <div>
                <label for="filter_view_status" class="block text-sm font-medium text-gray-700">Record Status</label>
                <select name="filter_view_status" id="filter_view_status" class="mt-1 input-field-select">
                    <option value="active" <?= ($filter_view_status === 'active') ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= ($filter_view_status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                    <option value="all" <?= ($filter_view_status === 'all') ? 'selected' : '' ?>>Show All</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="mt-4 text-right">
            <a href="leads_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-3">Clear Filters</a>
            <button type="submit" class="py-2 px-3 btn-secondary">Apply Filters</button>
        </div>
    </form>

    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="th-cell">Name</th>
                    <th class="th-cell">Status</th>
                    <th class="th-cell">Temperature</th>
                    <th class="th-cell">Value</th>
                    <th class="th-cell">Contact</th>
                    <th class="th-cell">Organisation</th>
                    <th class="th-cell">Assigned To</th>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?><th class="th-cell text-center">Active</th><?php endif; ?>
                    <th class="th-cell">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($leads)): ?>
                    <tr><td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '9' : '8' ?>" class="td-cell text-center">No leads found.</td></tr>
                <?php else: ?>
                    <?php foreach ($leads as $lead): ?>
                        <tr class="<?= $lead->is_active ? '' : 'bg-gray-50 opacity-70' ?> <?= $lead->isConverted() ? 'bg-green-50 opacity-80' : '' ?>">
                            <td class="td-cell font-medium">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/lead_view.php?id=<?= $lead->id ?>" class="text-primary hover:text-teal-700 font-semibold">
                                    <?= htmlspecialchars($lead->name) ?>
                                </a>
                                <?php if ($lead->isConverted()): ?>
                                    <span class="block text-xs text-green-600">(Converted to Deal ID: <?= htmlspecialchars((string)$lead->converted_to_deal_id) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-cell"><span class="status-badge status-<?= strtolower(htmlspecialchars($lead->status)) ?>"><?= htmlspecialchars($lead->status) ?></span></td>
                            <td class="td-cell"><span class="temp-badge temp-<?= strtolower(htmlspecialchars($lead->temperature)) ?>"><?= htmlspecialchars($lead->temperature) ?></span></td>
                            <td class="td-cell text-right"><?= $lead->value !== null ? '$' . htmlspecialchars(number_format($lead->value, 2)) : 'N/A' ?></td>
                            <td class="td-cell">
                                <?php if ($lead->contact_id && $lead->contact_name): ?>
                                    <a href="<?= htmlspecialchars($app_url_base) ?>/contact_view.php?id=<?= $lead->contact_id ?>" class="text-primary hover:text-teal-600"><?= htmlspecialchars($lead->contact_name) ?></a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <td class="td-cell">
                                <?php if ($lead->organisation_id && $lead->organisation_name): ?>
                                    <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $lead->organisation_id ?>" class="text-primary hover:text-teal-600"><?= htmlspecialchars($lead->organisation_name) ?></a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <td class="td-cell"><?= htmlspecialchars((string)($lead->assigned_user_name) ?: 'N/A') ?></td>
                            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <td class="td-cell text-center">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $lead->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $lead->is_active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="td-cell whitespace-nowrap">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/lead_view.php?id=<?= $lead->id ?>" class="text-primary hover:text-teal-600 mr-2">View</a>
                                <?php if (!$lead->isConverted()): ?>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/lead_edit.php?id=<?= $lead->id ?>" class="text-secondary hover:text-orange-700">Edit</a>
                                <?php else: ?>
                                <span class="text-gray-400 cursor-not-allowed" title="Cannot edit a converted lead. View the deal instead.">Edit</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-xs text-gray-500">Total Leads: <?= count($leads) ?></p>
</div>
<style>
    .input-field { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; }
    .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; background-color: #fff; }
    .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
    .btn-secondary { padding: 0.5rem 0.75rem; border: 1px solid transparent; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: white; background-color: #E27B2B; } /* Tailwind secondary color */
    .btn-secondary:hover { background-color: #D46F20; }
    .th-cell { padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 500; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; }
    .td-cell { padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; }
    .status-badge, .temp-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height: 1.2; }
    .status-new { background-color: #E0E7FF; color: #4338CA; }
    .status-contacted { background-color: #DBEAFE; color: #1D4ED8; }
    .status-qualified { background-color: #D1FAE5; color: #065F46; }
    .status-unqualified { background-color: #FEE2E2; color: #991B1B; }
    .status-converted { background-color: #F0FDF4; color: #166534; border: 1px solid #A7F3D0; }
    .temp-cold { background-color: #CCEFFE; color: #006BA2; }
    .temp-warm { background-color: #FFF3C4; color: #9A6700; }
    .temp-hot { background-color: #FFD8D2; color: #B53D2A; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
