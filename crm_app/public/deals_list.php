<?php
// public/deals_list.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Deal.php';
// User, Organisation, Contact classes are used by Deal::getRelatedDataForForms()
// No need to explicitly require them here if Deal.php handles its own dependencies for that method,
// or if an autoloader is robustly configured (which we don't assume for this step).
// For safety, if Deal.php's getRelatedDataForForms directly instantiates User, Organisation, Contact,
// they might need to be included if not already by auth_check or other means.
// However, the prompt for Deal.php showed it fetching data via direct SQL for these.
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Deals";
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

// Flash messages
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

// Filtering
$search_term = trim($_GET['search_term'] ?? '');
$filter_stage = $_GET['filter_stage'] ?? '';
$filter_assigned_user_id = filter_input(INPUT_GET, 'filter_assigned_user_id', FILTER_VALIDATE_INT) ?: '';
$filter_organisation_id = filter_input(INPUT_GET, 'filter_organisation_id', FILTER_VALIDATE_INT) ?: '';
$filter_contact_id = filter_input(INPUT_GET, 'filter_contact_id', FILTER_VALIDATE_INT) ?: '';
$filter_view_status = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? ($_GET['filter_view_status'] ?? 'active') : 'active';

$filters = [];
if (!empty($search_term)) $filters['search_term'] = $search_term;
if (!empty($filter_stage)) $filters['stage'] = $filter_stage;
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
    $deals = Deal::readAll($pdo, $filters);
    $deal_form_data = (new Deal($pdo))->getRelatedDataForForms();
    $users_for_filter = $deal_form_data['users'];
    $organisations_for_filter = $deal_form_data['organisations'];
    $contacts_for_filter = $deal_form_data['contacts'];
    $stage_options = Deal::getStageOptions();
} catch (Exception $e) {
    $error_message = "Error fetching deals: " . htmlspecialchars($e->getMessage());
    $deals = [];
    $users_for_filter = []; $organisations_for_filter = []; $contacts_for_filter = [];
    $stage_options = [];
    log_message('error', "Failed to fetch deals list: " . $e->getMessage());
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
        <a href="<?= htmlspecialchars($app_url_base) ?>/deal_edit.php" class="bg-primary text-white py-2 px-4 rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition duration-150 ease-in-out font-semibold">
            Add New Deal
        </a>
    </div>

    <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="GET" action="deals_list.php" class="bg-white p-4 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div>
                <label for="search_term" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name, org, contact..." class="mt-1 input-field">
            </div>
            <div>
                <label for="filter_stage" class="block text-sm font-medium text-gray-700">Stage</label>
                <select name="filter_stage" id="filter_stage" class="mt-1 input-field-select">
                    <option value="">All Stages</option>
                    <?php foreach ($stage_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($filter_stage === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
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
            <a href="deals_list.php" class="text-sm text-gray-600 hover:text-gray-800 mr-3">Clear Filters</a>
            <button type="submit" class="py-2 px-3 btn-secondary">Apply Filters</button>
        </div>
    </form>

    <div class="bg-white shadow-lg rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="th-cell">Deal Name</th>
                    <th class="th-cell">Organisation</th>
                    <th class="th-cell">Stage</th>
                    <th class="th-cell text-right">Amount</th>
                    <th class="th-cell">Close Date</th>
                    <th class="th-cell">Assigned To</th>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?><th class="th-cell text-center">Active</th><?php endif; ?>
                    <th class="th-cell">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($deals)): ?>
                    <tr><td colspan="<?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '8' : '7' ?>" class="td-cell text-center">No deals found.</td></tr>
                <?php else: ?>
                    <?php foreach ($deals as $deal): ?>
                        <tr class="<?= $deal->is_active ? '' : 'bg-gray-50 opacity-70' ?> <?= ($deal->stage === 'Won' || $deal->stage === 'Lost') ? 'opacity-80' : '' ?>">
                            <td class="td-cell font-medium">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/deal_view.php?id=<?= $deal->id ?>" class="text-primary hover:text-teal-700 font-semibold">
                                    <?= htmlspecialchars($deal->name) ?>
                                </a>
                            </td>
                            <td class="td-cell">
                                <?php if ($deal->organisation_id && $deal->organisation_name): ?>
                                    <a href="<?= htmlspecialchars($app_url_base) ?>/organisation_view.php?id=<?= $deal->organisation_id ?>" class="text-primary hover:text-teal-600"><?= htmlspecialchars($deal->organisation_name) ?></a>
                                <?php else: echo 'N/A'; endif; ?>
                            </td>
                            <td class="td-cell"><span class="stage-badge stage-<?= strtolower(str_replace(array(' ', '/'), '-', htmlspecialchars($deal->stage))) ?>"><?= htmlspecialchars($deal->stage) ?></span></td>
                            <td class="td-cell text-right"><?= '$' . htmlspecialchars(number_format($deal->amount, 2)) ?></td>
                            <td class="td-cell"><?= $deal->close_date ? htmlspecialchars(date('M j, Y', strtotime($deal->close_date))) : 'N/A' ?></td>
                            <td class="td-cell"><?= htmlspecialchars((string)($deal->assigned_user_name) ?: 'N/A') ?></td>
                            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <td class="td-cell text-center">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $deal->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $deal->is_active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="td-cell whitespace-nowrap">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/deal_view.php?id=<?= $deal->id ?>" class="text-primary hover:text-teal-600 mr-2">View</a>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/deal_edit.php?id=<?= $deal->id ?>" class="text-secondary hover:text-orange-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-xs text-gray-500">Total Deals: <?= count($deals) ?></p>
</div>
<style>
    .input-field { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; }
    .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; background-color: #fff; }
    .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
    .btn-secondary { padding: 0.5rem 0.75rem; border: 1px solid transparent; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: white; background-color: #E27B2B; }
    .btn-secondary:hover { background-color: #D46F20; }
    .th-cell { padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 500; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; }
    .td-cell { padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; }
    .stage-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height: 1.2; }
    .stage-prospecting { background-color: #E0E7FF; color: #4338CA; }
    .stage-qualification { background-color: #DBEAFE; color: #1D4ED8; }
    .stage-proposal { background-color: #FEF3C7; color: #92400E; }
    .stage-negotiation { background-color: #FCE7F3; color: #9D174D; }
    .stage-needs-analysis { background-color: #E0F2FE; color: #0C4A6E; } /* Example for Needs Analysis */
    .stage-won { background-color: #D1FAE5; color: #065F46; }
    .stage-lost { background-color: #FEE2E2; color: #991B1B; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
