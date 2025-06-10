<?php
// crm_app/public/organisation_edit.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Organisation.php';
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$organisation_handler = new Organisation($pdo);
$page_title = "Add Organisation";
$error_message = '';
$success_message = '';

$org_data = [
    'id' => null, 'name' => '', 'website' => '', 'phone' => '',
    'address_street' => '', 'address_city' => '', 'address_state' => '',
    'address_zip' => '', 'address_country' => '', 'description' => '',
    'industry' => '', 'annual_revenue' => '', 'version' => null, 'is_active' => true
];

$edit_mode = false;
if (isset($_GET['id'])) {
    $org_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($org_id) {
        $result = $organisation_handler->readOne($org_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $org_data = $result['data'];
            $page_title = "Edit Organisation: " . htmlspecialchars($org_data['name']);
            $edit_mode = true;
        } else {
            $_SESSION['error_message'] = $result['message'];
            header("Location: organisations.php");
            exit;
        }
    } else {
        $_SESSION['error_message'] = "Invalid Organisation ID.";
        header("Location: organisations.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('WARNING', 'CSRF validation failed for organisation edit/create.', $_SESSION['user_id']);
    } else {
        // Collect data from POST
        $name = $_POST['name'] ?? '';
        $website = $_POST['website'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address_street = $_POST['address_street'] ?? '';
        $address_city = $_POST['address_city'] ?? '';
        $address_state = $_POST['address_state'] ?? '';
        $address_zip = $_POST['address_zip'] ?? '';
        $address_country = $_POST['address_country'] ?? '';
        $description = $_POST['description'] ?? '';
        $industry = $_POST['industry'] ?? '';
        $annual_revenue = $_POST['annual_revenue'] ?? '';
        $current_version = $_POST['version'] ?? null; // For updates

        // Update $org_data with submitted values to repopulate form on error
        $org_data = array_merge($org_data, $_POST);


        if ($edit_mode && isset($_POST['id'])) {
            $org_id_post = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            if ($org_id_post && $org_id_post == $org_data['id']) { // Ensure ID matches
                 $result = $organisation_handler->update(
                    $org_data['id'], $name, $website, $phone, $address_street, $address_city, $address_state,
                    $address_zip, $address_country, $description, $industry, $annual_revenue,
                    $current_version, $_SESSION['user_id']
                );
                if ($result['success']) {
                    $_SESSION['message'] = "Organisation updated successfully.";
                    log_message('INFO', "Organisation ID {$org_data['id']} updated by user ID {$_SESSION['user_id']}.");
                    header("Location: organisation_view.php?id=" . $org_data['id']);
                    exit;
                } else {
                    $error_message = $result['message'];
                    // If version conflict, the version in $org_data might be stale. Re-fetch for form.
                    if (strpos($error_message, "conflict") !== false) {
                        $fresh_data_result = $organisation_handler->readOne($org_data['id'], $_SESSION['is_admin']);
                        if ($fresh_data_result['success']) $org_data['version'] = $fresh_data_result['data']['version'];
                    }
                }
            } else {
                $error_message = "Error with organisation ID during update.";
            }
        } else { // Create mode
            $result = $organisation_handler->create(
                $name, $website, $phone, $address_street, $address_city, $address_state,
                $address_zip, $address_country, $description, $industry, $annual_revenue,
                $_SESSION['user_id']
            );
            if ($result['success']) {
                $_SESSION['message'] = "Organisation created successfully.";
                log_message('INFO', "New organisation '{$name}' (ID: {$result['organisation_id']}) created by user ID {$_SESSION['user_id']}.");
                header("Location: organisation_view.php?id=" . $result['organisation_id']);
                exit;
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
    <h1><?php echo $page_title; ?></h1>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form action="organisation_edit.php<?php echo $edit_mode ? '?id='.$org_data['id'] : ''; ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($org_data['id']); ?>">
            <input type="hidden" name="version" value="<?php echo htmlspecialchars($org_data['version']); ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="name" class="form-label">Organisation Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($org_data['name']); ?>" required>
        </div>
        <div class="mb-3">
            <label for="website" class="form-label">Website</label>
            <input type="url" class="form-control" id="website" name="website" value="<?php echo htmlspecialchars($org_data['website']); ?>" placeholder="https://example.com">
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Phone</label>
            <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($org_data['phone']); ?>">
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="address_street" class="form-label">Street Address</label>
                <input type="text" class="form-control" id="address_street" name="address_street" value="<?php echo htmlspecialchars($org_data['address_street']); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="address_city" class="form-label">City</label>
                <input type="text" class="form-control" id="address_city" name="address_city" value="<?php echo htmlspecialchars($org_data['address_city']); ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label for="address_state" class="form-label">State/Province</label>
                <input type="text" class="form-control" id="address_state" name="address_state" value="<?php echo htmlspecialchars($org_data['address_state']); ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label for="address_zip" class="form-label">ZIP/Postal Code</label>
                <input type="text" class="form-control" id="address_zip" name="address_zip" value="<?php echo htmlspecialchars($org_data['address_zip']); ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label for="address_country" class="form-label">Country</label>
                <input type="text" class="form-control" id="address_country" name="address_country" value="<?php echo htmlspecialchars($org_data['address_country']); ?>">
            </div>
        </div>
        <div class="mb-3">
            <label for="industry" class="form-label">Industry</label>
            <input type="text" class="form-control" id="industry" name="industry" value="<?php echo htmlspecialchars($org_data['industry']); ?>">
        </div>
        <div class="mb-3">
            <label for="annual_revenue" class="form-label">Annual Revenue</label>
            <input type="number" step="0.01" class="form-control" id="annual_revenue" name="annual_revenue" value="<?php echo htmlspecialchars($org_data['annual_revenue']); ?>">
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($org_data['description']); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo $edit_mode ? 'Update' : 'Create'; ?> Organisation</button>
        <a href="organisations.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
