<?php
// crm_app/public/leads.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Lead.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php';

$lead_handler = new Lead($pdo);

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
$error_message = $_SESSION['error_message'] ?? ''; unset($_SESSION['error_message']);

// Handle actions: toggle_active, hard_delete (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'CSRF token validation failed.';
    } else {
        $lead_id = filter_input(INPUT_POST, 'lead_id', FILTER_VALIDATE_INT);
        $current_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);

        if ($lead_id && $_SESSION['is_admin']) {
            if ($_POST['action'] === 'toggle_active' && $current_version !== null) {
                $is_currently_active = filter_input(INPUT_POST, 'is_currently_active', FILTER_VALIDATE_BOOLEAN);
                $result = $lead_handler->setActiveStatus($lead_id, !$is_currently_active, $current_version, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            } elseif ($_POST['action'] === 'hard_delete') {
                $result = $lead_handler->hardDelete($lead_id, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            }
            log_message('INFO', "Admin action '{$_POST['action']}' on lead ID {$lead_id} by user ID {$_SESSION['user_id']}. Result: " . ($result['message'] ?? 'N/A'));
        } elseif (!$_SESSION['is_admin'] && isset($_POST['action'])) {
            $_SESSION['error_message'] = 'You do not have permission to perform this action.';
        }
    }
    // Preserve filters on redirect
    $preserved_filters = array_intersect_key($_GET, array_flip(['contact_id_filter', 'organisation_id_filter', 'assigned_user_id_filter', 'status_filter', 'view']));
    header("Location: leads.php?" . http_build_query($preserved_filters));
    exit;
}

// Filtering options
$show_all_for_admin = $_SESSION['is_admin'] && isset($_GET['view']) && $_GET['view'] === 'all';
$filters = [
    'contact_id'       => filter_input(INPUT_GET, 'contact_id_filter', FILTER_VALIDATE_INT) ?: null,
    'organisation_id'  => filter_input(INPUT_GET, 'organisation_id_filter', FILTER_VALIDATE_INT) ?: null,
    'assigned_user_id' => filter_input(INPUT_GET, 'assigned_user_id_filter', FILTER_VALIDATE_INT) ?: null,
    // Add status filter if needed, not directly supported by current Lead::readAll filters array yet
];
// Remove null filters to avoid issues with readAll expecting set keys
$filters = array_filter($filters, function($value) { return $value !== null; });


$leads = $lead_handler->readAll($_SESSION['is_admin'], !$show_all_for_admin, $filters);
$related_data_for_forms = $lead_handler->getRelatedDataForForms(); // For filter dropdowns

$csrf_token = generate_csrf_token();
$page_title = "Leads";
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1>Leads</h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3">
            <a href="lead_edit.php" class="btn btn-primary">Add New Lead</a>
        </div>
        <div class="col-md-9">
            <form action="leads.php" method="GET" class="row row-cols-lg-auto g-3 align-items-center justify-content-end">
                <div class="col-12">
                    <select name="assigned_user_id_filter" class="form-select form-select-sm">
                        <option value="">Filter by Assignee...</option>
                        <?php foreach ($related_data_for_forms['users'] as $user): ?>
                            <option value="<?php echo htmlspecialchars((string)$user['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($filters['assigned_user_id'] ?? null) == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Add more filters for contact, organisation, status as needed -->
                 <?php if ($_SESSION['is_admin']): ?>
                 <div class="col-12">
                    <?php if ($show_all_for_admin): ?>
                        <a href="leads.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => 'active'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-sm">Show Active Only</a>
                    <?php else: ?>
                        <a href="leads.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => 'all'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-sm">Show All</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="col-12"><button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button></div>
                <?php if (!empty($filters) || isset($_GET['view'])): ?>
                    <div class="col-12"><a href="leads.php" class="btn btn-outline-danger btn-sm">Clear Filters</a></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (count($leads) > 0): ?>
        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th>Name</th><th>Status</th><th>Temp.</th><th>Value</th><th>Contact</th><th>Organisation</th><th>Assigned To</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                    <tr>
                        <td><a href="lead_view.php?id=<?php echo htmlspecialchars((string)$lead['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lead['name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($lead['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($lead['temperature'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo $lead['value'] ? htmlspecialchars(number_format($lead['value'], 2), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                        <td><?php echo $lead['contact_first_name'] ? htmlspecialchars($lead['contact_first_name'] . ' ' . $lead['contact_last_name'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                        <td><?php echo htmlspecialchars($lead['organisation_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($lead['assigned_user_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="lead_view.php?id=<?php echo htmlspecialchars((string)$lead['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-xs">View</a>
                            <a href="lead_edit.php?id=<?php echo htmlspecialchars((string)$lead['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning btn-xs">Edit</a>
                            <?php if ($_SESSION['is_admin']): ?>
                                <form action="leads.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => $_GET['view'] ?? null])), ENT_QUOTES, 'UTF-8');?>" method="POST" style="display: inline;">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars((string)$lead['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$lead['version'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="is_currently_active" value="<?php echo $lead['is_active'] ? '1' : '0'; ?>">
                                    <button type="submit" name="action" value="toggle_active" class="btn btn-secondary btn-xs">
                                        <?php echo $lead['is_active'] ? 'Deact.' : 'Act.'; ?>
                                    </button>
                                </form>
                                <?php if (empty($lead['converted_to_deal_id'])): // Can only hard delete if not converted ?>
                                <form action="leads.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => $_GET['view'] ?? null])), ENT_QUOTES, 'UTF-8');?>" method="POST" style="display: inline;" onsubmit="return confirm('PERMANENTLY DELETE this lead?');">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars((string)$lead['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="action" value="hard_delete" class="btn btn-danger btn-xs">Del.</button>
                                </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary btn-xs" disabled title="Converted to Deal ID: <?php echo htmlspecialchars((string)$lead['converted_to_deal_id'], ENT_QUOTES, 'UTF-8'); ?>">Del.</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No leads found matching your criteria.</p>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
