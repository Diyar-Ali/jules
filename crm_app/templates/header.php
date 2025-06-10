<?php
// crm_app/templates/header.php
// This file should be included at the top of all public-facing PHP files.

// Ensure session is started (auth_check.php usually does this, but good practice for a header)
// However, auth_check.php might redirect, so direct session_config.php might be better if auth is not always required by header itself.
// For simplicity, we assume pages including this header will manage their own auth checks if needed after including it.
// Or, if all pages using this header are authenticated, then auth_check.php could be here.
// Let's assume session_config.php is already included by the calling page or auth_check.php

$current_page_title = $page_title ?? "Basic CRM"; // $page_title should be set by the including page
$is_logged_in_for_nav = isset($_SESSION['user_id']); // Check if user is logged in for nav display
$is_admin_for_nav = $is_logged_in_for_nav && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
$username_for_nav = $is_logged_in_for_nav && isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Define base URL for assets if needed, or use relative paths.
// For simplicity with PHP includes, relative paths from the public files are often used.
// For CSS/JS linked in HTML, a base path can be useful.
// $app_base_url = '/crm_app/public/'; // Example, adjust if your app is in a subdir

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current_page_title); ?></title>
    <!-- Simple CSS Library (e.g., Bootstrap-like for structure) -->
    <!-- Using a CDN for Bootstrap 5 for demonstration purposes. Replace with local if preferred. -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom minimal styles (can be moved to a separate CSS file) -->
    <style>
        body {
            padding-top: 56px; /* Adjust if navbar height changes */
            background-color: #f8f9fa; /* Light grey background */
        }
        .navbar {
            /* Defaults are fine with Bootstrap */
        }
        .container {
            background-color: #ffffff; /* White background for main content area */
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            margin-top: 20px; /* Space from navbar */
        }
        .table-sm th, .table-sm td {
            padding: 0.4rem; /* Smaller padding for compact tables */
        }
        .btn-xs { /* Extra small buttons */
            padding: 0.1rem 0.4rem;
            font-size: 0.75rem;
            line-height: 1.5;
            border-radius: 0.2rem;
        }
        .badge { /* Ensure good contrast for badges */
            /* Bootstrap usually handles this well */
        }
        .alert { margin-top: 15px; }
        /* For disabled look on converted lead/deal edit buttons */
        .btn.disabled, .btn:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }
        /* Custom color for converted items if needed */
        .bg-purple { background-color: #6f42c1; color: white; } /* Bootstrap purple-like */

    </style>
</head>
<body>

<?php if ($is_logged_in_for_nav): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">CRM</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''); ?>" href="dashboard.php">Dashboard</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'organisation') === 0 ? 'active' : ''); ?>" href="organisations.php">Organisations</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'contact') === 0 ? 'active' : ''); ?>" href="contacts.php">Contacts</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'lead') === 0 ? 'active' : ''); ?>" href="leads.php">Leads</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'deal') === 0 ? 'active' : ''); ?>" href="deals.php">Deals</a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'activit') === 0 ? 'active' : ''); ?>" href="activities.php">Activities</a>
        </li>
        <?php if ($is_admin_for_nav): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?php echo (strpos(basename($_SERVER['PHP_SELF']), 'admin_') === 0 ? 'active' : ''); ?>" href="#" id="navbarDropdownAdmin" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Admin
          </a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdownAdmin">
            <li><a class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_users.php' ? 'active' : ''); ?>" href="admin_users.php">User Management</a></li>
            <!-- Add other admin links here -->
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">
        Logged in as: <?php echo htmlspecialchars($username_for_nav); ?> <?php echo $is_admin_for_nav ? '(Admin)' : ''; ?>
      </span>
      <a href="logout.php" class="btn btn-outline-light">Logout</a>
    </div>
  </div>
</nav>
<?php endif; ?>

<!-- Start of main page content area -->
<main role="main" class="container-fluid mt-3"> <!-- container-fluid for full width, or container for fixed width -->
    <!-- The specific page content will be included after this header -->
