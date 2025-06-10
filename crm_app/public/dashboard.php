<?php
// crm_app/public/dashboard.php
$base_path = __DIR__ . '/../'; // Used for includes
require_once $base_path . 'includes/auth_check.php'; // Ensures user is logged in, includes session_config.php
require_once $base_path . 'includes/log_helper.php'; // For logging, if any specific to dashboard

// $page_title is used by header.php
$page_title = "CRM Dashboard";

// User details from session (already set by auth_check.php or login process)
$username = htmlspecialchars($_SESSION['username']);
$user_role = htmlspecialchars($_SESSION['role']);
$is_admin = $_SESSION['is_admin'];

log_message('INFO', "User '{$username}' (ID: {$_SESSION['user_id']}) accessed dashboard.", $_SESSION['user_id']);

// Include the common header
include_once $base_path . 'templates/header.php';
?>

<!-- Page-specific content starts here -->
<div class="container mt-4"> <!-- Bootstrap container class, mt-4 for spacing if needed -->
    <div class="row">
        <div class="col-12">
            <div class="pagetitle mb-3">
                <h1>Dashboard</h1>
                <p class="lead">Welcome back, <?php echo $username; ?>!</p>
            </div>

            <div class="alert alert-info">
                Your Role: <strong><?php echo ucfirst($user_role); ?></strong>
                <?php if ($is_admin): ?>
                    <span class="fw-bold text-danger"> (Administrator)</span>
                <?php endif; ?>
            </div>

            <p>This is your CRM dashboard. From here you can manage your clients, leads, deals, and activities using the navigation bar above.</p>

            <hr class="my-4">

            <h3>Quick Summary (Placeholders)</h3>
            <div class="row">
                <div class="col-md-4">
                    <div class="card text-white bg-primary mb-3">
                        <div class="card-header">Active Leads</div>
                        <div class="card-body">
                            <h5 class="card-title">XX</h5> <!-- Placeholder for count -->
                            <p class="card-text">View and manage your ongoing leads.</p>
                            <a href="leads.php" class="btn btn-light btn-sm">Go to Leads</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-success mb-3">
                        <div class="card-header">Open Deals</div>
                        <div class="card-body">
                            <h5 class="card-title">YY</h5> <!-- Placeholder for count -->
                            <p class="card-text">Track your sales pipeline and close deals.</p>
                            <a href="deals.php" class="btn btn-light btn-sm">Go to Deals</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-dark bg-warning mb-3">
                        <div class="card-header">Pending Activities</div>
                        <div class="card-body">
                            <h5 class="card-title">ZZ</h5> <!-- Placeholder for count -->
                            <p class="card-text">Manage your tasks, calls, and meetings.</p>
                            <a href="activities.php?status_filter=Pending" class="btn btn-dark btn-sm">Go to Activities</a>
                        </div>
                    </div>
                </div>
            </div>

            <h3 class="mt-4">Quick Actions</h3>
            <div class="list-group">
                <a href="organisation_edit.php" class="list-group-item list-group-item-action">Add New Organisation</a>
                <a href="contact_edit.php" class="list-group-item list-group-item-action">Add New Contact</a>
                <a href="lead_edit.php" class="list-group-item list-group-item-action">Add New Lead</a>
                <a href="deal_edit.php" class="list-group-item list-group-item-action">Add New Deal</a>
                <a href="activity_edit.php" class="list-group-item list-group-item-action">Log New Activity</a>
            </div>

            <p class="mt-5 text-muted"><em>Further content and layout for the dashboard will be detailed in the Stitch prompt for the frontend. This provides a basic structure.</em></p>
        </div>
    </div>
</div>
<!-- Page-specific content ends here -->

<?php
// Include the common footer
include_once $base_path . 'templates/footer.php';
?>
