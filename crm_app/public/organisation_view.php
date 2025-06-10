<?php
// crm_app/public/organisation_view.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Organisation.php';
require_once $base_path . 'includes/log_helper.php';

$organisation_handler = new Organisation($pdo);
$page_title = "View Organisation";
$error_message = '';
$org_data = null;

$message = $_SESSION['message'] ?? ''; // For messages from edit page
unset($_SESSION['message']);

if (isset($_GET['id'])) {
    $org_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($org_id) {
        $result = $organisation_handler->readOne($org_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $org_data = $result['data'];
            $page_title = "View Organisation: " . htmlspecialchars($org_data['name']);
            log_message('INFO', "User ID {$_SESSION['user_id']} viewed organisation ID {$org_id}.");
        } else {
            $error_message = $result['message'];
            log_message('WARNING', "User ID {$_SESSION['user_id']} failed to view organisation ID {$org_id}: {$error_message}");
        }
    } else {
        $error_message = "Invalid Organisation ID specified.";
    }
} else {
    $error_message = "No Organisation ID specified.";
}

if ($error_message && !$org_data) { // If error and no data, redirect
    $_SESSION['error_message'] = $error_message;
    header("Location: organisations.php");
    exit;
}


include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error_message && $org_data): // Show error on page if data is partially loaded or specific view error ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($org_data): ?>
        <div class="card">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($org_data['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <small class="text-muted"><?php echo $org_data['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></small>
                </h3>
            </div>
            <div class="card-body">
                <p><strong>Website:</strong> <?php echo $org_data['website'] ? '<a href="'.htmlspecialchars($org_data['website'], ENT_QUOTES, 'UTF-8').'" target="_blank">'.htmlspecialchars($org_data['website'], ENT_QUOTES, 'UTF-8').'</a>' : 'N/A'; ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($org_data['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Industry:</strong> <?php echo htmlspecialchars($org_data['industry'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Annual Revenue:</strong> <?php echo $org_data['annual_revenue'] ? htmlspecialchars(number_format($org_data['annual_revenue'], 2), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></p>

                <h4>Address</h4>
                <p>
                    <?php echo htmlspecialchars($org_data['address_street'] ?? '', ENT_QUOTES, 'UTF-8'); ?><br>
                    <?php echo htmlspecialchars($org_data['address_city'] ?? '', ENT_QUOTES, 'UTF-8'); ?><?php echo $org_data['address_state'] ? ', '.htmlspecialchars($org_data['address_state'], ENT_QUOTES, 'UTF-8') : ''; ?> <?php echo htmlspecialchars($org_data['address_zip'] ?? '', ENT_QUOTES, 'UTF-8'); ?><br>
                    <?php echo htmlspecialchars($org_data['address_country'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <h4>Description</h4>
                <p><?php echo nl2br(htmlspecialchars($org_data['description'] ?? 'N/A', ENT_QUOTES, 'UTF-8')); ?></p>

                <h4>Audit Information</h4>
                <p><strong>Created By:</strong> <?php echo htmlspecialchars($org_data['created_by_username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($org_data['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo htmlspecialchars(date("Y-m-d H:i:s", strtotime($org_data['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars($org_data['version'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="card-footer">
                <a href="organisation_edit.php?id=<?php echo htmlspecialchars((string)$org_data['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning">Edit</a>
                <a href="organisations.php" class="btn btn-secondary">Back to List</a>
                <?php if ($_SESSION['is_admin']): // Admin-only actions on view page could be added here too ?>
                <!-- Example: Deactivate/Activate button, using POST to organisations.php -->
                <form action="organisations.php" method="POST" style="display: inline;">
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="organisation_id" value="<?php echo htmlspecialchars((string)$org_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$org_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="is_currently_active" value="<?php echo $org_data['is_active'] ? '1' : '0'; ?>">
                    <button type="submit" name="action" value="toggle_active" class="btn btn-<?php echo $org_data['is_active'] ? 'outline-danger' : 'outline-success'; ?>">
                        <?php echo $org_data['is_active'] ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Placeholder for related entities like Contacts, Leads, Deals, Activities -->
        <div class="mt-4">
            <h4>Related Information (Placeholders)</h4>
            <ul>
                <li><a href="contacts.php?organisation_id=<?php echo $org_data['id']; ?>">View Contacts</a></li>
                <li><a href="leads.php?organisation_id=<?php echo $org_data['id']; ?>">View Leads</a></li>
                <li><a href="deals.php?organisation_id=<?php echo $org_data['id']; ?>">View Deals</a></li>
                <li><a href="activities.php?related_to_type=Organisation&related_to_id=<?php echo $org_data['id']; ?>">View Activities</a></li>
            </ul>
        </div>

    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
