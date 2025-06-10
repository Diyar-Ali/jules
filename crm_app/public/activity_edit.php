<?php
// public/activity_edit.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Activity.php';
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$activity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_editing = (bool)$activity_id;

$activity = new Activity($pdo);
$related_form_data = $activity->getRelatedDataForForms();
$users_for_select = $related_form_data['users'];
// The following will be used if we add dynamic select for related_to_id based on related_to_type
// $leads_for_select = $related_form_data['leads'];
// $deals_for_select = $related_form_data['deals'];
// $organisations_for_select = $related_form_data['organisations'];
// $contacts_for_select = $related_form_data['contacts'];

$type_options = Activity::getActivityTypeOptions();
$status_options = Activity::getActivityStatusOptions();
$related_to_type_options = Activity::getRelatedToTypeOptions();

// Form field initial values
$form_subject = ''; $form_type = $type_options[0] ?? 'Task'; $form_status = 'Pending';
$form_description = ''; $form_due_date = ''; $form_notes_content = '';
$form_related_to_type = ''; $form_related_to_id = ''; $form_related_to_name_display = '';
$form_assigned_to_user_id = $current_user_id; // Default to current user
$form_is_active = true; $current_version = null;
$current_files_metadata = [];

if (!$is_editing) {
    $form_related_to_type = $_GET['related_to_type'] ?? '';
    $form_related_to_id_get = filter_input(INPUT_GET, 'related_to_id', FILTER_VALIDATE_INT);
    if ($form_related_to_type && $form_related_to_id_get) {
        // Validate if this GET param is a valid int before assigning
        $form_related_to_id = $form_related_to_id_get ?: '';
        if($form_related_to_id) { // Fetch name only if ID is valid
            $form_related_to_name_display = $activity->getRelatedEntityName($form_related_to_type, $form_related_to_id);
        }
    }
    $current_version = 1;
}

if ($is_editing) {
    if (!$activity->read($activity_id)) {
        $_SESSION['error_message'] = 'Activity not found.';
        header("Location: {$app_url_base}/activities_list.php"); exit;
    }
    if (!$activity->is_active && !$is_admin) {
        $_SESSION['error_message'] = 'You do not have permission to edit this inactive activity.';
        header("Location: {$app_url_base}/activities_list.php"); exit;
    }

    $page_title = "Edit Activity: " . htmlspecialchars($activity->subject);
    $form_subject = $activity->subject; $form_type = $activity->type; $form_status = $activity->status;
    $form_description = $activity->description;
    $form_due_date = $activity->due_date ? date('Y-m-d\TH:i', strtotime($activity->due_date)) : '';
    $form_notes_content = $activity->notes_content;
    $form_related_to_type = $activity->related_to_type;
    $form_related_to_id = (string)$activity->related_to_id; // Cast to string for form value
    $form_related_to_name_display = $activity->related_to_entity_name;
    $form_assigned_to_user_id = $activity->assigned_to_user_id;
    $form_is_active = $activity->is_active; $current_version = $activity->version;
    $current_files_metadata = json_decode($activity->files_json ?: '[]', true);
    if (json_last_error() !== JSON_ERROR_NONE) $current_files_metadata = [];

} else {
    $page_title = "Add New Activity";
}

