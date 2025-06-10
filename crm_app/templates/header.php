<?php
// templates/header.php

// Ensure session is started (session_config.php should ideally be included by the calling page)
if (session_status() == PHP_SESSION_NONE) {
    // Basic fallback if not already included.
    // In a real app, ensure pages include session_config.php first.
    $cookieParams = ['httponly' => true, 'samesite' => 'Lax'];
    if(isset($_SERVER['HTTPS'])) $cookieParams['secure'] = true;
    session_set_cookie_params($cookieParams);
    session_start();
}

// Load .env for APP_URL if not already done by calling script
if (empty($_ENV['APP_URL']) && file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }
}

$app_name = "Connect CRM"; // Defined in prompt, can also be from .env
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
if ($app_url_base === '/public') { // Common case if APP_URL is http://localhost/crm_app/public
    // No change needed, links will be /public/page.php
} elseif (empty($app_url_base)) {
    // If APP_URL is http://localhost, and app is in /crm_app/public, this needs adjustment.
    // For this project, assume APP_URL points to the 'public' directory or its equivalent.
    // So, if APP_URL is 'http://localhost/crm_app/public', $app_url_base will be '/crm_app/public'.
    // If APP_URL is 'http://localhost/public_html' and files are there, $app_url_base is '/public_html'.
    // If APP_URL is 'http://mycrm.com' and public is doc root, $app_url_base is ''.
}


// User session data (auth_check.php should make these available, this is a fallback)
$is_logged_in = isset($_SESSION['user_id']);
$current_username = $_SESSION['username'] ?? 'Guest';
$is_admin_user = $_SESSION['is_admin'] ?? false;

// Page title should be set by the including page, e.g., $page_title = "Dashboard";
global $page_title; // Make $page_title available if set globally by calling script
$title = isset($page_title) ? htmlspecialchars($page_title) . " - " . $app_name : $app_name;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4FD1C5', // Teal
                        secondary: '#E27B2B', // Orange
                        danger: '#E53E3E', // Red
                        lightgray: '#f0f4f8', // Example light gray for backgrounds
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: tailwind.config.theme.extend.colors.lightgray; /* Default background */
        }
        .nav-link {
            @apply px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-primary hover:text-white transition-colors;
        }
        .nav-link-active {
            @apply bg-primary text-white;
        }
        /* Basic responsive container */
        .main-container {
            @apply container mx-auto px-4 sm:px-6 lg:px-8 py-8;
        }
            /* Common Form & Button Styles */
            .input-field, .input-field-select {
                @apply mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary sm:text-sm;
            }
            .input-field-select { @apply bg-white; }
            .form-label { @apply block text-sm font-medium text-gray-700; }

            .btn { @apply py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 transition duration-150 ease-in-out; }
            .btn-primary { @apply btn bg-primary text-white hover:bg-teal-600 focus:ring-primary; }
            .btn-primary-full { @apply btn-primary w-full flex justify-center; } /* For full width submit buttons */
            .btn-secondary { @apply btn bg-secondary text-white hover:bg-orange-700 focus:ring-secondary; }
            .btn-danger { @apply btn bg-danger text-white hover:bg-red-700 focus:ring-danger; }
            .btn-muted { @apply btn border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-primary; }
            .btn-disabled { @apply btn bg-gray-400 text-white cursor-not-allowed opacity-70; }
            .btn-success { @apply btn bg-green-500 text-white hover:bg-green-600 focus:ring-green-500; }


            .alert { @apply p-3 mb-4 border rounded-md text-sm; }
            .alert-danger { @apply bg-red-100 border-red-300 text-red-700; }
            .alert-success { @apply bg-green-100 border-green-300 text-green-700; }
            .alert-info { @apply bg-blue-100 border-blue-300 text-blue-700; }
            .alert-warning { @apply bg-yellow-100 border-yellow-300 text-yellow-700; }

            /* Badges (example, can be extended) */
            .status-badge, .temp-badge, .act-type-badge, .act-status-badge, .stage-badge {
                @apply px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full;
            }
            /* Specific badge colors should be defined where used or in a more extensive global CSS if many variants */

    </style>
