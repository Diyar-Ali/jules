<?php
// public/deal_edit.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Deal.php';
// User, Organisation, Contact classes are used by Deal->getRelatedDataForForms()
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$deal_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_editing = (bool)$deal_id;

$deal = new Deal($pdo);
$related_data = $deal->getRelatedDataForForms();
$users_for_select = $related_data['users'];
$contacts_for_select = $related_data['contacts'];
$organisations_for_select = $related_data['organisations'];
$stage_options = $related_data['stages'];

// Form field initial values
$form_name = ''; $form_stage = 'Prospecting'; $form_amount = ''; $form_close_date = '';
$form_probability = ''; $form_description = '';
$form_organisation_id = null; $form_contact_id = null; $form_assigned_user_id = null;
$form_is_active = true; $current_version = null;

// Pre-fill from GET parameters if creating
if (!$is_editing) {
    $form_organisation_id = filter_input(INPUT_GET, 'organisation_id', FILTER_VALIDATE_INT) ?: null;
    $form_contact_id = filter_input(INPUT_GET, 'contact_id', FILTER_VALIDATE_INT) ?: null;
    $current_version = 1; // New records start at version 1
}

if ($is_editing) {
    if (!$deal->read($deal_id)) {
        $_SESSION['error_message'] = 'Deal not found.';
        header("Location: {$app_url_base}/deals_list.php");
        exit;
    }
    // Authorization for inactive deals
    if (!$deal->is_active && !$is_admin) {
        $_SESSION['error_message'] = 'You do not have permission to edit this inactive deal.';
        log_message('warning', "User ID {$current_user_id} attempt to edit inactive deal ID {$deal_id}");
        header("Location: {$app_url_base}/deals_list.php");
        exit;
    }

    $page_title = "Edit Deal: " . htmlspecialchars($deal->name);
    // Populate form fields
    $form_name = $deal->name; $form_stage = $deal->stage; $form_amount = $deal->amount;
    $form_close_date = $deal->close_date;
    $form_probability = $deal->probability !== null ? $deal->probability * 100 : ''; // Display as percentage
    $form_description = $deal->description; $form_organisation_id = $deal->organisation_id;
    $form_contact_id = $deal->contact_id; $form_assigned_user_id = $deal->assigned_user_id;
    $form_is_active = $deal->is_active; $current_version = $deal->version;
} else {
    $page_title = "Add New Deal";
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('warning', "CSRF token validation failed for deal " . ($is_editing ? "edit ID {$deal_id}" : "create") . " by user ID {$current_user_id}");
    } else {
        $form_name = trim($_POST['name'] ?? '');
        $form_stage = $_POST['stage'] ?? 'Prospecting';

        $raw_amount = $_POST['amount'] ?? '';
        $form_amount = ($raw_amount === '') ? null : filter_var($raw_amount, FILTER_VALIDATE_FLOAT);
        if ($raw_amount !== '' && $form_amount === false) { // Check if it was not empty but failed validation
            $error_message = "Invalid format for Amount. Please enter a valid number or leave blank for 0.";
        } elseif ($form_amount === null) { // Treat blank as 0 for required amount
            $form_amount = 0.00;
        }


        $form_close_date = !empty($_POST['close_date']) ? trim($_POST['close_date']) : null;
        if ($form_close_date === '') $form_close_date = null;

        $form_probability_input = trim($_POST['probability'] ?? '');
        $form_probability = null; // Default to null if not provided or invalid
        if ($form_probability_input !== '') {
            $form_probability_percent = filter_var($form_probability_input, FILTER_VALIDATE_FLOAT); // Allow float for percent too
            if ($form_probability_percent !== false && $form_probability_percent >= 0 && $form_probability_percent <= 100) {
                $form_probability = $form_probability_percent / 100; // Convert percentage to decimal
            } else if ($form_probability_percent !== false) { // Valid number but out of range
                 $error_message = "Probability must be between 0 and 100.";
            }
            // If filter_var returns false, it means not a valid number, error_message might be set or rely on other checks
            // If it's critical to distinguish "not a number" from "out of range", more specific error handling needed
            if ($form_probability_percent === false && empty($error_message)) { // If not already an error
                 $error_message = "Invalid format for Probability. Enter a number (e.g., 75 for 75%).";
            }
        }


        $form_description = trim($_POST['description'] ?? '');
        $form_organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;
        $form_contact_id = !empty($_POST['contact_id']) ? filter_var($_POST['contact_id'], FILTER_VALIDATE_INT) : null;
        $form_assigned_user_id = !empty($_POST['assigned_user_id']) ? filter_var($_POST['assigned_user_id'], FILTER_VALIDATE_INT) : null;

        if ($is_admin) {
            $form_is_active = isset($_POST['is_active']);
        } elseif ($is_editing) {
            $form_is_active = $deal->is_active;
        } else {
            $form_is_active = true;
        }

        if (empty($form_name)) $error_message = 'Deal name is required.';
        elseif (empty($form_organisation_id)) $error_message = 'Organisation is required for a deal.';
        elseif ($form_amount === null || $form_amount === false || $form_amount < 0) $error_message = 'A valid, non-negative Amount is required.'; // Should be caught by earlier specific check
        elseif (empty($form_stage) || !in_array($form_stage, $stage_options)) $error_message = 'A valid Stage is required.';
        elseif ($form_probability !== null && ($form_probability < 0 || $form_probability > 1)) { // Already checked, but good for defense
            $error_message = "Probability must be between 0% and 100%.";
        }
        if ($form_close_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_close_date)) {
            $error_message = "Invalid format for Expected Close Date. Please use YYYY-MM-DD.";
        }


        if (empty($error_message)) {
            try {
                $deal_to_save = $is_editing ? $deal : new Deal($pdo);

                $deal_to_save->name = $form_name; $deal_to_save->stage = $form_stage; $deal_to_save->amount = $form_amount;
                $deal_to_save->close_date = $form_close_date; $deal_to_save->probability = $form_probability;
                $deal_to_save->description = $form_description; $deal_to_save->organisation_id = $form_organisation_id;
                $deal_to_save->contact_id = $form_contact_id; $deal_to_save->assigned_user_id = $form_assigned_user_id;
                $deal_to_save->is_active = $form_is_active;

                if ($is_editing) {
                    $submitted_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);
                    if ($submitted_version === false || $submitted_version !== $current_version) {
                         throw new Exception("Data conflict. The deal record was updated by someone else. Please refresh and try again.");
                    }
                    // $deal_to_save object already has id and version if $is_editing and $deal->read() was successful.
                    // Ensure version to check against is set on the object instance being updated.
                    $deal_to_save->version = $current_version;


                    if ($deal_to_save->update()) {
                        $_SESSION['success_message'] = "Deal '".htmlspecialchars($deal_to_save->name)."' updated successfully!";
                        header("Location: {$app_url_base}/deals_list.php"); exit;
                    }
                } else { // Creating new
                    if ($deal_to_save->create($current_user_id)) {
                        $_SESSION['success_message'] = "Deal '".htmlspecialchars($deal_to_save->name)."' created successfully!";
                        header("Location: {$app_url_base}/deals_list.php"); exit;
                    }
                }
            } catch (Exception $e) {
                $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
                log_message('error', "UI Exception for deal " . ($is_editing ? "edit ID {$deal_id}" : "create") . " by user {$current_user_id}: " . $e->getMessage());
                 if ($is_editing) {
                    $tempDeal = new Deal($pdo);
                    if($tempDeal->read($deal_id)) $current_version = $tempDeal->version; else $current_version = null;
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
            <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php" class="btn btn-muted">&larr; Back to List</a>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
        <?php endif; ?>

        <form action="deal_edit.php<?= $is_editing ? '?id='.$deal_id : '' ?>" method="POST" class="bg-white p-6 sm:p-8 rounded-lg shadow-lg space-y-6">
            <?= csrf_input_field() ?>
            <?php if ($is_editing): ?><input type="hidden" name="version" value="<?= htmlspecialchars((string)$current_version) ?>"><?php endif; ?>

            <div>
                <label for="name" class="form-label">Deal Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($form_name) ?>" required class="input-field">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="organisation_id" class="form-label">Organisation <span class="text-red-500">*</span></label>
                    <select name="organisation_id" id="organisation_id" required class="input-field-select">
                        <option value="">Select Organisation...</option>
                        <?php foreach ($organisations_for_select as $item): ?>
                            <option value="<?= htmlspecialchars((string)$item['id']) ?>" <?= ($form_organisation_id == $item['id']) ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="amount" class="form-label">Amount ($) <span class="text-red-500">*</span></label>
                    <input type="number" name="amount" id="amount" value="<?= htmlspecialchars((string)$form_amount) ?>" required step="0.01" placeholder="e.g., 10000.00" class="input-field">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label for="stage" class="form-label">Stage <span class="text-red-500">*</span></label>
                    <select name="stage" id="stage" required class="input-field-select">
                        <?php foreach ($stage_options as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= ($form_stage === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="probability" class="form-label">Probability (%)</label>
                    <input type="number" name="probability" id="probability" value="<?= htmlspecialchars($form_probability !== null ? (string)($form_probability * 100) : '') ?>" step="1" min="0" max="100" placeholder="e.g., 75 for 75%" class="input-field">
                </div>
                 <div>
                    <label for="close_date" class="form-label">Expected Close Date</label>
                    <input type="date" name="close_date" id="close_date" value="<?= htmlspecialchars((string)$form_close_date) ?>" class="input-field">
                </div>
            </div>

            <div>
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" rows="4" class="input-field"><?= htmlspecialchars($form_description) ?></textarea>
            </div>

            <h3 class="text-lg font-medium text-gray-800 pt-4 border-t mt-6">Associations</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="contact_id" class="form-label">Primary Contact</label>
                    <select name="contact_id" id="contact_id" class="input-field-select">
                        <option value="">None</option>
                        <?php foreach ($contacts_for_select as $item): ?>
                            <option value="<?= htmlspecialchars((string)$item['id']) ?>" <?= ($form_contact_id == $item['id']) ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="assigned_user_id" class="form-label">Assigned To</label>
                    <select name="assigned_user_id" id="assigned_user_id" class="input-field-select">
                        <option value="">Unassigned</option>
                         <?php foreach ($users_for_select as $user): ?>
                            <option value="<?= htmlspecialchars((string)$user['id']) ?>" <?= ($form_assigned_user_id == $user['id']) ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($is_admin): ?>
            <div class="flex items-center mt-4">
                <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?> class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                <label for="is_active" class="ml-2 block text-sm text-gray-900">Deal Record is Active</label>
            </div>
            <?php elseif ($is_editing): ?>
                 <p class="text-sm text-gray-600 mt-2">Record Status: <span class="font-semibold"><?= $deal->is_active ? 'Active' : 'Inactive' ?></span></p>
            <?php endif; ?>

            <div class="pt-5">
                <button type="submit" class="btn-primary-full">
                    <?= $is_editing ? 'Save Changes' : 'Create Deal' ?>
                </button>
            </div>

            <?php if ($is_editing): ?>
            <p class="text-xs text-gray-500 mt-1">Created: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($deal->created_at))) ?>, Last Updated: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($deal->updated_at))) ?>, Version: <?= htmlspecialchars((string)$deal->version) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <?php require_once __DIR__ . '/../templates/footer.php'; ?>
    <style>
        .input-field { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; }
        .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; background-color: #fff; }
        .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
        .btn-primary { padding: 0.5rem 1rem; border: 1px solid transparent; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: white; background-color: #4FD1C5; display: flex; justify-content: center; }
        .btn-primary:hover { background-color: #3ABAB0; }
        .btn-primary:disabled { background-color: #9CA3AF; cursor: not-allowed; }
    </style>
