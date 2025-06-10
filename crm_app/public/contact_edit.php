<?php
// crm_app/public/contact_edit.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php';
require_once $base_path . 'config/db.php';
require_once $base_path . 'classes/Contact.php'; // Also includes getAllOrganisationsForSelect
require_once $base_path . 'includes/csrf_helper.php';
require_once $base_path . 'includes/log_helper.php';

$contact_handler = new Contact($pdo);
$page_title = "Add Contact";
$error_message = '';

$contact_data = [
    'id' => null, 'first_name' => '', 'last_name' => '', 'email' => '',
    'phone_mobile' => '', 'phone_work' => '', 'title' => '',
    'organisation_id' => null, 'version' => null, 'is_active' => true
];
$organisations_list = $contact_handler->getAllOrganisationsForSelect(); // For dropdown

$edit_mode = false;
if (isset($_GET['id'])) {
    $contact_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($contact_id) {
        $result = $contact_handler->readOne($contact_id, $_SESSION['is_admin']);
        if ($result['success']) {
            $contact_data = $result['data'];
            $page_title = "Edit Contact: " . htmlspecialchars($contact_data['first_name'] . ' ' . $contact_data['last_name']);
            $edit_mode = true;
        } else {
            $_SESSION['error_message'] = $result['message'];
            header("Location: contacts.php");
            exit;
        }
    } else {
        $_SESSION['error_message'] = "Invalid Contact ID.";
        header("Location: contacts.php");
        exit;
    }
} elseif (isset($_GET['organisation_id'])) { // Pre-fill organisation if adding contact from organisation page
    $contact_data['organisation_id'] = filter_var($_GET['organisation_id'], FILTER_VALIDATE_INT);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('WARNING', 'CSRF validation failed for contact edit/create.', $_SESSION['user_id']);
    } else {
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone_mobile = $_POST['phone_mobile'] ?? '';
        $phone_work = $_POST['phone_work'] ?? '';
        $title = $_POST['title'] ?? '';
        $organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;
        $current_version = $_POST['version'] ?? null;

        $contact_data = array_merge($contact_data, $_POST); // Repopulate form

        if ($edit_mode && isset($_POST['id'])) {
            $contact_id_post = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            if ($contact_id_post && $contact_id_post == $contact_data['id']) {
                $result = $contact_handler->update(
                    $contact_data['id'], $first_name, $last_name, $email, $phone_mobile, $phone_work,
                    $title, $organisation_id, $current_version, $_SESSION['user_id']
                );
                if ($result['success']) {
                    $_SESSION['message'] = "Contact updated successfully.";
                    log_message('INFO', "Contact ID {$contact_data['id']} updated by user ID {$_SESSION['user_id']}.");
                    header("Location: contact_view.php?id=" . $contact_data['id']);
                    exit;
                } else {
                    $error_message = $result['message'];
                    if (strpos($error_message, "conflict") !== false) {
                        $fresh_data = $contact_handler->readOne($contact_data['id'], $_SESSION['is_admin']);
                        if($fresh_data['success']) $contact_data['version'] = $fresh_data['data']['version'];
                    }
                }
            } else { $error_message = "Error with contact ID during update."; }
        } else { // Create mode
            $result = $contact_handler->create(
                $first_name, $last_name, $email, $phone_mobile, $phone_work,
                $title, $organisation_id, $_SESSION['user_id']
            );
            if ($result['success']) {
                $_SESSION['message'] = "Contact created successfully.";
                log_message('INFO', "New contact '{$first_name} {$last_name}' (ID: {$result['contact_id']}) created by user ID {$_SESSION['user_id']}.");
                header("Location: contact_view.php?id=" . $result['contact_id']);
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
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form action="contact_edit.php<?php echo $edit_mode ? '?id='.htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8') : (isset($_GET['organisation_id']) ? '?organisation_id='.htmlspecialchars((string)$_GET['organisation_id'], ENT_QUOTES, 'UTF-8') : ''); ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$contact_data['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="version" value="<?php echo htmlspecialchars((string)$contact_data['version'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($contact_data['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($contact_data['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($contact_data['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="phone_mobile" class="form-label">Mobile Phone</label>
                <input type="tel" class="form-control" id="phone_mobile" name="phone_mobile" value="<?php echo htmlspecialchars($contact_data['phone_mobile'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="phone_work" class="form-label">Work Phone</label>
                <input type="tel" class="form-control" id="phone_work" name="phone_work" value="<?php echo htmlspecialchars($contact_data['phone_work'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="mb-3">
            <label for="title" class="form-label">Title/Position</label>
            <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($contact_data['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="mb-3">
            <label for="organisation_id" class="form-label">Organisation</label>
            <select class="form-select" id="organisation_id" name="organisation_id">
                <option value="">-- Select Organisation (Optional) --</option>
                <?php foreach ($organisations_list as $org): ?>
                    <option value="<?php echo htmlspecialchars((string)$org['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($contact_data['organisation_id'] == $org['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo $edit_mode ? 'Update' : 'Create'; ?> Contact</button>
        <a href="contacts.php<?php echo ($contact_data['organisation_id'] && !$edit_mode) ? '?organisation_id_filter='.htmlspecialchars((string)$contact_data['organisation_id'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php include_once $base_path . 'templates/footer.php'; ?>
