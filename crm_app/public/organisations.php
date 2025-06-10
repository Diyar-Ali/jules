<?php
// crm_app/public/organisations.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php'; // Ensures user is logged in, provides $is_admin
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Organisation.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php'; // For delete/status change actions

$organisation_handler = new Organisation($pdo);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Handle actions like soft delete, activate, hard delete (if admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'CSRF token validation failed.';
        header("Location: organisations.php");
        exit;
    }

    $org_id = filter_input(INPUT_POST, 'organisation_id', FILTER_VALIDATE_INT);
    $current_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT); // Required for status change

    if ($org_id && $is_admin) { // Actions requiring admin
        if ($_POST['action'] === 'toggle_active' && $current_version !== null) {
            $is_currently_active = filter_input(INPUT_POST, 'is_currently_active', FILTER_VALIDATE_BOOLEAN);
            $result = $organisation_handler->setActiveStatus($org_id, !$is_currently_active, $current_version, $_SESSION['user_id'], $_SESSION['is_admin']);
            $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
        } elseif ($_POST['action'] === 'hard_delete') {
            $result = $organisation_handler->hardDelete($org_id, $_SESSION['user_id'], $_SESSION['is_admin']);
            $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
        }
        log_message('INFO', "Admin action '{$_POST['action']}' on organisation ID {$org_id} by user ID {$_SESSION['user_id']}. Result: " . ($result['message'] ?? 'N/A'));
    } elseif (!$is_admin && isset($_POST['action'])) {
         $_SESSION['error_message'] = 'You do not have permission to perform this action.';
    }
    header("Location: organisations.php");
    exit;
}


// Determine if admin wants to see all (active + inactive) or just active
// Add a GET parameter for admin to toggle view
$show_all_for_admin = $is_admin && isset($_GET['view']) && $_GET['view'] === 'all';
$organisations = $organisation_handler->readAll($_SESSION['is_admin'], !$show_all_for_admin);

$csrf_token = generate_csrf_token(); // For forms on this page (delete/status change)

// Basic HTML for listing
$page_title = "Organisations";
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1>Organisations</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <p><a href="organisation_edit.php" class="btn btn-primary">Add New Organisation</a></p>

    <?php if ($is_admin): ?>
        <p>
            <?php if ($show_all_for_admin): ?>
                <a href="organisations.php?view=active" class="btn btn-info btn-sm">Show Active Only</a>
            <?php else: ?>
                <a href="organisations.php?view=all" class="btn btn-info btn-sm">Show All (Active & Inactive)</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (count($organisations) > 0): ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Website</th>
                    <th>Phone</th>
                    <th>City</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($organisations as $org): ?>
                    <tr>
                        <td><a href="organisation_view.php?id=<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?php echo $org['website'] ? '<a href="'.htmlspecialchars($org['website'], ENT_QUOTES, 'UTF-8').'" target="_blank">'.htmlspecialchars($org['website'], ENT_QUOTES, 'UTF-8').'</a>' : 'N/A'; ?></td>
                        <td><?php echo htmlspecialchars($org['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($org['address_city'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($org['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($org['created_by_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="organisation_view.php?id=<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-sm">View</a>
                            <a href="organisation_edit.php?id=<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning btn-sm">Edit</a>
                            <?php if ($is_admin): // Admin-only actions ?>
                                <form action="organisations.php" method="POST" style="display: inline;">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="organisation_id" value="<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)($org['version'] ?? $organisation_handler->readOne($org['id'], true)['data']['version']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="is_currently_active" value="<?php echo $org['is_active'] ? '1' : '0'; ?>">
                                    <button type="submit" name="action" value="toggle_active" class="btn btn-secondary btn-sm">
                                        <?php echo $org['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form action="organisations.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY DELETE this organisation? This action cannot be undone.');">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="organisation_id" value="<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="action" value="hard_delete" class="btn btn-danger btn-sm">Hard Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No organisations found.</p>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