$error_message = ''; $success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('error', "CSRF token validation failed for activity " . ($is_editing ? "edit ID {$activity_id}" : "create") . " by user {$current_user_id}");
    } else {
        $form_subject = trim($_POST['subject'] ?? ''); $form_type = $_POST['type'] ?? '';
        $form_status = $_POST['status'] ?? ''; $form_description = trim($_POST['description'] ?? '');
        $form_due_date_input = $_POST['due_date'] ?? '';
        $form_due_date = !empty($form_due_date_input) ? date('Y-m-d H:i:s', strtotime($form_due_date_input)) : null;
        if ($form_due_date_input !== '' && $form_due_date === false) { // strtotime failed
            $error_message = "Invalid Due Date/Time format.";
        }

        $form_notes_content = $_POST['notes_content'] ?? '';
        $form_related_to_type = $_POST['related_to_type'] ?? '';
        $form_related_to_id = filter_input(INPUT_POST, 'related_to_id', FILTER_VALIDATE_INT) ?: '';
        $form_assigned_to_user_id = filter_input(INPUT_POST, 'assigned_to_user_id', FILTER_VALIDATE_INT);

        $files_to_remove = $_POST['files_to_remove'] ?? [];

        if ($is_admin) $form_is_active = isset($_POST['is_active']);
        elseif ($is_editing) $form_is_active = $activity->is_active;
        else $form_is_active = true;

        if (empty($form_subject) || empty($form_type) || empty($form_status) || empty($form_assigned_to_user_id) || empty($form_related_to_type) || empty($form_related_to_id)) {
            $error_message = 'Subject, Type, Status, Assigned User, and Related Entity (Type & ID) are required.';
        }

        if (empty($error_message)) {
            try {
                $activity_to_save = $is_editing ? $activity : new Activity($pdo);

                $activity_to_save->subject = $form_subject; $activity_to_save->type = $form_type; $activity_to_save->status = $form_status;
                $activity_to_save->description = $form_description; $activity_to_save->due_date = $form_due_date;
                $activity_to_save->notes_content = $form_notes_content;
                $activity_to_save->related_to_type = $form_related_to_type; $activity_to_save->related_to_id = $form_related_to_id;
                $activity_to_save->assigned_to_user_id = $form_assigned_to_user_id; $activity_to_save->is_active = $form_is_active;
                // files_json is set within create/update methods

                if ($is_editing) {
                    $submitted_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);
                    if ($submitted_version === false || $submitted_version !== $current_version) {
                         throw new Exception("Data conflict. Activity updated by someone else. Refresh and retry.");
                    }
                    $activity_to_save->version = $current_version; // Set for update check
                    // $activity_to_save->files_json is already set from read() if editing, update() will modify it.

                    if ($activity_to_save->update($_FILES['attachments'] ?? [], $files_to_remove)) {
                        $_SESSION['success_message'] = "Activity '".htmlspecialchars($activity_to_save->subject)."' updated successfully!";
                        header("Location: {$app_url_base}/activities_list.php"); exit;
                    }
                } else { // Creating new
                    // $activity_to_save->files_json starts as "[]" from constructor
                    if ($activity_to_save->create($current_user_id, $_FILES['attachments'] ?? [])) {
                        $_SESSION['success_message'] = "Activity '".htmlspecialchars($activity_to_save->subject)."' created successfully!";
                        header("Location: {$app_url_base}/activities_list.php"); exit;
                    }
                }
            } catch (Exception $e) {
                $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
                log_message('error', "UI Exception for activity " . ($is_editing ? "edit ID {$activity_id}" : "create") . " by user {$current_user_id}: " . $e->getMessage());
                 if ($is_editing) {
                    $tempAct = new Activity($pdo);
                    if($tempAct->read($activity_id)) $current_version = $tempAct->version; else $current_version = null;
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
            <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php" class="btn btn-muted">&larr; Back to List</a>
        </div>

        <?php if (!empty($error_message)): ?><div class="alert alert-danger dismissable-alert"><?= $error_message ?></div><?php endif; ?>

        <form action="activity_edit.php<?= $is_editing ? '?id='.$activity_id : '' ?>" method="POST" enctype="multipart/form-data" class="bg-white p-6 sm:p-8 rounded-lg shadow-lg space-y-6">
            <?= csrf_input_field() ?>
            <?php if ($is_editing): ?><input type="hidden" name="version" value="<?= htmlspecialchars((string)$current_version) ?>"><?php endif; ?>

            <div>
                <label for="subject" class="form-label">Subject <span class="text-red-500">*</span></label>
                <input type="text" name="subject" id="subject" value="<?= htmlspecialchars($form_subject) ?>" required class="input-field">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label for="type" class="form-label">Type <span class="text-red-500">*</span></label>
                    <select name="type" id="type" required class="input-field-select">
                        <?php foreach ($type_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($form_type === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status" class="form-label">Status <span class="text-red-500">*</span></label>
                    <select name="status" id="status" required class="input-field-select">
                        <?php foreach ($status_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($form_status === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                 <div>
                    <label for="due_date" class="form-label">Due Date/Time</label>
                    <input type="datetime-local" name="due_date" id="due_date" value="<?= htmlspecialchars($form_due_date) ?>" class="input-field">
                </div>
            </div>

            <div>
                <label for="description" class="form-label">Description (General Summary)</label>
                <textarea name="description" id="description" rows="3" class="input-field"><?= htmlspecialchars($form_description) ?></textarea>
            </div>

            <div>
                <label for="notes_content" class="form-label">Detailed Notes (HTML accepted)</label>
                <textarea name="notes_content" id="notes_content" rows="8" class="input-field wysiwyg-placeholder"><?= htmlspecialchars($form_notes_content) ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Use basic HTML for formatting. A rich text editor (e.g., TinyMCE, CKEditor) would be integrated here in a full build.</p>
            </div>

            <h3 class="text-lg font-medium text-gray-800 pt-4 border-t mt-6">Related To & Assignment</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label for="related_to_type" class="form-label">Related To Type <span class="text-red-500">*</span></label>
                    <select name="related_to_type" id="related_to_type" required class="input-field-select">
                        <option value="">Select Type...</option>
                        <?php foreach ($related_to_type_options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= ($form_related_to_type === $option) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="related_to_id" class="form-label">Related To ID <span class="text-red-500">*</span></label>
                    <input type="number" name="related_to_id" id="related_to_id" value="<?= htmlspecialchars($form_related_to_id) ?>" required class="input-field" placeholder="Enter ID of selected type">
                    <?php if ($form_related_to_name_display): ?> <p class="text-xs text-gray-500 mt-1">Currently: <?= htmlspecialchars($form_related_to_name_display) ?> (Type: <?= htmlspecialchars($form_related_to_type) ?>)</p> <?php endif; ?>
                </div>
                 <div>
                    <label for="assigned_to_user_id" class="form-label">Assigned To User <span class="text-red-500">*</span></label>
                    <select name="assigned_to_user_id" id="assigned_to_user_id" required class="input-field-select">
                        <option value="">Select User...</option>
                        <?php foreach ($users_for_select as $user): ?>
                        <option value="<?= htmlspecialchars((string)$user['id']) ?>" <?= ($form_assigned_to_user_id == $user['id']) ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h3 class="text-lg font-medium text-gray-800 pt-4 border-t mt-6">File Attachments</h3>
            <div>
                <label for="attachments" class="form-label">Add New Files (Max 2MB each, common types)</label>
                <input type="file" name="attachments[]" id="attachments" multiple class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-teal-600 cursor-pointer">
            </div>

            <?php if ($is_editing && !empty($current_files_metadata)): ?>
            <div class="mt-4">
                <p class="form-label mb-1">Currently Attached Files:</p>
                <ul class="list-disc list-inside space-y-1 text-sm">
                    <?php foreach($current_files_metadata as $file): ?>
                    <li>
                        <span class="file-info">
                            <a href="<?= htmlspecialchars($app_url_base . '/../uploads/activities/' . rawurlencode($file['path'])) ?>"
                               target="_blank" class="text-primary hover:text-teal-700 file-name-display"
                               title="Type: <?= htmlspecialchars($file['type']) ?>">
                                <?= htmlspecialchars($file['name']) ?>
                            </a>
                            <span class="file-size-info text-gray-600">(<?= round($file['size']/1024,1) ?> KB)</span>
                        </span>
                        <label class="ml-2">
                            <input type="checkbox" name="files_to_remove[]" value="<?= htmlspecialchars($file['path']) ?>" class="remove-file-cb h-4 w-4 text-secondary focus:ring-secondary align-middle"> Remove
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>


            <?php if ($is_admin): ?>
            <div class="flex items-center mt-4">
                <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?> class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                <label for="is_active" class="ml-2 block text-sm text-gray-900">Activity Record is Active</label>
            </div>
            <?php elseif ($is_editing): ?>
                 <p class="text-sm text-gray-600 mt-2">Record Status: <span class="font-semibold"><?= $activity->is_active ? 'Active' : 'Inactive' ?></span></p>
            <?php endif; ?>

            <div class="pt-5">
                <button type="submit" class="btn-primary-full">
                    <?= $is_editing ? 'Save Changes' : 'Create Activity' ?>
                </button>
            </div>

            <?php if ($is_editing): ?>
            <p class="text-xs text-gray-500 mt-1">Created: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->created_at))) ?>, Last Updated: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($activity->updated_at))) ?>, Version: <?= htmlspecialchars((string)$activity->version) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <?php require_once __DIR__ . '/../templates/footer.php'; ?>
    <style>
        .input-field, .input-field-select { padding: 0.5rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); width: 100%; font-size:0.875rem; }
        .input-field-select { background-color: #fff; }
        .input-field:focus, .input-field-select:focus { outline: none; border-color: #4FD1C5; box-shadow: 0 0 0 2px rgba(79, 209, 197, 0.5); }
        .btn-primary { padding: 0.5rem 1rem; border: 1px solid transparent; border-radius: 0.375rem; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: white; background-color: #4FD1C5; display: flex; justify-content: center; }
        .btn-primary:hover { background-color: #3ABAB0; }
        .btn-primary:disabled { background-color: #9CA3AF; cursor: not-allowed; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 0.375rem; }
        .alert-danger { background-color: #FEE2E2; color: #B91C1C; border: 1px solid #FCA5A5; }
        .wysiwyg-placeholder { min-height: 150px; background-color: #f9fafb; border: 1px dashed #d1d5db; padding:10px; color:#6b7280; font-size: 0.875rem; }
        .btn-link { color: #4B5563; /* text-gray-600 */ }
        .btn-link:hover { color: #1F2937; /* text-gray-800 */ }
        .form-checkbox { height: 1rem; width: 1rem; color: #4FD1C5; border-color: #D1D5DB; border-radius: 0.25rem; }
        .form-checkbox:focus { ring: #4FD1C5; }
    </style>
