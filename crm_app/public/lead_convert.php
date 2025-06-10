<?php
// crm_app/public/lead_convert.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php'; // Ensures user is logged in
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Lead.php';     // Includes Deal.php and LeadStatusHistory.php
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$lead_handler = new Lead($pdo);
$page_title = "Convert Lead to Deal";
$error_message = '';
$success_message = '';
$lead_data = null;

if (!isset($_GET['id'])) {
    $_SESSION['error_message'] = "No Lead ID specified for conversion.";
    header("Location: leads.php");
    exit;
}

$lead_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if (!$lead_id) {
    $_SESSION['error_message'] = "Invalid Lead ID specified.";
    header("Location: leads.php");
    exit;
}

// Fetch lead data for display and confirmation
$result = $lead_handler->readOne($lead_id, $_SESSION['is_admin']); // Allow admin to see inactive to potentially convert if needed? Or restrict to active? For now, use readOne.
if (!$result['success']) {
    $_SESSION['error_message'] = $result['message'] ?? "Failed to retrieve lead details.";
    header("Location: leads.php");
    exit;
}
$lead_data = $result['data'];

if (!$lead_data['is_active']) {
    $_SESSION['error_message'] = "Inactive leads cannot be converted. Please activate the lead first.";
    header("Location: lead_view.php?id=" . $lead_id);
    exit;
}
if (!empty($lead_data['converted_to_deal_id'])) {
    $_SESSION['message'] = "This lead has already been converted to Deal ID: " . $lead_data['converted_to_deal_id'];
    header("Location: deal_view.php?id=" . $lead_data['converted_to_deal_id']);
    exit;
}

// For Deal stage dropdown
$deal_stages = Deal::$stages; // Assuming Deal class has public static stages
$default_deal_name = $lead_data['name'] . " - Deal";


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Please try again.';
    } elseif (isset($_POST['confirm_conversion'])) {
        $custom_deal_name = !empty($_POST['deal_name']) ? strip_tags($_POST['deal_name']) : $default_deal_name;
        $deal_stage = $_POST['deal_stage'] ?? 'Qualification'; // Default stage for new deal

        if (!in_array($deal_stage, $deal_stages)) {
            $error_message = "Invalid deal stage selected.";
        } else {
            $conversion_result = $lead_handler->convertToDeal($lead_id, $_SESSION['user_id'], $deal_stage, $custom_deal_name);

            if ($conversion_result['success']) {
                $_SESSION['message'] = $conversion_result['message'];
                log_message('INFO', "Lead ID {$lead_id} converted to Deal ID {$conversion_result['deal_id']} by user ID {$_SESSION['user_id']}.");
                header("Location: deal_view.php?id=" . $conversion_result['deal_id']);
                exit;
            } else {
                $error_message = $conversion_result['message'] ?? "An unknown error occurred during lead conversion.";
                log_message('ERROR', "Lead conversion failed for Lead ID {$lead_id}. User ID {$_SESSION['user_id']}. Error: {$error_message}");
            }
        }
    } else {
        // Cancelled
        $_SESSION['message'] = "Lead conversion cancelled.";
        header("Location: lead_view.php?id=" . $lead_id);
        exit;
    }
}

$csrf_token = generate_csrf_token();
include_once $base_path . 'templates/header.php';
?>
<div class="container">
    <h1><?php echo $page_title; ?>: <?php echo htmlspecialchars($lead_data['name']); ?></h1>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header">Confirm Lead Conversion</div>
        <div class="card-body">
            <p>You are about to convert the following lead into a new deal:</p>
            <ul>
                <li><strong>Lead Name:</strong> <?php echo htmlspecialchars($lead_data['name']); ?></li>
                <li><strong>Lead Value:</strong> <?php echo $lead_data['value'] ? number_format($lead_data['value'], 2) : 'N/A'; ?> (This will be the initial deal amount)</li>
                <li><strong>Contact:</strong> <?php echo ($lead_data['contact_first_name'] ? htmlspecialchars($lead_data['contact_first_name'] . ' ' . $lead_data['contact_last_name']) : 'N/A'); ?></li>
                <li><strong>Organisation:</strong> <?php echo htmlspecialchars($lead_data['organisation_name'] ?? 'N/A'); ?>
                    <?php if (empty($lead_data['organisation_id']) && !empty($lead_data['contact_id'])): ?>
                        <span class="text-warning">(Organisation will be derived from contact if possible, otherwise conversion may fail if contact has no org.)</span>
                    <?php elseif (empty($lead_data['organisation_id'])): ?>
                         <span class="text-danger">(No Organisation linked. An Organisation is required for deal conversion. Please update lead or its contact.)</span>
                    <?php endif; ?>
                </li>
                <li><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($lead_data['description'] ?? 'N/A')); ?></li>
            </ul>
            <hr>
            <form action="lead_convert.php?id=<?php echo $lead_id; ?>" method="POST">
                <?php echo csrf_input_field(); ?>
                <div class="mb-3">
                    <label for="deal_name" class="form-label">New Deal Name:</label>
                    <input type="text" class="form-control" id="deal_name" name="deal_name" value="<?php echo htmlspecialchars($default_deal_name); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="deal_stage" class="form-label">Initial Deal Stage:</label>
                    <select class="form-select" id="deal_stage" name="deal_stage">
                        <?php foreach ($deal_stages as $stage_option): ?>
                            <option value="<?php echo htmlspecialchars($stage_option); ?>" <?php echo ($stage_option === 'Qualification') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($stage_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p class="text-muted">The lead's status will be set to 'Converted'.</p>

                <button type="submit" name="confirm_conversion" class="btn btn-success">Confirm Conversion</button>
                <a href="lead_view.php?id=<?php echo $lead_id; ?>" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
