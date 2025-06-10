<?php
// crm_app/public/activity_view.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Activity.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php';

$activity_handler = new Activity($pdo);
$page_title = "View Activity";
$error_message = '';
$activity_data = null;

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);

if (isset($_GET['id'])) {
    $activity_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($activity_id) {
        $result = $activity_handler->readOne($activity_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $activity_data = $result['data'];
            $page_title = "View Activity: " . htmlspecialchars($activity_data['subject']);
            log_message('INFO', "User ID {$_SESSION['user_id']} viewed activity ID {$activity_id}.");
        } else { $error_message = $result['message']; }
    } else { $error_message = "Invalid Activity ID."; }
} else { $error_message = "No Activity ID specified."; }

if ($error_message && !$activity_data) {
    $_SESSION['error_message'] = $error_message; header("Location: activities.php"); exit;
}

include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message && $activity_data): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <?php if ($activity_data): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($activity_data['subject'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($activity_data['type'], ENT_QUOTES, 'UTF-8'); ?>)
                    <small class="text-muted"><?php echo $activity_data['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></small>
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Status:</strong> <span class="badge bg-<?php echo strtolower($activity_data['status']) == 'completed' ? 'success' : (strtolower($activity_data['status']) == 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars($activity_data['status'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <p><strong>Due Date/Time:</strong> <?php echo $activity_data['due_date'] ? htmlspecialchars(date("Y-m-d H:i", strtotime($activity_data['due_date'])), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></p>
                        <p><strong>Assigned To:</strong> <?php echo htmlspecialchars($activity_data['assigned_user_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Created By:</strong> <?php echo htmlspecialchars($activity_data['created_by_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="col-md-6">
                         <p><strong>Related To:</strong>
                            <a href="<?php echo htmlspecialchars(strtolower($activity_data['related_to_type']), ENT_QUOTES, 'UTF-8'); ?>_view.php?id=<?php echo htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($activity_data['related_to_type'] . ': ' . $activity_data['related_entity_name'], ENT_QUOTES, 'UTF-8'); ?> (ID: <?php echo htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8'); ?>)
                            </a>
                        </p>
                    </div>
                </div>

                <h4>Description</h4>
                <div><?php echo ($activity_data['description']); /* HTML content - intentionally not escaped */ ?></div>

                <h4 class="mt-3">Notes</h4>
                <div><?php echo ($activity_data['notes_content']); /* HTML content - intentionally not escaped */ ?></div>

                <?php if (!empty($activity_data['files_json'])): ?>
                <h4 class="mt-3">Attached Files</h4>
                <ul>
                    <?php foreach ($activity_data['files_json'] as $file): ?>
                        <li>
                            <a href="<?php echo '../uploads/' . htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                            (<?php echo htmlspecialchars(round($file['size'] / 1024, 1), ENT_QUOTES, 'UTF-8'); ?> KB, Type: <?php echo htmlspecialchars($file['type'], ENT_QUOTES, 'UTF-8'); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <h4 class="mt-3">Audit Information</h4>
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($activity_data['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($activity_data['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars($activity_data['version'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="card-footer">
                <a href="activity_edit.php?id=<?php echo htmlspecialchars((string)$activity_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Edit</a>
                <a href="activities.php<?php echo ($activity_data['related_to_type'] && $activity_data['related_to_id']) ? '?related_to_type_filter='.htmlspecialchars($activity_data['related_to_type'], ENT_QUOTES, 'UTF-8').'&related_to_id_filter='.htmlspecialchars((string)$activity_data['related_to_id'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="btn btn-secondary">Back to List</a>
                 <?php if ($_SESSION['is_admin']): ?>
                    <form action="activities.php" method="POST" style="display: inline;">
                        <?php echo csrf_input_field(); ?>
                        <input type="hidden" name="activity_id" value="<?php echo htmlspecialchars((string)$activity_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$activity_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="is_currently_active" value="<?php echo $activity_data['is_active'] ? '1' : '0'; ?>">
                        <button type="submit" name="action" value="toggle_active" class="btn btn-<?php echo $activity_data['is_active'] ? 'outline-danger' : 'outline-success'; ?>">
                            <?php echo $activity_data['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
