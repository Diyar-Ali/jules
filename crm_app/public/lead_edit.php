<?php
// public/lead_edit.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Lead.php';
require_once __DIR__ . '/../classes/LeadStatusHistory.php'; // For status/temp options
// User, Organisation, Contact classes are used by Lead->getRelatedDataForForms()
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/../includes/csrf_helper.php';


$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$lead_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_editing = (bool)$lead_id;

$lead = new Lead($pdo);
$related_data = $lead->getRelatedDataForForms();
$users_for_select = $related_data['users'];
$contacts_for_select = $related_data['contacts'];
$organisations_for_select = $related_data['organisations'];
$status_options = $related_data['statuses'];
$temperature_options = $related_data['temperatures'];


// Form field initial values
$form_name = ''; $form_source = ''; $form_status = 'New'; $form_temperature = 'Cold';
$form_description = ''; $form_value = ''; $form_expected_close_date = '';
$form_contact_id = null; $form_organisation_id = null; $form_assigned_user_id = null;
$form_is_active = true; $current_version = null;
$is_converted_lead = false;

// Pre-fill from GET parameters if creating
if (!$is_editing) {
    $form_contact_id = filter_input(INPUT_GET, 'contact_id', FILTER_VALIDATE_INT) ?: null;
    $form_organisation_id = filter_input(INPUT_GET, 'organisation_id', FILTER_VALIDATE_INT) ?: null;
    // New leads always start with version 1, set by Lead class constructor or DB default
    $current_version = 1; // For the hidden field if needed, though not strictly for create
}