</head>
<body class="flex flex-col min-h-screen">
    <header class="bg-white shadow-md sticky top-0 z-50">
        <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="<?= htmlspecialchars($app_url_base) ?>/index.php" class="text-2xl font-bold text-primary">
                        <?= htmlspecialchars($app_name) ?>
                    </a>
                </div>
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-4">
                        <?php if ($is_logged_in): ?>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/index.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/index.php') ? 'nav-link-active' : '') ?>">Dashboard</a>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/organisations_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/organisations_list.php') ? 'nav-link-active' : '') ?>">Organisations</a>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/contacts_list.php') ? 'nav-link-active' : '') ?>">Contacts</a>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/leads_list.php') ? 'nav-link-active' : '') ?>">Leads</a>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/deals_list.php') ? 'nav-link-active' : '') ?>">Deals</a>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/activities_list.php') ? 'nav-link-active' : '') ?>">Activities</a>
                            <?php if ($is_admin_user): ?>
                                <a href="<?= htmlspecialchars($app_url_base) ?>/users_list.php" class="nav-link <?= (str_ends_with($_SERVER['PHP_SELF'], '/users_list.php') || str_ends_with($_SERVER['PHP_SELF'], '/register.php') || str_ends_with($_SERVER['PHP_SELF'], '/user_edit.php') ? 'nav-link-active' : '') ?>">User Management</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hidden md:block">
                    <?php if ($is_logged_in): ?>
                        <div class="ml-4 flex items-center md:ml-6">
                            <span class="text-gray-700 text-sm mr-3">Welcome, <?= htmlspecialchars($current_username) ?>!</span>
                            <a href="<?= htmlspecialchars($app_url_base) ?>/logout.php" class="nav-link bg-secondary text-white hover:bg-orange-700">Logout</a>
                        </div>
                    <?php else: ?>
                        <!-- No login link here, login page is standalone -->
                    <?php endif; ?>
                </div>
                <div class="-mr-2 flex md:hidden">
                    <!-- Mobile menu button -->
                    <button type="button" id="mobile-menu-button" class="bg-white inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-primary hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary" aria-controls="mobile-menu" aria-expanded="false">
                        <span class="sr-only">Open main menu</span>
                        <svg class="block h-6 w-6" id="icon-burger" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg class="hidden h-6 w-6" id="icon-close" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Mobile menu, show/hide based on menu state. -->
        <div class="md:hidden hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
                <?php if ($is_logged_in): ?>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/index.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/index.php') ? 'nav-link-active' : '') ?>">Dashboard</a>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/organisations_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/organisations_list.php') ? 'nav-link-active' : '') ?>">Organisations</a>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/contacts_list.php') ? 'nav-link-active' : '') ?>">Contacts</a>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/leads_list.php') ? 'nav-link-active' : '') ?>">Leads</a>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/deals_list.php') ? 'nav-link-active' : '') ?>">Deals</a>
                    <a href="<?= htmlspecialchars($app_url_base) ?>/activities_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/activities_list.php') ? 'nav-link-active' : '') ?>">Activities</a>
                    <?php if ($is_admin_user): ?>
                        <a href="<?= htmlspecialchars($app_url_base) ?>/users_list.php" class="nav-link block <?= (str_ends_with($_SERVER['PHP_SELF'], '/users_list.php') || str_ends_with($_SERVER['PHP_SELF'], '/register.php') || str_ends_with($_SERVER['PHP_SELF'], '/user_edit.php') ? 'nav-link-active' : '') ?>">User Management</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($is_logged_in): ?>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="flex items-center px-5">
                    <div class="ml-3">
                        <div class="text-base font-medium leading-none text-gray-700"><?= htmlspecialchars($current_username) ?></div>
                    </div>
                </div>
                <div class="mt-3 px-2 space-y-1">
                    <a href="<?= htmlspecialchars($app_url_base) ?>/logout.php" class="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:bg-secondary hover:text-white">Logout</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>
    <main class="flex-grow main-container">
        <?php // Page content will be inserted here by the including PHP file ?>
