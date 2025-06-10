<?php
// public/lead_convert.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Lead.php';
// Deal class is needed for Deal::getStageOptions(), but convertToDeal in Lead.php handles Deal creation.
// For this subtask, assume Deal::getStageOptions() will exist or provide a placeholder.
// If Deal.php and its getStageOptions method are not ready, the page will use a fallback.
if (file_exists(__DIR__ . '/../classes/Deal.php')) {
    require_once __DIR__ . '/../classes/Deal.php';
}
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];

$lead_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$lead_id) {
    $_SESSION['error_message'] = 'Invalid lead ID specified for conversion.';
    header("Location: {$app_url_base}/leads_list.php");
    exit;
}

$lead = new Lead($pdo);
if (!$lead->read($lead_id)) {
    $_SESSION['error_message'] = 'Lead not found for conversion.';
    header("Location: {$app_url_base}/leads_list.php");
    exit;
}

// Authorization & Pre-checks
if (!$lead->is_active) {
    $_SESSION['error_message'] = 'Cannot convert an inactive lead.';
    header("Location: {$app_url_base}/lead_view.php?id={$lead_id}");
    exit;
}
if ($lead->isConverted()) {
    $_SESSION['error_message'] = 'This lead has already been converted.';
    header("Location: {$app_url_base}/lead_view.php?id={$lead_id}");
    exit;
}
 if (!$lead->organisation_id) {
    $_SESSION['error_message'] = 'Lead must be associated with an Organisation before conversion.';
    header("Location: {$app_url_base}/lead_edit.php?id={$lead_id}");
    exit;
}


$page_title = "Convert Lead: " . htmlspecialchars($lead->name);

// Deal Stage options
$deal_stage_options = [];
if (class_exists('Deal') && method_exists('Deal', 'getStageOptions')) {
    $deal_stage_options = Deal::getStageOptions();
} else {
    $deal_stage_options = ['Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Needs Analysis'];
    // Removed 'Won', 'Lost' as they are typically not initial stages for conversion.
}
if(empty($deal_stage_options)) { // Further fallback if getStageOptions() returned empty
     $deal_stage_options = ['Prospecting', 'Qualification'];
}


// Form field initial values
$form_deal_name = $lead->name;
$form_initial_deal_stage = $deal_stage_options[0];

$error_message = '';
$success_message = ''; // Not used here due to redirect

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('warning', "CSRF token failed on lead conversion for lead ID {$lead_id} by user {$current_user_id}");
    } else {
        $form_deal_name = trim($_POST['deal_name'] ?? $lead->name);
        $form_initial_deal_stage = $_POST['initial_deal_stage'] ?? $deal_stage_options[0];

        if (empty($form_deal_name)) {
            $error_message = 'Deal Name is required.';
        } elseif (!in_array($form_initial_deal_stage, $deal_stage_options)) {
            $error_message = 'Invalid Initial Deal Stage selected.';
        } else {
            try {
                if (!class_exists('Deal')) {
                     throw new Exception("Deal class not available. Cannot complete conversion. Please ensure Deal module is implemented.");
                }

                $new_deal_id = $lead->convertToDeal($current_user_id, $form_deal_name, $form_initial_deal_stage);

                if ($new_deal_id) {
                    $_SESSION['success_message'] = "Lead '".htmlspecialchars($lead->name)."' successfully converted to Deal '".htmlspecialchars($form_deal_name)."'!";
                    log_message('info', "User ID {$current_user_id} converted lead ID {$lead->id} to new deal ID {$new_deal_id}.");
                    header("Location: {$app_url_base}/deal_view.php?id={$new_deal_id}");
                    exit;
                } else {
                    $error_message = 'Failed to convert lead. The lead or deal update might have failed. Please check logs.';
                }
            } catch (Exception $e) {
                $error_message = "An error occurred during conversion: " . htmlspecialchars($e->getMessage());
                log_message('error', "Exception during lead conversion for lead ID {$lead_id} by user {$current_user_id}: " . $e->getMessage());
            }
        }
    }
}