if ($is_editing) {
    if (!$lead->read($lead_id)) {
        $_SESSION['error_message'] = 'Lead not found.';
        header("Location: {$app_url_base}/leads_list.php");
        exit;
    }
    if (!$lead->is_active && !$is_admin) {
        $_SESSION['error_message'] = 'You do not have permission to edit this inactive lead.';
        log_message('warning', "User ID {$current_user_id} attempt to edit inactive lead ID {$lead_id}");
        header("Location: {$app_url_base}/leads_list.php");
        exit;
    }
    $is_converted_lead = $lead->isConverted();

    $page_title = "Edit Lead: " . htmlspecialchars($lead->name);
    $form_name = $lead->name; $form_source = $lead->source; $form_status = $lead->status;
    $form_temperature = $lead->temperature; $form_description = $lead->description;
    $form_value = $lead->value; $form_expected_close_date = $lead->expected_close_date;
    $form_contact_id = $lead->contact_id; $form_organisation_id = $lead->organisation_id;
    $form_assigned_user_id = $lead->assigned_user_id; $form_is_active = $lead->is_active;
    $current_version = $lead->version;
} else {
    $page_title = "Add New Lead";
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_editing && $is_converted_lead) { // Re-check if editing a converted lead
        $error_message = "This lead is already converted and cannot be modified.";
        log_message('warning', "Attempt to POST edit a converted lead ID {$lead_id} by user ID {$current_user_id}");
    } elseif (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('warning', "CSRF token validation failed on lead " . ($is_editing ? "edit for ID {$lead_id}" : "create") . " by user ID {$current_user_id}");
    } else {
        $form_name = trim($_POST['name'] ?? '');
        $form_source = trim($_POST['source'] ?? '');
        $form_status = $_POST['status'] ?? 'New';
        $form_temperature = $_POST['temperature'] ?? 'Cold';
        $form_description = trim($_POST['description'] ?? '');

        $raw_value = $_POST['value'] ?? '';
        $form_value = ($raw_value === '') ? null : filter_var($raw_value, FILTER_VALIDATE_FLOAT);
        if ($raw_value !== '' && $form_value === false) {
            $error_message = "Invalid format for Value. Please enter a valid number or leave blank.";
        }

        $form_expected_close_date = !empty($_POST['expected_close_date']) ? trim($_POST['expected_close_date']) : null;
        if ($form_expected_close_date === '') $form_expected_close_date = null; // Ensure empty string becomes NULL

        $form_contact_id = !empty($_POST['contact_id']) ? filter_var($_POST['contact_id'], FILTER_VALIDATE_INT) : null;
        $form_organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;
        $form_assigned_user_id = !empty($_POST['assigned_user_id']) ? filter_var($_POST['assigned_user_id'], FILTER_VALIDATE_INT) : null;

        if ($is_admin) {
            $form_is_active = isset($_POST['is_active']);
        } elseif ($is_editing) { // Non-admin editing
            $form_is_active = $lead->is_active; // Keep original status from loaded lead
        } else { // Non-admin creating
            $form_is_active = true;
        }

        if (empty($form_name)) $error_message = 'Lead name is required.';
        // Date format validation (basic YYYY-MM-DD check)
        if ($form_expected_close_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_expected_close_date)) {
            $error_message = "Invalid format for Expected Close Date. Please use YYYY-MM-DD.";
        }


        if (empty($error_message)) {
            try {
                $lead_to_save = $is_editing ? $lead : new Lead($pdo); // Use existing $lead object if editing

                $lead_to_save->name = $form_name; $lead_to_save->source = $form_source; $lead_to_save->status = $form_status;
                $lead_to_save->temperature = $form_temperature; $lead_to_save->description = $form_description;
                $lead_to_save->value = $form_value; $lead_to_save->expected_close_date = $form_expected_close_date;
                $lead_to_save->contact_id = $form_contact_id; $lead_to_save->organisation_id = $form_organisation_id;
                $lead_to_save->assigned_user_id = $form_assigned_user_id; $lead_to_save->is_active = $form_is_active;

                if ($is_editing) {
                    $submitted_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);
                    if ($submitted_version === false || $submitted_version !== $current_version) { // $current_version is from initial page load
                         throw new Exception("Data conflict. The lead record was updated by someone else. Please refresh and try again.");
                    }
                    $lead_to_save->id = $lead_id;
                    $lead_to_save->version = $current_version; // Set the version that we expect to be in the DB

                    if ($lead_to_save->update($current_user_id)) {
                        $_SESSION['success_message'] = "Lead '".htmlspecialchars($lead_to_save->name)."' updated successfully!";
                        header("Location: {$app_url_base}/leads_list.php"); exit;
                    }
                } else {
                    // $lead_to_save->version is already 1 from constructor
                    if ($lead_to_save->create($current_user_id)) {
                        $_SESSION['success_message'] = "Lead '".htmlspecialchars($lead_to_save->name)."' created successfully!";
                        header("Location: {$app_url_base}/leads_list.php"); exit;
                    }
                }
            } catch (Exception $e) {
                $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
                log_message('error', "UI Exception for lead " . ($is_editing ? "edit ID {$lead_id}" : "create") . " by user {$current_user_id}: " . $e->getMessage());
                if ($is_editing) { // Refresh version from DB in case of conflict or other error during POST
                    $tempLead = new Lead($pdo);
                    if($tempLead->read($lead_id)) $current_version = $tempLead->version; else $current_version = null;
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
            <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php" class="btn btn-muted">&larr; Back to List</a>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert"><?= $error_message ?></div>
        <?php endif; ?>

        <?php if ($is_editing && $is_converted_lead): ?>
            <div class="alert alert-warning mb-6">
                This lead has been converted and can no longer be edited.
                <?php if ($lead->converted_to_deal_id): ?>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/deal_view.php?id=<?= $lead->converted_to_deal_id ?>" class="font-semibold underline hover:text-yellow-800">
                        View Associated Deal &rarr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <form action="lead_edit.php<?= $is_editing ? '?id='.$lead_id : '' ?>" method="POST" class="bg-white p-6 sm:p-8 rounded-lg shadow-lg space-y-6">
            <?= csrf_input_field() ?>
            <?php if ($is_editing): ?><input type="hidden" name="version" value="<?= htmlspecialchars((string)$current_version) ?>"><?php endif; ?>

            <fieldset <?= ($is_editing && $is_converted_lead) ? 'disabled' : '' ?> class="<?= ($is_editing && $is_converted_lead) ? 'opacity-60 cursor-not-allowed' : '' ?>">
                <div>
                    <label for="name" class="form-label">Lead Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="name" value="<?= htmlspecialchars($form_name) ?>" required class="input-field">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="input-field-select">
                            <?php foreach ($status_options as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>" <?= ($form_status === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="temperature" class="form-label">Temperature</label>
                        <select name="temperature" id="temperature" class="input-field-select">
                             <?php foreach ($temperature_options as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>" <?= ($form_temperature === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="value" class="form-label">Value ($)</label>
                        <input type="number" name="value" id="value" value="<?= htmlspecialchars((string)$form_value) ?>" step="0.01" placeholder="e.g., 5000.00" class="input-field">
                    </div>
                    <div>
                        <label for="expected_close_date" class="form-label">Expected Close Date</label>
                        <input type="date" name="expected_close_date" id="expected_close_date" value="<?= htmlspecialchars((string)$form_expected_close_date) ?>" class="input-field">
                    </div>
                </div>

                <div>
                    <label for="source" class="form-label">Source</label>
                    <input type="text" name="source" id="source" value="<?= htmlspecialchars($form_source) ?>" class="input-field">
                </div>

                <div>
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" rows="4" class="input-field"><?= htmlspecialchars($form_description) ?></textarea>
                </div>

                <h3 class="text-lg font-medium text-gray-800 pt-4 border-t mt-6">Associations</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="contact_id" class="form-label">Contact</label>
                        <select name="contact_id" id="contact_id" class="input-field-select">
                            <option value="">None</option>
                            <?php foreach ($contacts_for_select as $item): ?>
                                <option value="<?= htmlspecialchars((string)$item['id']) ?>" <?= ($form_contact_id == $item['id']) ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="organisation_id" class="form-label">Organisation</label>
                        <select name="organisation_id" id="organisation_id" class="input-field-select">
                            <option value="">None</option>
                            <?php foreach ($organisations_for_select as $item): ?>
                                <option value="<?= htmlspecialchars((string)$item['id']) ?>" <?= ($form_organisation_id == $item['id']) ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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

                <?php if ($is_admin): ?>
                <div class="flex items-center mt-4">
                    <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?> class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                    <label for="is_active" class="ml-2 block text-sm text-gray-900">Lead Record is Active</label>
                </div>
                <?php elseif ($is_editing): ?>
                     <p class="text-sm text-gray-600 mt-2">Record Status: <span class="font-semibold"><?= $lead->is_active ? 'Active' : 'Inactive' ?></span></p>
                <?php endif; ?>
            </fieldset>

            <div class="pt-5">
                <button type="submit" class="btn-primary-full <?= ($is_editing && $is_converted_lead) ? 'btn-disabled' : '' ?>" <?= ($is_editing && $is_converted_lead) ? 'disabled' : '' ?> >
                    <?= $is_editing ? 'Save Changes' : 'Create Lead' ?>
                </button>
            </div>

            <?php if ($is_editing): ?>
            <p class="text-xs text-gray-500 mt-1">Created: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($lead->created_at))) ?>, Last Updated: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($lead->updated_at))) ?>, Version: <?= htmlspecialchars((string)$lead->version) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <?php require_once __DIR__ . '/../templates/footer.php'; ?>
    <?php // Local style block removed ?>
