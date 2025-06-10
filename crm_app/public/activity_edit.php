<?php
// crm_app/public/activity_edit.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Activity.php';
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$activity_handler = new Activity($pdo);
$page_title = "Add Activity";
$error_message = '';

$activity_data = [
    'id' => null, 'type' => 'Task', 'subject' => '', 'description' => '', 'due_date' => '',
    'status' => 'Pending', 'notes_content' => '', 'files_json' => [],
    'related_to_type' => null, 'related_to_id' => null,
    'assigned_to_user_id' => $_SESSION['user_id'], 'version' => null, 'is_active' => true
];

// Pre-fill related_to fields if coming from another entity's page
$prefill_related_type = filter_input(INPUT_GET, 'related_to_type', FILTER_SANITIZE_STRING);
$prefill_related_id = filter_input(INPUT_GET, 'related_to_id', FILTER_VALIDATE_INT);
if ($prefill_related_type && $prefill_related_id && in_array($prefill_related_type, Activity::$related_to_types)) {
    if ($activity_handler->validateRelatedEntity($prefill_related_type, $prefill_related_id)) { // Use the class method
        $activity_data['related_to_type'] = $prefill_related_type;
        $activity_data['related_to_id'] = $prefill_related_id;
        $activity_data['related_entity_name'] = $activity_handler->getRelatedEntityName($prefill_related_type, $prefill_related_id);
    } else {
        $error_message = "Invalid pre-filled related entity: " . htmlspecialchars($prefill_related_type) . " ID " . htmlspecialchars($prefill_related_id);
    }
}
$form_data_sources = $activity_handler->getRelatedDataForForms($activity_data['related_to_type'], $activity_data['related_to_id']);


$edit_mode = false;
if (isset($_GET['id'])) {
    $activity_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($activity_id) {
        $result = $activity_handler->readOne($activity_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $activity_data = $result['data']; // This now includes files_json as array
            $page_title = "Edit Activity: " . htmlspecialchars($activity_data['subject']);
            $edit_mode = true;
        } else {
            $_SESSION['error_message'] = $result['message']; header("Location: activities.php"); exit;
        }
    } else {
        $_SESSION['error_message'] = "Invalid Activity ID."; header("Location: activities.php"); exit;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
    } else {
        $type = $_POST['type'] ?? 'Task';
        $subject = $_POST['subject'] ?? '';
        $description = $_POST['description'] ?? ''; // HTML allowed
        $due_date = $_POST['due_date'] ?? '';
        $status = $_POST['status'] ?? 'Pending';
        $notes_content = $_POST['notes_content'] ?? ''; // HTML allowed
        $related_to_type_post = $_POST['related_to_type'] ?? null;
        $related_to_id_post = !empty($_POST['related_to_id']) ? filter_var($_POST['related_to_id'], FILTER_VALIDATE_INT) : null;
        $assigned_to_user_id = !empty($_POST['assigned_to_user_id']) ? filter_var($_POST['assigned_to_user_id'], FILTER_VALIDATE_INT) : $_SESSION['user_id'];
        $current_version = $_POST['version'] ?? null;
        $files_to_remove_json = $_POST['files_to_remove'] ?? '[]'; // Expecting a JSON string of paths

        // Repopulate form data (files_json handled separately by class)
        $activity_data = array_merge($activity_data, $_POST);
        $activity_data['related_to_type'] = $related_to_type_post; // Ensure these are correctly updated
        $activity_data['related_to_id'] = $related_to_id_post;
         if ($related_to_type_post && $related_to_id_post) { // Update name for repopulation
            $activity_data['related_entity_name'] = $activity_handler->getRelatedEntityName($related_to_type_post, $related_to_id_post);
        }


        // File uploads are handled by $_FILES, passed directly to class methods
        $uploaded_files_array = (isset($_FILES['activity_files']) && !empty($_FILES['activity_files']['name'][0])) ? $_FILES['activity_files'] : null;

        if ($edit_mode && isset($_POST['id'])) {
            $activity_id_post = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            if ($activity_id_post && $activity_id_post == $activity_data['id']) {
                $result = $activity_handler->update(
                    $activity_data['id'], $type, $subject, $description, $due_date, $status, $notes_content,
                    $related_to_type_post, $related_to_id_post, $assigned_to_user_id, $current_version, $_SESSION['user_id'],
                    $uploaded_files_array, $files_to_remove_json
                );
                if ($result['success']) {
                    $_SESSION['message'] = "Activity updated successfully.";
                    header("Location: activity_view.php?id=" . $activity_data['id']); exit;
                } else {
                    $error_message = $result['message'];
                    if (strpos($error_message, "conflict") !== false) {
                        $fresh_data = $activity_handler->readOne($activity_data['id'], $_SESSION['is_admin']);
                        if($fresh_data['success']) $activity_data['version'] = $fresh_data['data']['version'];
                        // files_json also needs to be re-fetched if there was a conflict before file processing logic ran in update
                        $activity_data['files_json'] = $fresh_data['success'] ? $fresh_data['data']['files_json'] : [];
                    }
                }
            } else { $error_message = "Error with Activity ID during update."; }
        } else { // Create mode
            $result = $activity_handler->create(
                $type, $subject, $description, $due_date, $status, $notes_content,
                $related_to_type_post, $related_to_id_post, $assigned_to_user_id, $_SESSION['user_id'],
                $uploaded_files_array
            );
            if ($result['success']) {
                $_SESSION['message'] = "Activity created successfully.";
                header("Location: activity_view.php?id=" . $result['activity_id']); exit;
            } else {
                $error_message = $result['message'];
            }
        }
    }
}

