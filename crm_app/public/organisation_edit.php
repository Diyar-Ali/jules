<?php
// public/organisation_edit.php

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // User must be logged in
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../classes/Organisation.php';
require_once __DIR__ . '/../includes/csrf_helper.php';
require_once __DIR__ . '/../includes/log_helper.php';

$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
$current_user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['is_admin'] ?? false;

$organisation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$is_editing = (bool)$organisation_id;

$organisation = new Organisation($pdo);

// Form field initial values
$form_name = ''; $form_website = ''; $form_phone = '';
$form_address_street = ''; $form_address_city = ''; $form_address_state = '';
$form_address_zip = ''; $form_address_country = ''; $form_description = '';
$form_industry = ''; $form_annual_revenue = ''; $form_is_active = true; // Default for new
$current_version = null; // Will be populated in edit mode

if ($is_editing) {
    if (!$organisation->read($organisation_id)) {
        $_SESSION['error_message'] = 'Organisation not found.';
        header("Location: {$app_url_base}/organisations_list.php");
        exit;
    }
    // Authorization: If org is inactive, only admin can edit. Active orgs can be edited by anyone logged in.
    if (!$organisation->is_active && !$is_admin) {
        $_SESSION['error_message'] = 'You do not have permission to edit this inactive organisation.';
        log_message('warning', "User ID {$current_user_id} attempt to edit inactive organisation ID {$organisation_id}");
        header("Location: {$app_url_base}/organisations_list.php");
        exit;
    }

    $page_title = "Edit Organisation: " . htmlspecialchars($organisation->name);
    // Populate form fields from loaded organisation
    $form_name = $organisation->name;
    $form_website = $organisation->website;
    $form_phone = $organisation->phone;
    $form_address_street = $organisation->address_street;
    $form_address_city = $organisation->address_city;
    $form_address_state = $organisation->address_state;
    $form_address_zip = $organisation->address_zip;
    $form_address_country = $organisation->address_country;
    $form_description = $organisation->description;
    $form_industry = $organisation->industry;
    $form_annual_revenue = $organisation->annual_revenue;
    $form_is_active = $organisation->is_active;
    $current_version = $organisation->version;
} else {
    $page_title = "Add New Organisation";
    // Default is_active is true, already set in declaration
    // For new orgs, version will be set by the class/DB.
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $error_message = 'CSRF token validation failed. Please try again.';
        log_message('warning', "CSRF token validation failed on organisation " . ($is_editing ? "edit" : "create") . " attempt by user ID {$current_user_id}");
    } else {
        // Repopulate form fields from POST for sticky form
        $form_name = trim($_POST['name'] ?? '');
        $form_website = trim($_POST['website'] ?? '');
        $form_phone = trim($_POST['phone'] ?? '');
        $form_address_street = trim($_POST['address_street'] ?? '');
        $form_address_city = trim($_POST['address_city'] ?? '');
        $form_address_state = trim($_POST['address_state'] ?? '');
        $form_address_zip = trim($_POST['address_zip'] ?? '');
        $form_address_country = trim($_POST['address_country'] ?? '');
        $form_description = trim($_POST['description'] ?? '');
        $form_industry = trim($_POST['industry'] ?? '');

        $raw_annual_revenue = $_POST['annual_revenue'] ?? '';
        if ($raw_annual_revenue === '') {
            $form_annual_revenue = null;
        } else {
            $form_annual_revenue = filter_var($raw_annual_revenue, FILTER_VALIDATE_FLOAT);
            if ($form_annual_revenue === false) {
                $error_message = "Invalid format for Annual Revenue. Please enter a valid number or leave blank.";
            }
        }

        if ($is_admin) {
            $form_is_active = isset($_POST['is_active']);
        } elseif ($is_editing) {
            // Non-admin editing: if org exists, its active status cannot be changed by non-admin here
            // So, we must re-assign it from the loaded $organisation object's state not from POST
             $form_is_active = $organisation->is_active;
        } else {
            $form_is_active = true; // New orgs by non-admins are active
        }


        if (empty($form_name)) {
            $error_message = 'Organisation name is required.';
        }
        // Add other specific validations as needed (e.g., website format)
        if (!empty($form_website) && !filter_var($form_website, FILTER_VALIDATE_URL) && !str_starts_with($form_website, 'http://') && !str_starts_with($form_website, 'https://')) {
            // Allow URLs without scheme for convenience, prepend http://
            if (filter_var('http://' . $form_website, FILTER_VALIDATE_URL)) {
                 $form_website = 'http://' . $form_website;
            } else {
                 $error_message = 'Invalid website URL format.';
            }
        }


        if (empty($error_message)) {
            try {
                // For editing, we need to load the existing record into the $organisation object
                // if it wasn't already (e.g. page load vs post processing)
                // However, $organisation is already loaded if $is_editing.
                // We need to apply POSTed values to this $organisation object.

                $organisation->name = $form_name;
                $organisation->website = $form_website;
                $organisation->phone = $form_phone;
                $organisation->address_street = $form_address_street;
                $organisation->address_city = $form_address_city;
                $organisation->address_state = $form_address_state;
                $organisation->address_zip = $form_address_zip;
                $organisation->address_country = $form_address_country;
                $organisation->description = $form_description;
                $organisation->industry = $form_industry;
                $organisation->annual_revenue = $form_annual_revenue;
                $organisation->is_active = $form_is_active;

                if ($is_editing) {
                    $submitted_version = filter_input(INPUT_POST, 'version', FILTER_VALIDATE_INT);
                    // $organisation object already has its version from the initial read.
                    if ($submitted_version === false || $submitted_version !== $organisation->version) {
                         throw new Exception("Data conflict. The organisation record was updated by someone else. Please refresh and try again. (Submitted: {$submitted_version}, Current: {$organisation->version})");
                    }
                    // ID and version are already set on $organisation object from initial read

                    if ($organisation->update()) {
                        $_SESSION['success_message'] = "Organisation '".htmlspecialchars($organisation->name)."' updated successfully!";
                        log_message('info', "User ID {$current_user_id} updated organisation ID {$organisation->id}");
                        header("Location: {$app_url_base}/organisations_list.php");
                        exit;
                    } else {
                        $error_message = 'Failed to update organisation. Optimistic lock failed or no data changed.';
                    }
                } else { // Creating new
                    // $organisation->version is already 1 by default from constructor
                    if ($organisation->create($current_user_id)) {
                        $_SESSION['success_message'] = "Organisation '".htmlspecialchars($organisation->name)."' created successfully!";
                        log_message('info', "User ID {$current_user_id} created organisation ID {$organisation->id}");
                        header("Location: {$app_url_base}/organisations_list.php");
                        exit;
                    } else {
                        $error_message = 'Failed to create organisation.';
                    }
                }
            } catch (Exception $e) {
                $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
                log_message('error', "Exception during organisation " . ($is_editing ? "edit ID {$organisation_id}" : "create") . " by user ID {$current_user_id}: " . $e->getMessage());
                 if ($is_editing) { // If error on edit, ensure $current_version is from DB
                    $tempOrg = new Organisation($pdo);
                    if ($tempOrg->read($organisation_id)) $current_version = $tempOrg->version; else $current_version = null; // Refresh version
                }
            }
        }
    }

    $distinct_industries = Organisation::getDistinctIndustries($pdo);

    generate_csrf_token();
    require_once __DIR__ . '/../templates/header.php';
    ?>

    <div class="main-container">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <a href="<?= htmlspecialchars($app_url_base) ?>/organisations_list.php" class="btn btn-muted">&larr; Back to List</a>
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

        <form action="organisation_edit.php<?= $is_editing ? '?id='.$organisation_id : '' ?>" method="POST" class="bg-white p-6 sm:p-8 rounded-lg shadow-lg space-y-6">
            <?= csrf_input_field() ?>
            <?php if ($is_editing): ?>
                <input type="hidden" name="version" value="<?= htmlspecialchars((string)$current_version) ?>">
            <?php endif; ?>

            <div>
                <label for="name" class="form-label">Organisation Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($form_name) ?>" required
                       class="input-field">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="website" class="form-label">Website</label>
                    <input type="text" name="website" id="website" value="<?= htmlspecialchars($form_website) ?>" placeholder="https://example.com"
                           class="input-field">
                </div>
                <div>
                    <label for="phone" class="form-label">Phone</label>
                    <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($form_phone) ?>"
                           class="input-field">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="industry" class="form-label">Industry</label>
                     <input list="industry_options" name="industry" id="industry" value="<?= htmlspecialchars($form_industry) ?>" class="input-field">
                    <datalist id="industry_options">
                        <?php foreach ($distinct_industries as $industry_option): ?>
                            <option value="<?= htmlspecialchars($industry_option) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label for="annual_revenue" class="form-label">Annual Revenue</label>
                    <input type="number" name="annual_revenue" id="annual_revenue" value="<?= htmlspecialchars((string)$form_annual_revenue) ?>" step="0.01" placeholder="e.g., 100000.00"
                           class="input-field">
                </div>
            </div>

            <div>
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" rows="4"
                          class="input-field"><?= htmlspecialchars($form_description) ?></textarea>
            </div>

            <fieldset class="mt-6">
                <legend class="text-base font-medium text-gray-900 mb-2">Address</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="address_street" class="form-label">Street</label>
                        <input type="text" name="address_street" id="address_street" value="<?= htmlspecialchars($form_address_street) ?>" class="input-field">
                    </div>
                    <div>
                        <label for="address_city" class="form-label">City</label>
                        <input type="text" name="address_city" id="address_city" value="<?= htmlspecialchars($form_address_city) ?>" class="input-field">
                    </div>
                    <div>
                        <label for="address_state" class="form-label">State / Province</label>
                        <input type="text" name="address_state" id="address_state" value="<?= htmlspecialchars($form_address_state) ?>" class="input-field">
                    </div>
                     <div>
                        <label for="address_zip" class="form-label">ZIP / Postal Code</label>
                        <input type="text" name="address_zip" id="address_zip" value="<?= htmlspecialchars($form_address_zip) ?>" class="input-field">
                    </div>
                    <div class="md:col-span-2">
                        <label for="address_country" class="form-label">Country</label>
                        <input type="text" name="address_country" id="address_country" value="<?= htmlspecialchars($form_address_country) ?>" class="input-field">
                    </div>
                </div>
            </fieldset>

            <?php if ($is_admin): ?>
            <div class="flex items-center mt-4">
                <input id="is_active" name="is_active" type="checkbox" value="1" <?= $form_is_active ? 'checked' : '' ?>
                       class="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary">
                <label for="is_active" class="ml-2 block text-sm text-gray-900">
                    Organisation is Active
                </label>
            </div>
            <?php elseif ($is_editing):  ?>
                 <p class="text-sm text-gray-600 mt-2">Status: <span class="font-semibold"><?= $organisation->is_active ? 'Active' : 'Inactive (Contact Admin to change)' ?></span></p>
                 <?php // No hidden input needed for is_active if non-admin cannot change it. Server logic preserves it. ?>
            <?php endif; ?>


            <div class="pt-5">
                <button type="submit" class="btn-primary-full">
                    <?= $is_editing ? 'Save Changes' : 'Create Organisation' ?>
                </button>
            </div>

            <?php if ($is_editing): ?>
            <p class="text-xs text-gray-500 mt-1">Created: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($organisation->created_at))) ?>, Last Updated: <?= htmlspecialchars(date('M j, Y, g:i a', strtotime($organisation->updated_at))) ?>, Version: <?= htmlspecialchars((string)$organisation->version) ?></p>
            <?php endif; ?>
        </form>
    </div>

    <?php
    require_once __DIR__ . '/../templates/footer.php';
    ?>
    <?php // Local style block removed, using global styles from header.php ?>
