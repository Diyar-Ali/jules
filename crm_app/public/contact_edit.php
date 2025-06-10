<?php
// public/contact_edit.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // User must be logged in
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Contact.php';
require_once __DIR__ . '/../classes/Organisation.php'; // For fetching organisations for dropdown
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/log_helper.php';

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_editing = (bool)$contact_id;

$contact = new Contact($pdo);
$related_data = $contact->getRelatedDataForForms(); // Gets active organisations
$organisations_for_select = $related_data['organisations'];

// Form field initial values
$form_first_name = ''; $form_last_name = ''; $form_email = '';
$form_phone_mobile = ''; $form_phone_work = ''; $form_title = '';
$form_organisation_id = null; $form_is_active = true; // Default for new
$current_version = null;

// Pre-fill organisation_id if passed via GET (e.g., from Organisation view page)
$prefill_organisation_id = filter_input(INPUT_GET, 'organisation_id', FILTER_VALIDATE_INT);
if (!$is_editing && $prefill_organisation_id) {
    $form_organisation_id = $prefill_organisation_id;
}


if ($is_editing) {
    if (!$contact->read($contact_id)) {
        $_SESSION['error_message'] = 'Contact not found.';
        header("Location: {$app_url_base}/contacts_list.php");
        exit;
    }
    // Authorization: If contact is inactive, only admin can edit.
    if (!$contact->is_active && !$is_admin) {
        $_SESSION['error_message'] = 'You do not have permission to edit this inactive contact.';
        log_message('warning', "User ID {$current_user_id} attempt to edit inactive contact ID {$contact_id}");
        header("Location: {$app_url_base}/contacts_list.php");
        exit;
    }

    $page_title = "Edit Contact: " . htmlspecialchars($contact->first_name . ' ' . $contact->last_name);
    // Populate form fields
    $form_first_name = $contact->first_name;
    $form_last_name = $contact->last_name;
    $form_email = $contact->email;
    $form_phone_mobile = $contact->phone_mobile;
    $form_phone_work = $contact->phone_work;
    $form_title = $contact->title;
    $form_organisation_id = $contact->organisation_id;
    $form_is_active = $contact->is_active;
    $current_version = $contact->version;
} else {
    $page_title = "Add New Contact";
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed.';
        log_message('warning', "CSRF token validation failed on contact " . ($is_editing ? "edit" : "create") . " by user ".($current_user_id ?? "N/A"));
    } else {
        $form_first_name = trim($_POST['first_name'] ?? '');
        $form_last_name = trim($_POST['last_name'] ?? '');
        $form_email = trim($_POST['email'] ?? '');
        $form_phone_mobile = trim($_POST['phone_mobile'] ?? '');
        $form_phone_work = trim($_POST['phone_work'] ?? '');
        $form_title = trim($_POST['title'] ?? '');
        $form_organisation_id = !empty($_POST['organisation_id']) ? filter_var($_POST['organisation_id'], FILTER_VALIDATE_INT) : null;

        if ($is_admin) {
            $form_is_active = isset($_POST['is_active']);
        } elseif ($is_editing) { // Non-admin editing existing contact
            // Keep the original active status from the loaded $contact object
            // This ensures non-admins cannot change active status of existing contacts
            $form_is_active = $contact->is_active;
        } else { // Non-admin creating new contact
            $form_is_active = true; // Default active for new by non-admin
        }

        if (empty($form_first_name) || empty($form_last_name)) {
            $error_message = 'First name and last name are required.';
        }
        // Further validation (email format, uniqueness) is handled by the Contact class methods.

        if (empty($error_message)) {
            try {
                // For editing, apply changes to the already loaded $contact object
                // For creating, $contact is a new empty instance
                $contact->first_name = $form_first_name;
                $contact->last_name = $form_last_name;
                $contact->email = $form_email;
                $contact->phone_mobile = $form_phone_mobile;
                $contact->phone_work = $form_phone_work;
                $contact->title = $form_title;
                $contact->organisation_id = $form_organisation_id;
                $contact->is_active = $form_is_active;

                if ($is_editing) {
                    $submitted_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);
                    // $contact->version already holds the version from when it was read
                    if ($submitted_version === false || $submitted_version !== $contact->version) {
                         throw new Exception("Data conflict. The contact record was updated by someone else. Please refresh and try again. (Submitted: {$submitted_version}, Current: {$contact->version})");
                    }
                    // ID is already set in $contact from the read() method call
                    // Version for update() method is taken from $contact->version

                    if ($contact->update()) {
                        $_SESSION['success_message'] = "Contact '".htmlspecialchars($contact->first_name . ' ' . $contact->last_name)."' updated successfully!";
                        log_message('info', "User ID {$current_user_id} updated contact ID {$contact->id}");
                        header("Location: {$app_url_base}/contacts_list.php");
                        exit;
                    }
                } else { // Creating new
                    // $contact->version is already 1 by default from constructor
                    if ($contact->create($current_user_id)) {
                        $_SESSION['success_message'] = "Contact '".htmlspecialchars($contact->first_name . ' ' . $contact->last_name)."' created successfully!";
                        log_message('info', "User ID {$current_user_id} created contact ID {$contact->id}");
                        header("Location: {$app_url_base}/contacts_list.php");
                        exit;
                    }
                }
            } catch (Exception $e) {
                $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
                log_message('error', "UI Exception for contact " . ($is_editing ? "edit ID {$contact_id}" : "create") . " by user {$current_user_id}: " . $e->getMessage());
                 if ($is_editing) { // If error on edit, ensure $current_version is from DB for the form
                    $tempContact = new Contact($pdo); // Use a temporary object to refresh version
                    if ($tempContact->read($contact_id)) $current_version = $tempContact->version; else $current_version = null;
                }
            }
        }
    }

    generate_csrf_token();
    require_once __DIR__ . '/../templates/header.php';
    ?>

    <div class="main-container">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php" class="btn btn-muted">&larr; Back to List</a>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger dismissable-alert">
                <?= $error_message ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success dismissable-alert">
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <form action="contact_edit.php<?= $is_editing ? '?id='.$contact_id : '' ?>" method="POST" class="bg-white p-6 sm:p-8 rounded-lg shadow-lg space-y-6">
            <?= csrf_input_field() ?>
            <?php if ($is_editing): ?>
                <input type="hidden" name="version" value="<?= htmlspecialchars((string)$current_version) ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="first_name" class="form-label">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($form_first_name) ?>" required
                           class="input-field">
                </div>
                <div>
                    <label for="last_name" class="form-label">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($form_last_name) ?>" required
                           class="input-field">
                </div>
            </div>

            <div>
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($form_email) ?>"
                       class="input-field" placeholder="user@example.com">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="phone_mobile" class="form-label">Mobile Phone</label>
                    <input type="tel" name="phone_mobile" id="phone_mobile" value="<?= htmlspecialchars($form_phone_mobile) ?>"
                           class="input-field">
                </div>
                <div>
                    <label for="phone_work" class="form-label">Work Phone</label>
                    <input type="tel" name="phone_work" id="phone_work" value="<?= htmlspecialchars($form_phone_work) ?>"
                           class="input-field">
                </div>
            </div>

            <div>
                <label for="title" class="form-label">Title / Position</label>
                <input type="text" name="title" id="title" value="<?= htmlspecialchars($form_title) ?>"
                       class="input-field">
            </div>

            <div>
                <label for="organisation_id" class="form-label">Organisation</label>
                <select name="organisation_id" id="organisation_id" class="input-field-select">
                    <option value="">None</option>
                    <?php foreach ($organisations_for_select as $org_select): ?>
                        <option value="<?= htmlspecialchars((string)$org_select['id']) ?>" <?= ($form_organisation_id == $org_select['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org_select['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($is_admin): ?>
            <div class="flex items-center mt-4">
                <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?>
                       class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                <label for="is_active" class="ml-2 block text-sm text-gray-900">Contact is Active</label>
            </div>
            <?php elseif ($is_editing): ?>
                 <p class="text-sm text-gray-600 mt-2">Status: <span class="font-semibold"><?= $contact->is_active ? 'Active' : 'Inactive (Contact Admin to change)' ?></span></p>
                 <?php // No hidden input needed for is_active if non-admin cannot change it. Server logic preserves it. ?>
            <?php endif; ?>


            <div class="pt-5">
                <button type="submit" class="btn-primary-full">
                    <?= $is_editing ? 'Save Changes' : 'Create Contact' ?>
                </button>
            </div>

            <?php if ($is_editing): ?>
            <p class="text-xs text-gray-500 mt-1">Created: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($contact->created_at))) ?>, Last Updated: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($contact->updated_at))) ?>, Version: <?= htmlspecialchars((string)$contact->version) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <?php
    require_once __DIR__ . '/../templates/footer.php';
    ?>
    <?php // Local style block removed ?>
