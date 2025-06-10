<?php
// crm_app/public/lead_edit.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Lead.php';
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$lead_handler = new Lead($pdo);
$page_title = "Add Lead";
$error_message = '';

$lead_data = [
    'id' => null, 'name' => '', 'source' => '', 'status' => 'New', 'temperature' => 'Cold',
    'description' => '', 'value' => '', 'expected_close_date' => '',
    'contact_id' => null, 'organisation_id' => null, 'assigned_user_id' => $_SESSION['user_id'], // Default assign to current user
    'version' => null, 'is_active' => true, 'converted_to_deal_id' => null
];
$related_data = $lead_handler->getRelatedDataForForms(); // Users, Contacts, Orgs, Statuses, Temps

$edit_mode = false;
if (isset($_GET['id'])) {
    $lead_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($lead_id) {
        $result = $lead_handler->readOne($lead_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $lead_data = $result['data'];
            $page_title = "Edit Lead: " . htmlspecialchars($lead_data['name']);
            $edit_mode = true;
            if ($lead_data['converted_to_deal_id']) {
                 $_SESSION['error_message'] = "This lead has been converted to a deal and cannot be edited further.";
                 header("Location: lead_view.php?id=" . $lead_data['id']);
                 exit;
            }
        } else {
            $_SESSION['error_message'] = $result['message']; header("Location: leads.php"); exit;
        }
    } else {
        $_SESSION['error_message'] = "Invalid Lead ID."; header("Location: leads.php"); exit;
    }
} elseif (isset($_GET['contact_id'])) { // Pre-fill from contact page
    $lead_data['contact_id'] = filter_var($_GET['contact_id'], FILTER_VALIDATE_INT);
    // Potentially fetch organisation from contact if available
} elseif (isset($_GET['organisation_id'])) { // Pre-fill from organisation page
    $lead_data['organisation_id'] = filter_var($_GET['organisation_id'], FILTER_VALIDATE_INT);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
    } else {
        $name = $_POST['name'] ?? '';
        $source = $_POST['source'] ?? '';
        $status = $_POST['status'] ?? 'New';
        $temperature = $_POST['temperature'] ?? 'Cold';
        $description = $_POST['description'] ?? '';
        $value = $_POST['value'] ?? '';
        $expected_close_date = $_POST['expected_close_date'] ?? '';
        $contact_id = !empty($_POST['contact_id']) ? filter_var($_POST['contact_id'], FILTER_VALIDATE_INT) : null;
        $organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? filter_var($_POST['assigned_user_id'], FILTER_VALIDATE_INT) : $_SESSION['user_id'];
        $current_version = $_POST['version'] ?? null;

        // Update lead_data with POST values for form repopulation
        $lead_data = array_merge($lead_data, $_POST);
        // Ensure numeric/null fields are correctly typed after merge if empty string from POST
        $lead_data['value'] = !empty($_POST['value']) ? $_POST['value'] : null;
        $lead_data['contact_id'] = $contact_id;
        $lead_data['organisation_id'] = $organisation_id;
        $lead_data['assigned_user_id'] = $assigned_user_id;


        if ($edit_mode && isset($_POST['id'])) {
            $lead_id_post = filter_var($_POST['id'], FILTER_VALIDATE_INT);
             if ($lead_data['converted_to_deal_id']) { // Double check on POST
                $error_message = "Converted leads cannot be edited.";
            } else if ($lead_id_post && $lead_id_post == $lead_data['id']) {
                $result = $lead_handler->update(
                    $lead_data['id'], $name, $source, $status, $temperature, $description, $value,
                    $expected_close_date, $contact_id, $organisation_id, $assigned_user_id,
                    $current_version, $_SESSION['user_id']
                );
                if ($result['success']) {
                    $_SESSION['message'] = "Lead updated successfully.";
                    header("Location: lead_view.php?id=" . $lead_data['id']); exit;
                } else {
                    $error_message = $result['message'];
                    if (strpos($error_message, "conflict") !== false) {
                        $fresh_data = $lead_handler->readOne($lead_data['id'], $_SESSION['is_admin']);
                        if($fresh_data['success']) $lead_data['version'] = $fresh_data['data']['version'];
                    }
                }
            } else { $error_message = "Error with Lead ID during update."; }
        } else { // Create mode
            $result = $lead_handler->create(
                $name, $source, $status, $temperature, $description, $value,
                $expected_close_date, $contact_id, $organisation_id, $assigned_user_id,
                $_SESSION['user_id']
            );
            if ($result['success']) {
                $_SESSION['message'] = "Lead created successfully.";
                header("Location: lead_view.php?id=" . $result['lead_id']); exit;
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

    <form action="lead_edit.php<?php echo $edit_mode ? '?id='.htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8') : ''; ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$lead_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$lead_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($lead_data['converted_to_deal_id']): ?>
                <div class="alert alert-warning">This lead has been converted to Deal ID <?php echo htmlspecialchars((string)$lead_data['converted_to_deal_id'], ENT_QUOTES, 'UTF-8'); ?> and cannot be edited.</div>
            <?php endif; ?>
        <?php endif; ?>

        <fieldset <?php echo ($edit_mode && $lead_data['converted_to_deal_id']) ? 'disabled' : ''; ?>>
            <div class="mb-3">
                <label for="name" class="form-label">Lead Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($lead_data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($related_data['statuses'] as $stat): ?>
                        <option value="<?php echo htmlspecialchars($stat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($lead_data['status'] == $stat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($stat, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="temperature" class="form-label">Temperature</label>
                    <select class="form-select" id="temperature" name="temperature">
                         <?php foreach ($related_data['temperatures'] as $temp): ?>
                        <option value="<?php echo htmlspecialchars($temp, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($lead_data['temperature'] == $temp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($temp, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
             <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="value" class="form-label">Value (Est.)</label>
                    <input type="number" step="0.01" class="form-control" id="value" name="value" value="<?php echo htmlspecialchars($lead_data['value'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="expected_close_date" class="form-label">Expected Close Date</label>
                    <input type="date" class="form-control" id="expected_close_date" name="expected_close_date" value="<?php echo htmlspecialchars($lead_data['expected_close_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="source" class="form-label">Source</label>
                <input type="text" class="form-control" id="source" name="source" value="<?php echo htmlspecialchars($lead_data['source'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($lead_data['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="contact_id" class="form-label">Related Contact</label>
                    <select class="form-select" id="contact_id" name="contact_id">
                        <option value="">-- Select Contact (Optional) --</option>
                        <?php foreach ($related_data['contacts'] as $contact): ?>
                        <option value="<?php echo htmlspecialchars((string)$contact['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($lead_data['contact_id'] == $contact['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name'] . ($contact['email'] ? ' - '.$contact['email'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="organisation_id" class="form-label">Related Organisation</label>
                    <select class="form-select" id="organisation_id" name="organisation_id">
                        <option value="">-- Select Organisation (Optional) --</option>
                         <?php foreach ($related_data['organisations'] as $org): ?>
                        <option value="<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($lead_data['organisation_id'] == $org['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="assigned_user_id" class="form-label">Assigned To User <span class="text-danger">*</span></label>
                <select class="form-select" id="assigned_user_id" name="assigned_user_id" required>
                    <option value="">-- Select User --</option>
                    <?php foreach ($related_data['users'] as $user): ?>
                    <option value="<?php echo htmlspecialchars((string)$user['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($lead_data['assigned_user_id'] == $user['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['username'] . ($user['first_name'] ? ' ('.$user['first_name'].' '.$user['last_name'].')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>

        <?php if (!($edit_mode && $lead_data['converted_to_deal_id'])): ?>
        <button type="submit" class="btn btn-primary"><?php echo $edit_mode ? 'Update' : 'Create'; ?> Lead</button>
        <?php endif; ?>
        <a href="leads.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
