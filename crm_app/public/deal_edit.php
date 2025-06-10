<?php
// crm_app/public/deal_edit.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Deal.php';
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$deal_handler = new Deal($pdo);
$page_title = "Add Deal";
$error_message = '';

$deal_data = [
    'id' => null, 'name' => '', 'stage' => 'Prospecting', 'amount' => '', 'close_date' => '',
    'probability' => null, 'description' => '', 'organisation_id' => null, 'contact_id' => null,
    'assigned_user_id' => $_SESSION['user_id'], 'version' => null, 'is_active' => true
];
$related_data = $deal_handler->getRelatedDataForForms();

$edit_mode = false;
if (isset($_GET['id'])) {
    $deal_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($deal_id) {
        $result = $deal_handler->readOne($deal_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $deal_data = $result['data'];
            // Ensure probability is formatted for the input field (e.g., 0.75 not 0.7500 if db stores more precision)
            if (isset($deal_data['probability'])) $deal_data['probability'] = rtrim(rtrim(number_format($deal_data['probability'], 4), '0'), '.');

            $page_title = "Edit Deal: " . htmlspecialchars($deal_data['name']);
            $edit_mode = true;
        } else {
            $_SESSION['error_message'] = $result['message']; header("Location: deals.php"); exit;
        }
    } else {
        $_SESSION['error_message'] = "Invalid Deal ID."; header("Location: deals.php"); exit;
    }
} elseif (isset($_GET['organisation_id'])) { // Pre-fill from organisation page
    $deal_data['organisation_id'] = filter_var($_GET['organisation_id'], FILTER_VALIDATE_INT);
} elseif (isset($_GET['contact_id'])) { // Pre-fill from contact page
    $deal_data['contact_id'] = filter_var($_GET['contact_id'], FILTER_VALIDATE_INT);
    // Optionally try to get org_id from contact if creating from contact page and contact has org.
    if ($deal_data['contact_id']) {
        $stmt_contact_org = $pdo->prepare("SELECT organisation_id FROM contacts WHERE id = :cid");
        $stmt_contact_org->bindParam(':cid', $deal_data['contact_id']);
        $stmt_contact_org->execute();
        $contact_org_id = $stmt_contact_org->fetchColumn();
        if ($contact_org_id) $deal_data['organisation_id'] = $contact_org_id;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
    } else {
        $name = $_POST['name'] ?? '';
        $stage = $_POST['stage'] ?? 'Prospecting';
        $amount = $_POST['amount'] ?? ''; // Validation in class
        $close_date = $_POST['close_date'] ?? '';
        $probability_input = $_POST['probability'] ?? null;
        $description = $_POST['description'] ?? '';
        $organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;
        $contact_id = !empty($_POST['contact_id']) ? filter_var($_POST['contact_id'], FILTER_VALIDATE_INT) : null;
        $assigned_user_id = !empty($_POST['assigned_user_id']) ? filter_var($_POST['assigned_user_id'], FILTER_VALIDATE_INT) : $_SESSION['user_id'];
        $current_version = $_POST['version'] ?? null;

        $probability = ($probability_input === '' || $probability_input === null) ? null : floatval($probability_input);


        $deal_data = array_merge($deal_data, $_POST); // Repopulate form
        $deal_data['probability'] = $probability; // Ensure correct type for repopulation
        $deal_data['organisation_id'] = $organisation_id; // Ensure required field is correctly repopulated

        if ($edit_mode && isset($_POST['id'])) {
            $deal_id_post = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            if ($deal_id_post && $deal_id_post == $deal_data['id']) {
                $result = $deal_handler->update(
                    $deal_data['id'], $name, $stage, $amount, $close_date, $probability, $description,
                    $organisation_id, $contact_id, $assigned_user_id, $current_version, $_SESSION['user_id']
                );
                if ($result['success']) {
                    $_SESSION['message'] = "Deal updated successfully.";
                    header("Location: deal_view.php?id=" . $deal_data['id']); exit;
                } else {
                    $error_message = $result['message'];
                    if (strpos($error_message, "conflict") !== false) {
                        $fresh_data = $deal_handler->readOne($deal_data['id'], $_SESSION['is_admin']);
                        if($fresh_data['success']) $deal_data['version'] = $fresh_data['data']['version'];
                    }
                }
            } else { $error_message = "Error with Deal ID during update."; }
        } else { // Create mode
            $result = $deal_handler->create(
                $name, $stage, $amount, $close_date, $probability, $description,
                $organisation_id, $contact_id, $assigned_user_id, $_SESSION['user_id']
            );
            if ($result['success']) {
                $_SESSION['message'] = "Deal created successfully.";
                header("Location: deal_view.php?id=" . $result['deal_id']); exit;
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

    <form action="deal_edit.php<?php echo $edit_mode ? '?id='.htmlspecialchars((string)$deal_data['id'], ENT_QUOTES, 'UTF-8') : ''; ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$deal_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$deal_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="name" class="form-label">Deal Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($deal_data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="organisation_id" class="form-label">Organisation <span class="text-danger">*</span></label>
                <select class="form-select" id="organisation_id" name="organisation_id" required>
                    <option value="">-- Select Organisation --</option>
                    <?php foreach ($related_data['organisations'] as $org): ?>
                    <option value="<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($deal_data['organisation_id'] == $org['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="contact_id" class="form-label">Primary Contact</label>
                <select class="form-select" id="contact_id" name="contact_id">
                    <option value="">-- Select Contact (Optional) --</option>
                    <?php foreach ($related_data['contacts'] as $contact): ?>
                    <option value="<?php echo htmlspecialchars((string)$contact['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($deal_data['contact_id'] == $contact['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name'] . ($contact['email'] ? ' ('.$contact['email'].')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="stage" class="form-label">Stage <span class="text-danger">*</span></label>
                <select class="form-select" id="stage" name="stage" required>
                    <?php foreach ($related_data['stages'] as $st): ?>
                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($deal_data['stage'] == $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                <input type="number" step="0.01" class="form-control" id="amount" name="amount" value="<?php echo htmlspecialchars($deal_data['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="close_date" class="form-label">Expected Close Date</label>
                <input type="date" class="form-control" id="close_date" name="close_date" value="<?php echo htmlspecialchars($deal_data['close_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="probability" class="form-label">Probability (0.00 to 1.00)</label>
                <input type="number" step="0.01" min="0" max="1" class="form-control" id="probability" name="probability" value="<?php echo htmlspecialchars($deal_data['probability'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., 0.75 for 75%">
            </div>
        </div>
        <div class="mb-3">
            <label for="assigned_user_id" class="form-label">Assigned To User</label>
            <select class="form-select" id="assigned_user_id" name="assigned_user_id">
                <option value="">-- Unassigned --</option>
                <?php foreach ($related_data['users'] as $user): ?>
                <option value="<?php echo htmlspecialchars((string)$user['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($deal_data['assigned_user_id'] == $user['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($user['username'] . ($user['first_name'] ? ' ('.$user['first_name'].' '.$user['last_name'].')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($deal_data['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo $edit_mode ? 'Update' : 'Create'; ?> Deal</button>
        <a href="deals.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