generate_csrf_token();
require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <a href="<?= htmlspecialchars($app_url_base) ?>/lead_view.php?id=<?= $lead_id ?>" class="py-2 px-3 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">&larr; Back to Lead View</a>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 border border-red-300 rounded-md text-sm dismissable-alert"><?= $error_message ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-1 bg-white p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Lead Summary</h2>
            <div class="space-y-3 text-sm">
                <div><strong class="text-gray-600">Name:</strong> <span class="text-gray-800"><?= htmlspecialchars($lead->name) ?></span></div>
                <div><strong class="text-gray-600">Status:</strong> <span class="status-badge status-<?= strtolower(htmlspecialchars($lead->status)) ?>"><?= htmlspecialchars($lead->status) ?></span></div>
                <div><strong class="text-gray-600">Value:</strong> <span class="text-gray-800"><?= $lead->value !== null ? '$' . htmlspecialchars(number_format($lead->value, 2)) : 'N/A' ?></span></div>
                <?php if ($lead->contact_name): ?>
                <div><strong class="text-gray-600">Contact:</strong> <span class="text-gray-800"><?= htmlspecialchars($lead->contact_name) ?></span></div>
                <?php endif; ?>
                <?php if ($lead->organisation_name): ?>
                <div><strong class="text-gray-600">Organisation:</strong> <span class="text-gray-800"><?= htmlspecialchars($lead->organisation_name) ?></span></div>
                <?php endif; ?>
                 <div><strong class="text-gray-600">Expected Close:</strong> <span class="text-gray-800"><?= $lead->expected_close_date ? htmlspecialchars(date('M j, Y', strtotime($lead->expected_close_date))) : 'N/A' ?></span></div>
            </div>
        </div>

        <div class="md:col-span-2 bg-white p-6 sm:p-8 rounded-lg shadow-lg">
            <h2 class="text-xl font-semibold text-gray-700 mb-6">Confirm Conversion to Deal</h2>
            <form action="lead_convert.php?id=<?= $lead_id ?>" method="POST" class="space-y-6">
                <?= csrf_input_field() ?>
                <div>
                    <label for="deal_name" class="block text-sm font-medium text-gray-700">New Deal Name <span class="text-red-500">*</span></label>
                    <input type="text" name="deal_name" id="deal_name" value="<?= htmlspecialchars($form_deal_name) ?>" required class="mt-1 input-field">
                </div>
                <div>
                    <label for="initial_deal_stage" class="block text-sm font-medium text-gray-700">Initial Deal Stage <span class="text-red-500">*</span></label>
                    <select name="initial_deal_stage" id="initial_deal_stage" required class="mt-1 input-field-select">
                        <?php foreach ($deal_stage_options as $stage): ?>
                            <option value="<?= htmlspecialchars($stage) ?>" <?= ($form_initial_deal_stage === $stage) ? 'selected' : '' ?>><?= htmlspecialchars($stage) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">The deal amount will be derived from the lead value (<?= $lead->value !== null ? '$' . htmlspecialchars(number_format($lead->value, 2)) : 'N/A' ?>).</p>
                     <p class="mt-1 text-xs text-gray-500">Expected close date will be: <?= $lead->expected_close_date ? htmlspecialchars(date('M j, Y', strtotime($lead->expected_close_date))) : 'N/A' ?>.</p>
                </div>
                <div class="pt-5">
                    <button type="submit" class="w-full btn-success flex justify-center py-2 px-4">
                        Confirm Conversion & Create Deal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<style>
    .input-field { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; }
    .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; background-color: #fff; }
    .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
    .btn-success { padding: 0.5rem 1rem; background-color: #10B981; color: white; border-radius: 0.375rem; font-weight: 500; text-decoration: none; transition: background-color 0.2s; display:inline-block; }
    .btn-success:hover { background-color: #059669; }
    .status-badge, .temp-badge { padding: 0.25em 0.6em; border-radius: 0.375rem; font-size: 0.75rem; font-weight: 500; display: inline-block; line-height:1.2; }
    .status-new { background-color: #E0E7FF; color: #4338CA; }
    .status-contacted { background-color: #DBEAFE; color: #1D4ED8; }
    .status-qualified { background-color: #D1FAE5; color: #065F46; }
    .status-unqualified { background-color: #FEE2E2; color: #991B1B; }
    .status-converted { background-color: #F0FDF4; color: #166534; border: 1px solid #A7F3D0;}
</style>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
