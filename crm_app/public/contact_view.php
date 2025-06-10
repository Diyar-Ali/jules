<?php
// crm_app/public/contact_view.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Contact.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php'; // For potential actions from view page

$contact_handler = new Contact($pdo);
$page_title = "View Contact";
$error_message = '';
$contact_data = null;

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);

if (isset($_GET['id'])) {
    $contact_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($contact_id) {
        $result = $contact_handler->readOne($contact_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $contact_data = $result['data'];
            $page_title = "View Contact: " . htmlspecialchars($contact_data['first_name'] . ' ' . $contact_data['last_name']);
            log_message('INFO', "User ID {$_SESSION['user_id']} viewed contact ID {$contact_id}.");
        } else {
            $error_message = $result['message'];
        }
    } else { $error_message = "Invalid Contact ID."; }
} else { $error_message = "No Contact ID specified."; }

if ($error_message && !$contact_data) {
    $_SESSION['error_message'] = $error_message;
    header("Location: contacts.php");
    exit;
}

include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message && $contact_data): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <?php if ($contact_data): ?>
        <div class="card">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($contact_data['first_name'] . ' ' . $contact_data['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                    <small class="text-muted"><?php echo $contact_data['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></small>
                </h3>
            </div>
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo $contact_data['email'] ? '<a href="mailto:'.htmlspecialchars($contact_data['email'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($contact_data['email'], ENT_QUOTES, 'UTF-8').'</a>' : 'N/A'; ?></p>
                <p><strong>Mobile Phone:</strong> <?php echo htmlspecialchars($contact_data['phone_mobile'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Work Phone:</strong> <?php echo htmlspecialchars($contact_data['phone_work'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($contact_data['title'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Organisation:</strong>
                    <?php if ($contact_data['organisation_id'] && $contact_data['organisation_name']): ?>
                        <a href="organisation_view.php?id=<?php echo htmlspecialchars((string)$contact_data['organisation_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contact_data['organisation_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: echo 'N/A'; endif; ?>
                </p>

                <h4>Audit Information</h4>
                <p><strong>Created By:</strong> <?php echo htmlspecialchars($contact_data['created_by_username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($contact_data['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($contact_data['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars($contact_data['version'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="card-footer">
                <a href="contact_edit.php?id=<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Edit</a>
                <a href="contacts.php<?php echo $contact_data['organisation_id'] ? '?organisation_id_filter='.htmlspecialchars((string)$contact_data['organisation_id'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="btn btn-secondary">Back to List</a>
                 <?php if ($_SESSION['is_admin']): ?>
                    <form action="contacts.php" method="POST" style="display: inline;">
                        <?php echo csrf_input_field(); ?>
                        <input type="hidden" name="contact_id" value="<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$contact_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="is_currently_active" value="<?php echo $contact_data['is_active'] ? '1' : '0'; ?>">
                        <button type="submit" name="action" value="toggle_active" class="btn btn-<?php echo $contact_data['is_active'] ? 'outline-danger' : 'outline-success'; ?>">
                            <?php echo $contact_data['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Placeholder for related Activities, Leads, Deals -->
         <div class="mt-4">
            <h4>Related Information (Placeholders)</h4>
            <ul>
                <li><a href="leads.php?contact_id=<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>">View Leads</a></li>
                <li><a href="deals.php?contact_id=<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>">View Deals</a></li>
                <li><a href="activities.php?related_to_type=Contact&related_to_id=<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>">View Activities</a></li>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
