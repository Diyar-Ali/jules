<?php
// crm_app/public/lead_view.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Lead.php'; // LeadStatusHistory is included by Lead.php
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$lead_handler = new Lead($pdo);
$page_title = "View Lead";
$error_message = '';
$lead_data = null;
$lead_history = [];

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);

if (isset($_GET['id'])) {
    $lead_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($lead_id) {
        $result = $lead_handler->readOne($lead_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $lead_data = $result['data'];
            $page_title = "View Lead: " . htmlspecialchars($lead_data['name']);
            log_message('INFO', "User ID {$_SESSION['user_id']} viewed lead ID {$lead_id}.");

            // Fetch lead status history
            $history_stmt = $pdo->prepare("SELECT lsh.*, u.username as changed_by_username
                                           FROM lead_status_history lsh
                                           JOIN users u ON lsh.changed_by_user_id = u.id
                                           WHERE lsh.lead_id = :lead_id ORDER BY lsh.change_timestamp DESC");
            $history_stmt->bindParam(':lead_id', $lead_id, PDO::PARAM_INT);
            $history_stmt->execute();
            $lead_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

        } else { $error_message = $result['message']; }
    } else { $error_message = "Invalid Lead ID."; }
} else { $error_message = "No Lead ID specified."; }

if ($error_message && !$lead_data) {
    $_SESSION['error_message'] = $error_message; header("Location: leads.php"); exit;
}

include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message && $lead_data): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <?php if ($lead_data): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($lead_data['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <small class="text-muted"><?php echo $lead_data['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></small>
                </h3>
                <?php if ($lead_data['converted_to_deal_id']): ?>
                    <span class="badge bg-purple">Converted to Deal ID: <a href="deal_view.php?id=<?php echo htmlspecialchars((string)$lead_data['converted_to_deal_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$lead_data['converted_to_deal_id'], ENT_QUOTES, 'UTF-8'); ?></a></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Status:</strong> <span class="badge bg-info text-dark"><?php echo htmlspecialchars($lead_data['status'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <p><strong>Temperature:</strong> <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($lead_data['temperature'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <p><strong>Value (Est.):</strong> <?php echo $lead_data['value'] ? htmlspecialchars(number_format($lead_data['value'], 2), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></p>
                        <p><strong>Expected Close Date:</strong> <?php echo $lead_data['expected_close_date'] ? htmlspecialchars(date("M d, Y", strtotime($lead_data['expected_close_date'])), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></p>
                        <p><strong>Source:</strong> <?php echo htmlspecialchars($lead_data['source'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Related Contact:</strong>
                            <?php if($lead_data['contact_id'] && $lead_data['contact_first_name']): ?>
                                <a href="contact_view.php?id=<?php echo htmlspecialchars((string)$lead_data['contact_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lead_data['contact_first_name'] . ' ' . $lead_data['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php else: echo 'N/A'; endif; ?>
                        </p>
                        <p><strong>Related Organisation:</strong>
                            <?php if($lead_data['organisation_id'] && $lead_data['organisation_name']): ?>
                                <a href="organisation_view.php?id=<?php echo htmlspecialchars((string)$lead_data['organisation_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lead_data['organisation_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php else: echo 'N/A'; endif; ?>
                        </p>
                        <p><strong>Assigned To:</strong> <?php echo htmlspecialchars($lead_data['assigned_user_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Created By:</strong> <?php echo htmlspecialchars($lead_data['created_by_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
                <h4>Description</h4>
                <p><?php echo nl2br(htmlspecialchars($lead_data['description'] ?? 'N/A', ENT_QUOTES, 'UTF-8')); ?></p>

                <h4>Audit Information</h4>
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($lead_data['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($lead_data['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars($lead_data['version'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="card-footer">
                <?php if (empty($lead_data['converted_to_deal_id'])): ?>
                    <a href="lead_edit.php?id=<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Edit</a>
                <?php else: ?>
                     <a href="lead_edit.php?id=<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning disabled" aria-disabled="true" title="Converted leads cannot be edited.">Edit</a>
                <?php endif; ?>
                <a href="leads.php" class="btn btn-secondary">Back to List</a>
                <!-- Convert to Deal Button (Placeholder for later step) -->
                <?php if (empty($lead_data['converted_to_deal_id']) && $lead_data['status'] !== 'Converted'): ?>
                <a href="lead_convert.php?id=<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success">Convert to Deal</a>
                <?php endif; ?>

                 <?php if ($_SESSION['is_admin'] && empty($lead_data['converted_to_deal_id'])): ?>
                    <form action="leads.php" method="POST" style="display: inline;">
                        <?php echo csrf_input_field(); ?>
                        <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$lead_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="is_currently_active" value="<?php echo $lead_data['is_active'] ? '1' : '0'; ?>">
                        <button type="submit" name="action" value="toggle_active" class="btn btn-<?php echo $lead_data['is_active'] ? 'outline-danger' : 'outline-success'; ?>">
                            <?php echo $lead_data['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h4>Lead Status History</h4></div>
            <div class="card-body">
                <?php if (count($lead_history) > 0): ?>
                <table class="table table-sm table-striped">
                    <thead><tr><th>Timestamp</th><th>Changed By</th><th>Old Status</th><th>New Status</th><th>Old Temp.</th><th>New Temp.</th></tr></thead>
                    <tbody>
                    <?php foreach ($lead_history as $entry): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date("Y-m-d H:i", strtotime($entry['change_timestamp'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($entry['changed_by_username'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($entry['old_status'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($entry['new_status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($entry['old_temperature'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($entry['new_temperature'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><p>No status history found for this lead.</p><?php endif; ?>
            </div>
        </div>
         <!-- Placeholder for related Activities -->
         <div class="mt-4">
            <h4>Related Activities (Placeholder)</h4>
            <ul><li><a href="activities.php?related_to_type=Lead&related_to_id=<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>">View Activities</a></li></ul>
        </div>

    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
