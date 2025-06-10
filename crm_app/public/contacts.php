<?php
// crm_app/public/contacts.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php'; // $is_admin available
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Contact.php';
require_once $base_path . 'classes/Organisation.php'; // For organisation filter dropdown
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php';

$contact_handler = new Contact($pdo);
$organisation_handler = new Organisation($pdo); // For dropdown

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Handle actions: toggle_active, hard_delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'CSRF token validation failed.';
    } else {
        $contact_id = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);
        $current_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);

        if ($contact_id && $is_admin) { // Admin only actions
            if ($_POST['action'] === 'toggle_active' && $current_version !== null) {
                $is_currently_active = filter_input(INPUT_POST, 'is_currently_active', FILTER_VALIDATE_BOOLEAN);
                $result = $contact_handler->setActiveStatus($contact_id, !$is_currently_active, $current_version, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            } elseif ($_POST['action'] === 'hard_delete') {
                $result = $contact_handler->hardDelete($contact_id, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            }
            log_message('INFO', "Admin action '{$_POST['action']}' on contact ID {$contact_id} by user ID {$_SESSION['user_id']}. Result: " . ($result['message'] ?? 'N/A'));
        } elseif (!$is_admin && isset($_POST['action'])) {
            $_SESSION['error_message'] = 'You do not have permission to perform this action.';
        }
    }
    // Preserve filter if set
    $org_filter_param = isset($_GET['organisation_id_filter']) ? '?organisation_id_filter=' . htmlspecialchars($_GET['organisation_id_filter']) : '';
    header("Location: contacts.php" . $org_filter_param);
    exit;
}

// Filtering and View options
$show_all_for_admin = $is_admin && isset($_GET['view']) && $_GET['view'] === 'all';
$organisation_id_filter = filter_input(INPUT_GET, 'organisation_id_filter', FILTER_VALIDATE_INT) ?: null;

$contacts = $contact_handler->readAll($_SESSION['is_admin'], !$show_all_for_admin, $organisation_id_filter);
$organisations_for_filter = $organisation_handler->readAll(true, true); // Get all active orgs for filter

$csrf_token = generate_csrf_token();
$page_title = "Contacts";
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1>Contacts</h1>

    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-6">
            <a href="contact_edit.php" class="btn btn-primary">Add New Contact</a>
            <?php if ($is_admin): ?>
                <?php if ($show_all_for_admin): ?>
                    <a href="contacts.php<?php echo $organisation_id_filter ? '?organisation_id_filter='.$organisation_id_filter.'&view=active' : '?view=active'; ?>" class="btn btn-info btn-sm">Show Active Only</a>
                <?php else: ?>
                    <a href="contacts.php<?php echo $organisation_id_filter ? '?organisation_id_filter='.$organisation_id_filter.'&view=all' : '?view=all'; ?>" class="btn btn-info btn-sm">Show All (Active & Inactive)</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <form action="contacts.php" method="GET" class="row g-3 justify-content-end">
                <div class="col-auto">
                    <select name="organisation_id_filter" class="form-select">
                        <option value="">Filter by Organisation...</option>
                        <?php foreach ($organisations_for_filter as $org): ?>
                            <option value="<?php echo $org['id']; ?>" <?php echo ($organisation_id_filter == $org['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (isset($_GET['view'])): // Preserve view setting if present ?>
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($_GET['view']); ?>">
                <?php endif; ?>
                <div class="col-auto"><button type="submit" class="btn btn-outline-secondary">Filter</button></div>
                 <?php if ($organisation_id_filter || isset($_GET['view'])): ?>
                    <div class="col-auto"><a href="contacts.php" class="btn btn-outline-danger">Clear Filters</a></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (count($contacts) > 0): ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone (Mobile)</th>
                    <th>Organisation</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td><a href="contact_view.php?id=<?php echo $contact['id']; ?>"><?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?></a></td>
                        <td><?php echo $contact['email'] ? '<a href="mailto:'.htmlspecialchars($contact['email']).'">'.htmlspecialchars($contact['email']).'</a>' : 'N/A'; ?></td>
                        <td><?php echo htmlspecialchars($contact['phone_mobile'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($contact['organisation_id'] && $contact['organisation_name']): ?>
                                <a href="organisation_view.php?id=<?php echo $contact['organisation_id']; ?>"><?php echo htmlspecialchars($contact['organisation_name']); ?></a>
                            <?php else: echo 'N/A'; endif; ?>
                        </td>
                        <td><?php echo $contact['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>'; ?></td>
                        <td>
                            <a href="contact_view.php?id=<?php echo $contact['id']; ?>" class="btn btn-info btn-sm">View</a>
                            <a href="contact_edit.php?id=<?php echo $contact['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                            <?php if ($is_admin): ?>
                                <form action="contacts.php<?php echo ($organisation_id_filter || isset($_GET['view'])) ? '?'.http_build_query(array_filter(['organisation_id_filter' => $organisation_id_filter, 'view' => $_GET['view'] ?? null])) : ''; ?>" method="POST" style="display: inline;">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                    <input type="hidden" name="version" value="<?php echo $contact['version']; ?>">
                                    <input type="hidden" name="is_currently_active" value="<?php echo $contact['is_active'] ? '1' : '0'; ?>">
                                    <button type="submit" name="action" value="toggle_active" class="btn btn-secondary btn-sm">
                                        <?php echo $contact['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form action="contacts.php<?php echo ($organisation_id_filter || isset($_GET['view'])) ? '?'.http_build_query(array_filter(['organisation_id_filter' => $organisation_id_filter, 'view' => $_GET['view'] ?? null])) : ''; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY DELETE this contact? This action cannot be undone.');">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                    <button type="submit" name="action" value="hard_delete" class="btn btn-danger btn-sm">Hard Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No contacts found<?php echo $organisation_id_filter ? ' for the selected organisation' : ''; ?>.</p>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
