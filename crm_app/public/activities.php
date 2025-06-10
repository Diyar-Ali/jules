<?php
// crm_app/public/activities.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Activity.php';
require_once $base_path . 'includes/log_helper.php';
require_once $base_path . 'includes/csrf_helper.php';

$activity_handler = new Activity($pdo);

$message = $_SESSION['message'] ?? ''; unset($_SESSION['message']);
$error_message = $_SESSION['error_message'] ?? ''; unset($_SESSION['error_message']);

// Handle actions (Admin only for status/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'CSRF token validation failed.';
    } else {
        $activity_id = filter_input(INPUT_POST, 'activity_id', FILTER_VALIDATE_INT);
        $current_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);

        if ($activity_id && $_SESSION['is_admin']) {
            if ($_POST['action'] === 'toggle_active' && $current_version !== null) {
                $is_currently_active = filter_input(INPUT_POST, 'is_currently_active', FILTER_VALIDATE_BOOLEAN);
                $result = $activity_handler->setActiveStatus($activity_id, !$is_currently_active, $current_version, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            } elseif ($_POST['action'] === 'hard_delete') {
                $result = $activity_handler->hardDelete($activity_id, $_SESSION['user_id'], $_SESSION['is_admin']);
                $_SESSION[$result['success'] ? 'message' : 'error_message'] = $result['message'];
            }
            log_message('INFO', "Admin action '{$_POST['action']}' on activity ID {$activity_id} by user ID {$_SESSION['user_id']}. Result: " . ($result['message'] ?? 'N/A'));
        } elseif (!$_SESSION['is_admin'] && isset($_POST['action'])) {
            $_SESSION['error_message'] = 'You do not have permission to perform this action.';
        }
    }
    // Preserve filters on redirect
    $preserved_filters = array_intersect_key($_GET, array_flip(['related_to_type_filter', 'related_to_id_filter', 'assigned_user_id_filter', 'type_filter', 'status_filter', 'view']));
    header("Location: activities.php?" . http_build_query(array_filter($preserved_filters))); // array_filter to remove empty params
    exit;
}

// Filtering options
$show_all_for_admin = $_SESSION['is_admin'] && isset($_GET['view']) && $_GET['view'] === 'all';
$filters = [
    'related_to_type'     => filter_input(INPUT_GET, 'related_to_type_filter', FILTER_SANITIZE_STRING) ?: null,
    'related_to_id'       => filter_input(INPUT_GET, 'related_to_id_filter', FILTER_VALIDATE_INT) ?: null,
    'assigned_to_user_id' => filter_input(INPUT_GET, 'assigned_user_id_filter', FILTER_VALIDATE_INT) ?: null,
    'type'                => filter_input(INPUT_GET, 'type_filter', FILTER_SANITIZE_STRING) ?: null,
    'status'              => filter_input(INPUT_GET, 'status_filter', FILTER_SANITIZE_STRING) ?: null,
];
// If related_to_type is set but not related_to_id, or vice-versa, it might not be a valid filter pair for Activity::readAll
if ( (isset($filters['related_to_type']) && !isset($filters['related_to_id'])) || (!isset($filters['related_to_type']) && isset($filters['related_to_id'])) ) {
    unset($filters['related_to_type']);
    unset($filters['related_to_id']);
    // Optionally set an error message: $error_message = "Related Type and ID must be filtered together.";
}
$filters = array_filter($filters, function($value) { return $value !== null && $value !== ''; });


$activities = $activity_handler->readAll($_SESSION['is_admin'], !$show_all_for_admin, $filters);
$form_data_sources = $activity_handler->getRelatedDataForForms(); // For filter dropdowns

