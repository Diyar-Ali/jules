<?php
// public/index.php (Dashboard)

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/auth_check.php'; // Ensures user is logged in
require_once __DIR__ . '/../config/db.php';         // For $pdo
require_once __DIR__ . '/../classes/Dashboard.php';
// Activity class is used by Dashboard class, ensure it's loaded if not auto-loaded by Dashboard.php
// Dashboard.php already requires Activity.php, so no need to require it again here.
require_once __DIR__ . '/../includes/log_helper.php';

$page_title = "Dashboard";
$app_url_base = rtrim(parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH) ?: '', '/');

$dashboard_data_fetcher = new Dashboard($pdo);
$entity_counts = $dashboard_data_fetcher->getActiveEntityCounts();
$recent_activities = $dashboard_data_fetcher->getRecentGlobalActivities(10); // Get 10 recent activities

require_once __DIR__ . '/../templates/header.php';
?>

<div class="main-container">
    <h1 class="text-3xl font-bold text-gray-800 mb-8"><?= htmlspecialchars($page_title) ?></h1>

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="metric-card bg-blue-500">
            <h2 class="metric-title">Active Leads</h2>
            <p class="metric-value"><?= htmlspecialchars((string)$entity_counts['leads']) ?></p>
            <a href="<?= htmlspecialchars($app_url_base) ?>/leads_list.php" class="metric-link">View Leads &rarr;</a>
        </div>
        <div class="metric-card bg-green-500">
            <h2 class="metric-title">Active Deals</h2>
            <p class="metric-value"><?= htmlspecialchars((string)$entity_counts['deals']) ?></p>
             <a href="<?= htmlspecialchars($app_url_base) ?>/deals_list.php" class="metric-link">View Deals &rarr;</a>
        </div>
        <div class="metric-card bg-purple-500">
            <h2 class="metric-title">Active Contacts</h2>
            <p class="metric-value"><?= htmlspecialchars((string)$entity_counts['contacts']) ?></p>
            <a href="<?= htmlspecialchars($app_url_base) ?>/contacts_list.php" class="metric-link">View Contacts &rarr;</a>
        </div>
        <div class="metric-card bg-indigo-500">
            <h2 class="metric-title">Active Organisations</h2>
            <p class="metric-value"><?= htmlspecialchars((string)$entity_counts['organisations']) ?></p>
            <a href="<?= htmlspecialchars($app_url_base) ?>/organisations_list.php" class="metric-link">View Organisations &rarr;</a>
        </div>
    </div>

    <!-- Recent Activities Widget -->
    <div class="bg-white p-6 rounded-lg shadow-lg">
        <h2 class="text-xl font-semibold text-gray-700 mb-4">Recent Global Activities</h2>
        <?php if (empty($recent_activities)): ?>
            <p class="text-gray-500">No recent activities found.</p>
        <?php else: ?>
            <ul class="space-y-4">
                <?php foreach ($recent_activities as $activity_item): ?>
                    <li class="flex items-start space-x-3 p-3 border-b border-gray-100 hover:bg-gray-50 rounded-md transition-colors">
                        <div class="activity-icon-container bg-primary">
                            <?php
                            $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>'; // Default
                            if ($activity_item['type'] === 'Call') $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>';
                            elseif ($activity_item['type'] === 'Meeting') $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-3.471-5.072A5.971 5.971 0 0012 13.648a5.971 5.971 0 00-3.529 1.001A5.971 5.971 0 006 18.72m12 0A5.971 5.971 0 0018 13.648V13.5A5.971 5.971 0 0012 8.25c-1.036 0-2.021.286-2.871.775A5.971 5.971 0 006 13.5v.148A5.971 5.971 0 006 18.72a5.971 5.971 0 003.471 2.536A5.971 5.971 0 0012 21.75c1.036 0 2.021-.286 2.871-.775A5.971 5.971 0 0018 18.72z" /></svg>';
                            elseif ($activity_item['type'] === 'Email') $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>';
                            elseif ($activity_item['type'] === 'Task') $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                            echo $icon_svg;
                            ?>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-800">
                                <a href="<?= htmlspecialchars($app_url_base) ?>/activity_view.php?id=<?= (int)$activity_item['id'] ?>" class="hover:underline">
                                    <?= htmlspecialchars($activity_item['subject']) ?>
                                </a>
                                <span class="text-xs text-gray-500 font-normal">(<?= htmlspecialchars($activity_item['type']) ?>)</span>
                            </p>
                            <p class="text-sm text-gray-600">
                                Related to:
                                <?php
                                    $related_link_dash = '#';
                                    if ($activity_item['related_to_type'] && $activity_item['related_to_id']) {
                                        if ($activity_item['related_to_type'] === 'Lead') $related_link_dash = "{$app_url_base}/lead_view.php?id={$activity_item['related_to_id']}";
                                        elseif ($activity_item['related_to_type'] === 'Deal') $related_link_dash = "{$app_url_base}/deal_view.php?id={$activity_item['related_to_id']}";
                                        elseif ($activity_item['related_to_type'] === 'Organisation') $related_link_dash = "{$app_url_base}/organisation_view.php?id={$activity_item['related_to_id']}";
                                        elseif ($activity_item['related_to_type'] === 'Contact') $related_link_dash = "{$app_url_base}/contact_view.php?id={$activity_item['related_to_id']}";
                                        elseif ($activity_item['related_to_type'] === 'User' && (isset($_SESSION['is_admin']) && $_SESSION['is_admin'])) $related_link_dash = "{$app_url_base}/user_edit.php?id={$activity_item['related_to_id']}";
                                    }
                                ?>
                                <a href="<?= htmlspecialchars($related_link_dash) ?>" class="text-primary hover:underline">
                                    <?= htmlspecialchars((string)($activity_item['related_entity_name'] ?: $activity_item['related_to_type'])) ?>
                                </a>
                            </p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars($activity_item['time_elapsed']) ?> by <?= htmlspecialchars($activity_item['created_by_username']) ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<style>
    .metric-card { padding: 1.5rem; border-radius: 0.5rem; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); transition: transform 0.2s ease-in-out; }
    .metric-card:hover { transform: translateY(-5px); }
    .metric-title { font-size: 0.875rem; /* text-sm */ font-weight: 500; opacity: 0.9; }
    .metric-value { font-size: 2.25rem; /* text-4xl */ font-weight: 700; margin-top: 0.5rem; margin-bottom: 0.75rem; }
    .metric-link { font-size: 0.875rem; font-weight: 500; text-decoration: none; opacity: 0.9; transition: opacity 0.2s; }
    .metric-link:hover { opacity: 1; text-decoration: underline; }
    .activity-icon-container { width: 2.5rem; height: 2.5rem; /* w-10 h-10 */ border-radius: 9999px; /* rounded-full */ display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; }
</style>
<?php
require_once __DIR__ . '/../templates/footer.php';
?>
