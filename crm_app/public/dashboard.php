<?php
// crm_app/public/dashboard.php
$base_path = __DIR__ . '/../';
require_once $base_path . 'includes/auth_check.php'; // Ensures user is logged in, includes session_config.php
require_once $base_path . 'includes/log_helper.php';

// At this point, $_SESSION['user_id'], $_SESSION['username'], etc., are available.
$username = htmlspecialchars($_SESSION['username']);
$user_role = htmlspecialchars($_SESSION['role']);
$is_admin = $_SESSION['is_admin'];

log_message('INFO', "User '{$username}' accessed dashboard.", $_SESSION['user_id']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard</title>
    <style>
        body { font-family: sans-serif; margin: 0; background-color: #f4f4f4; color: #333; }
        .navbar { background-color: #333; padding: 10px 20px; color: white; overflow: hidden; }
        .navbar a { float: left; display: block; color: white; text-align: center; padding: 14px 16px; text-decoration: none; }
        .navbar a:hover { background-color: #ddd; color: black; }
        .navbar .logout { float: right; }
        .container { padding: 20px; }
        .welcome { font-size: 1.5em; margin-bottom: 20px; }
        .role-info { background-color: #e9ecef; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .admin-notice { color: #dc3545; font-weight: bold; }
        /* Basic structure for links, will be expanded by Stitch prompt */
        nav ul { list-style-type: none; padding: 0; }
        nav ul li { margin-bottom: 10px; }
        nav ul li a { text-decoration: none; color: #007bff; }
        nav ul li a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="navbar">
    <a href="dashboard.php">Dashboard</a>
    <a href="organisations.php">Organisations</a>
    <a href="contacts.php">Contacts</a>
    <a href="leads.php">Leads</a>
    <a href="deals.php">Deals</a>
    <a href="activities.php">Activities</a>
    <?php if ($is_admin): ?>
        <a href="admin_users.php">User Management</a> <!-- Placeholder for admin user management -->
    <?php endif; ?>
    <a href="logout.php" class="logout">Logout</a>
</div>

<div class="container">
    <p class="welcome">Welcome, <?php echo $username; ?>!</p>
    <div class="role-info">
        Your Role: <?php echo ucfirst($user_role); ?>
        <?php if ($is_admin): ?>
            <span class="admin-notice"> (Administrator)</span>
        <?php endif; ?>
    </div>

    <p>This is your CRM dashboard. From here you can manage your clients, leads, and activities.</p>

    <h3>Quick Actions (Placeholders)</h3>
    <nav>
        <ul>
            <li><a href="organisation_edit.php">Add New Organisation</a></li>
            <li><a href="contact_edit.php">Add New Contact</a></li>
            <li><a href="lead_edit.php">Add New Lead</a></li>
            <li><a href="deal_edit.php">Add New Deal</a></li>
            <li><a href="activity_edit.php">Log New Activity</a></li>
        </ul>
    </nav>

    <p><em>Further content and layout will be detailed in the Stitch prompt for the frontend.</em></p>
</div>

</body>
</html>