$csrf_token = generate_csrf_token();
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form action="activity_edit.php<?php echo $edit_mode ? '?id='.htmlspecialchars((string)$activity_data['id'], ENT_QUOTES, 'UTF-8') : ($activity_data['related_to_type'] ? '?related_to_type='.htmlspecialchars($activity_data['related_to_type'], ENT_QUOTES, 'UTF-8').'&related_to_id='.htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8') : ''); ?>" method="POST" enctype="multipart/form-data">
        <?php echo csrf_input_field(); ?>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$activity_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$activity_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <input type="hidden" id="files_to_remove_json_input" name="files_to_remove" value="[]">


        <div class="row">
            <div class="col-md-8 mb-3">
                <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="subject" name="subject" value="<?php echo htmlspecialchars($activity_data['subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-4 mb-3">
                <label for="type" class="form-label">Type <span class="text-danger">*</span></label>
                <select class="form-select" id="type" name="type" required>
                    <?php foreach ($form_data_sources['types'] as $type_opt): ?>
                    <option value="<?php echo htmlspecialchars($type_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($activity_data['type'] == $type_opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Description (HTML allowed)</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($activity_data['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
         <div class="row">
            <div class="col-md-6 mb-3">
                <label for="due_date" class="form-label">Due Date/Time</label>
                <input type="datetime-local" class="form-control" id="due_date" name="due_date" value="<?php echo $activity_data['due_date'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($activity_data['due_date'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                <select class="form-select" id="status" name="status" required>
                     <?php foreach ($form_data_sources['statuses'] as $status_opt): ?>
                    <option value="<?php echo htmlspecialchars($status_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($activity_data['status'] == $status_opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="related_to_type" class="form-label">Related To Type <span class="text-danger">*</span></label>
                <select class="form-select" id="related_to_type" name="related_to_type" required <?php echo ($prefill_related_type && $prefill_related_id) ? 'disabled' : ''; ?>>
                    <option value="">-- Select Type --</option>
                    <?php foreach ($form_data_sources['related_to_types'] as $rel_type_opt): ?>
                    <option value="<?php echo htmlspecialchars($rel_type_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($activity_data['related_to_type'] == $rel_type_opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rel_type_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                 <?php if ($prefill_related_type && $prefill_related_id): // Keep original value if disabled ?>
                    <input type="hidden" name="related_to_type" value="<?php echo htmlspecialchars($activity_data['related_to_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-6 mb-3">
                <label for="related_to_id" class="form-label">Related To ID/Name <span class="text-danger">*</span></label>
                <?php if ($prefill_related_type && $prefill_related_id && isset($activity_data['related_entity_name'])): ?>
                     <input type="text" class="form-control" value="<?php echo htmlspecialchars($activity_data['related_entity_name'] . ' (ID: ' . $activity_data['related_to_id'] . ')', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                     <input type="hidden" name="related_to_id" value="<?php echo htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php else: ?>
                    <input type="number" class="form-control" id="related_to_id" name="related_to_id" value="<?php echo htmlspecialchars($activity_data['related_to_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter ID of selected type above" required>
                    <small class="form-text text-muted">Enter the ID of the Lead, Deal, Organisation, Contact, or User.</small>
                <?php endif; ?>
            </div>
        </div>
        <div class="mb-3">
            <label for="assigned_to_user_id" class="form-label">Assigned To User <span class="text-danger">*</span></label>
            <select class="form-select" id="assigned_to_user_id" name="assigned_to_user_id" required>
                <?php foreach ($form_data_sources['users'] as $user): ?>
                <option value="<?php echo htmlspecialchars((string)$user['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($activity_data['assigned_to_user_id'] == $user['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($user['username'] . ($user['first_name'] ? ' ('.$user['first_name'].' '.$user['last_name'].')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="notes_content" class="form-label">Notes (HTML allowed)</label>
            <textarea class="form-control" id="notes_content" name="notes_content" rows="5"><?php echo htmlspecialchars($activity_data['notes_content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="mb-3">
            <label for="activity_files" class="form-label">Attach Files (Multiple allowed)</label>
            <input type="file" class="form-control" id="activity_files" name="activity_files[]" multiple>
        </div>

        <?php if ($edit_mode && !empty($activity_data['files_json'])): ?>
        <div class="mb-3">
            <p><strong>Currently Attached Files:</strong></p>
            <ul id="current_files_list">
                <?php foreach ($activity_data['files_json'] as $file): ?>
                <li data-path="<?php echo htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8'); ?>">
                    <a href="<?php echo '../uploads/' . htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    (<?php echo htmlspecialchars(round($file['size'] / 1024, 1), ENT_QUOTES, 'UTF-8'); ?> KB)
                    <button type="button" class="btn btn-danger btn-xs remove-file-btn" data-path="<?php echo htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8'); ?>">Remove</button>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?php echo $edit_mode ? 'Update' : 'Create'; ?> Activity</button>
        <a href="activities.php<?php echo ($activity_data['related_to_type'] && $activity_data['related_to_id']) ? '?related_to_type_filter='.htmlspecialchars($activity_data['related_to_type'], ENT_QUOTES, 'UTF-8').'&related_to_id_filter='.htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<script>
// Script to handle file removal list for edit form
document.addEventListener('DOMContentLoaded', function() {
    const filesToRemove = [];
    const filesToRemoveInput = document.getElementById('files_to_remove_json_input');

    document.querySelectorAll('.remove-file-btn').forEach(button => {
        button.addEventListener('click', function() {
            const filePath = this.dataset.path;
            if (filePath && !filesToRemove.includes(filePath)) {
                filesToRemove.push(filePath);
            }
            // Update hidden input
            if(filesToRemoveInput) filesToRemoveInput.value = JSON.stringify(filesToRemove);
            // Visually remove from list and disable button
            this.parentElement.style.textDecoration = 'line-through';
            this.disabled = true;
            this.textContent = 'Marked for Removal';
        });
    });
});
</script>
<?php include_once $base_path . 'templates/footer.php'; ?>