$csrf_token = generate_csrf_token();
$page_title = "Activities";
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1>Activities</h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3"><a href="activity_edit.php" class="btn btn-primary">Log New Activity</a></div>
        <div class="col-md-9">
            <form action="activities.php" method="GET" class="row row-cols-lg-auto g-3 align-items-center justify-content-end">
                <!-- Basic Filters: Type, Status, Assignee -->
                <div class="col-12">
                    <select name="type_filter" class="form-select form-select-sm">
                        <option value="">Filter by Type...</option>
                        <?php foreach ($form_data_sources['types'] as $type_opt): ?>
                            <option value="<?php echo htmlspecialchars($type_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($filters['type'] ?? null) == $type_opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <select name="status_filter" class="form-select form-select-sm">
                        <option value="">Filter by Status...</option>
                         <?php foreach ($form_data_sources['statuses'] as $status_opt): ?>
                            <option value="<?php echo htmlspecialchars($status_opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($filters['status'] ?? null) == $status_opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status_opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <select name="assigned_user_id_filter" class="form-select form-select-sm">
                        <option value="">Filter by Assignee...</option>
                        <?php foreach ($form_data_sources['users'] as $user): ?>
                            <option value="<?php echo htmlspecialchars((string)$user['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($filters['assigned_user_id'] ?? null) == $user['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                 <?php if ($_SESSION['is_admin']): ?>
                <div class="col-12">
                    <?php if ($show_all_for_admin): ?>
                        <a href="activities.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => 'active'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-sm">Show Active Only</a>
                    <?php else: ?>
                        <a href="activities.php?<?php echo htmlspecialchars(http_build_query(array_merge($filters, ['view' => 'all'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-sm">Show All</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <!-- Note: related_to_type and related_to_id filters are usually set by clicking links from other entity pages, not generic dropdowns here -->
                <input type="hidden" name="related_to_type_filter" value="<?php echo htmlspecialchars($filters['related_to_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="related_to_id_filter" value="<?php echo htmlspecialchars((string)($filters['related_to_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="col-12"><button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button></div>
                <?php if (!empty($filters) || isset($_GET['view'])): ?>
                    <div class="col-12"><a href="activities.php" class="btn btn-outline-danger btn-sm">Clear Filters</a></div>
                <?php endif; ?>
            </form>
        </div>
    </div>
     <?php if (!empty($filters['related_to_type']) && !empty($filters['related_to_id'])):
        $related_entity_name_display = $activity_handler->getRelatedEntityName($filters['related_to_type'], $filters['related_to_id']);
     ?>
        <div class="alert alert-info">Showing activities related to: <strong><?php echo htmlspecialchars($filters['related_to_type'] . ' - ' . $related_entity_name_display, ENT_QUOTES, 'UTF-8'); ?> (ID: <?php echo htmlspecialchars((string)$filters['related_to_id'], ENT_QUOTES, 'UTF-8'); ?>)</strong></div>
    <?php endif; ?>


    <?php if (count($activities) > 0): ?>
        <table class="table table-striped table-sm">
            <thead><tr><th>Subject</th><th>Type</th><th>Status</th><th>Due Date</th><th>Related To</th><th>Assigned To</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><a href="activity_view.php?id=<?php echo htmlspecialchars((string)$activity['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($activity['subject'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?php echo htmlspecialchars($activity['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="badge bg-<?php echo strtolower($activity['status']) == 'completed' ? 'success' : (strtolower($activity['status']) == 'pending' ? 'warning text-dark' : 'secondary'); ?>"><?php echo htmlspecialchars($activity['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?php echo $activity['due_date'] ? htmlspecialchars(date("Y-m-d H:i", strtotime($activity['due_date'])), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                        <td><a href="<?php echo htmlspecialchars(strtolower($activity['related_to_type']), ENT_QUOTES, 'UTF-8'); ?>_view.php?id=<?php echo htmlspecialchars((string)$activity['related_to_id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($activity['related_to_type'] . ': ' . $activity['related_entity_name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><?php echo htmlspecialchars($activity['assigned_user_username'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="activity_view.php?id=<?php echo htmlspecialchars((string)$activity['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info btn-xs">View</a>
                            <a href="activity_edit.php?id=<?php echo htmlspecialchars((string)$activity['id'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning btn-xs">Edit</a>
                            <?php if ($_SESSION['is_admin']): ?>
                                <form action="activities.php?<?php echo htmlspecialchars(http_build_query(array_filter(array_merge($filters, ['view' => $_GET['view'] ?? null]))), ENT_QUOTES, 'UTF-8');?>" method="POST" style="display: inline;">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="activity_id" value="<?php echo htmlspecialchars((string)$activity['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$activity['version'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="is_currently_active" value="<?php echo $activity['is_active'] ? '1' : '0'; ?>">
                                    <button type="submit" name="action" value="toggle_active" class="btn btn-secondary btn-xs"><?php echo $activity['is_active'] ? 'Deact.' : 'Act.'; ?></button>
                                </form>
                                <form action="activities.php?<?php echo htmlspecialchars(http_build_query(array_filter(array_merge($filters, ['view' => $_GET['view'] ?? null]))), ENT_QUOTES, 'UTF-8');?>" method="POST" style="display: inline;" onsubmit="return confirm('PERMANENTLY DELETE this activity?');">
                                    <?php echo csrf_input_field(); ?>
                                    <input type="hidden" name="activity_id" value="<?php echo htmlspecialchars((string)$activity['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" name="action" value="hard_delete" class="btn btn-danger btn-xs">Del.</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No activities found matching your criteria.</p>
    <?php endif; ?>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
