<?php
// crm_app/public/deal_view.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Deal.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php';

$deal_handler = new Deal($pdo);
$page_title = "View Deal";
$error_message = '';
$deal_data = null;

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);

if (isset($_GET['id'])) {
    $deal_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($deal_id) {
        $result = $deal_handler->readOne($deal_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $deal_data = $result['data'];
            $page_title = "View Deal: " . htmlspecialchars($deal_data['name']);
            log_message('INFO', "User ID {$_SESSION['user_id']} viewed deal ID {$deal_id}.");
        } else { $error_message = $result['message']; }
    } else { $error_message = "Invalid Deal ID."; }
} else { $error_message = "No Deal ID specified."; }

if ($error_message && !$deal_data) {
    $_SESSION['error_message'] = $error_message; header("Location: deals.php"); exit;
}

include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message && $deal_data): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <?php if ($deal_data): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($deal_data['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <small class="text-muted"><?php echo $deal_data['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></small>
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Organisation:</strong> <a href="organisation_view.php?id=<?php echo htmlspecialchars((string)$deal_data['organisation_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deal_data['organisation_name'], ENT_QUOTES, 'UTF-8'); ?></a></p>
                        <p><strong>Primary Contact:</strong>
                            <?php if($deal_data['contact_id'] && $deal_data['contact_first_name']): ?>
                                <a href="contact_view.php?id=<?php echo htmlspecialchars((string)$deal_data['contact_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($deal_data['contact_first_name'] . ' ' . $deal_data['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php else: echo 'N/A'; endif; ?>
                        </p>
                        <p><strong>Stage:</strong> <span class="badge bg-primary"><?php echo htmlspecialchars($deal_data['stage'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <p><strong>Amount:</strong> <?php echo htmlspecialchars(number_format($deal_data['amount'], 2), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Expected Close Date:</strong> <?php echo $deal_data['close_date'] ? htmlspecialchars(date("M d, Y", strtotime($deal_data['close_date'])), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></p>
                        <p><strong>Probability:</strong> <?php echo ($deal_data['probability'] !== null) ? (htmlspecialchars(number_format($deal_data['probability'] * 100, 2), ENT_QUOTES, 'UTF-8') . '%') : 'N/A'; ?></p>
                        <p><strong>Assigned To:</strong> <?php echo htmlspecialchars($deal_data['assigned_user_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Created By:</strong> <?php echo htmlspecialchars($deal_data['created_by_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
                <h4>Description</h4>
                <p><?php echo nl2br(htmlspecialchars($deal_data['description'] ?? 'N/A', ENT_QUOTES, 'UTF-8')); ?></p>

                <h4>Audit Information</h4>
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($deal_data['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($deal_data['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars($deal_data['version'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="card-footer">
                <a href="deal_edit.php?id=<?php echo htmlspecialchars((string)$deal_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Edit</a>
                <a href="deals.php" class="btn btn-secondary">Back to List</a>
                 <?php if ($_SESSION['is_admin']): ?>
                    <form action="deals.php" method="POST" style="display: inline;">
                        <?php echo csrf_input_field(); ?>
                        <input type="hidden" name="deal_id" value="<?php echo htmlspecialchars((string)$deal_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$deal_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="is_currently_active" value="<?php echo $deal_data['is_active'] ? '1' : '0'; ?>">
                        <button type="submit" name="action" value="toggle_active" class="btn btn-<?php echo $deal_data['is_active'] ? 'outline-danger' : 'outline-success'; ?>">
                            <?php echo $deal_data['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <!-- Placeholder for related Activities -->
         <div class="mt-4">
            <h4>Related Activities (Placeholder)</h4>
            <ul><li><a href="activities.php?related_to_type=Deal&related_to_id=<?php echo htmlspecialchars((string)$deal_data['id'], ENT_QUOTES, 'UTF-8'); ?>">View Activities</a></li></ul>
        </div>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
