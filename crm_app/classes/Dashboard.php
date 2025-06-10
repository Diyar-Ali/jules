<?php
// crm_app/classes/Dashboard.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';
// Activity class needed for fetching recent activities and their related entity names
require_once __DIR__ . '/Activity.php';

class Dashboard {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get counts of active entities for dashboard summary widgets.
     * @return array Associative array with counts for 'leads', 'deals', 'contacts', 'organisations'.
     */
    public function getActiveEntityCounts() {
        $counts = [
            'leads' => 0,
            'deals' => 0,
            'contacts' => 0,
            'organisations' => 0,
        ];

        try {
            // Active Leads (not 'Converted' and is_active = TRUE)
            $stmt_leads = $this->pdo->query("SELECT COUNT(*) FROM leads WHERE is_active = TRUE AND status != 'Converted'");
            $counts['leads'] = (int)$stmt_leads->fetchColumn();

            // Active Deals (not 'Won' or 'Lost' and is_active = TRUE)
            $stmt_deals = $this->pdo->query("SELECT COUNT(*) FROM deals WHERE is_active = TRUE AND stage NOT IN ('Won', 'Lost')");
            $counts['deals'] = (int)$stmt_deals->fetchColumn();

            // Active Contacts (is_active = TRUE)
            $stmt_contacts = $this->pdo->query("SELECT COUNT(*) FROM contacts WHERE is_active = TRUE");
            $counts['contacts'] = (int)$stmt_contacts->fetchColumn();

            // Active Organisations (is_active = TRUE)
            $stmt_orgs = $this->pdo->query("SELECT COUNT(*) FROM organisations WHERE is_active = TRUE");
            $counts['organisations'] = (int)$stmt_orgs->fetchColumn();

        } catch (PDOException $e) {
            log_message('error', 'Error fetching active entity counts for dashboard: ' . $e->getMessage());
            // Return current counts (possibly all zeros) or re-throw if critical
        }
        return $counts;
    }

    /**
     * Get a list of recent global activities for the dashboard feed.
     * Fetches a limited number of the most recent activities.
     * @param int $limit Number of recent activities to fetch (e.g., 10).
     * @return array Array of associative arrays, each containing key details for displaying an activity feed item.
     */
    public function getRecentGlobalActivities($limit = 10) {
        $recent_activities_data = [];

        $sql = "SELECT a.id, a.subject, a.type, a.created_at, a.related_to_type, a.related_to_id,
                       u.username as created_by_username, u.id as created_by_user_id_val
                FROM activities a
                JOIN users u ON a.created_by_user_id = u.id
                WHERE a.is_active = TRUE
                ORDER BY a.created_at DESC
                LIMIT :limit";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $activities_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $activity_helper = new Activity($this->pdo); // For getRelatedEntityName

            foreach ($activities_raw as $raw_activity) {
                $related_entity_name = $activity_helper->getRelatedEntityName($raw_activity['related_to_type'], $raw_activity['related_to_id']);
                $time_elapsed_string = $this->timeElapsedString($raw_activity['created_at']);

                $recent_activities_data[] = [
                    'id' => (int)$raw_activity['id'],
                    'subject' => $raw_activity['subject'],
                    'type' => $raw_activity['type'],
                    'created_at_raw' => $raw_activity['created_at'],
                    'time_elapsed' => $time_elapsed_string,
                    'related_to_type' => $raw_activity['related_to_type'],
                    'related_to_id' => (int)$raw_activity['related_to_id'],
                    'related_entity_name' => $related_entity_name,
                    'created_by_username' => $raw_activity['created_by_username'],
                    'created_by_user_id' => (int)$raw_activity['created_by_user_id_val']
                ];
            }

        } catch (PDOException $e) {
            log_message('error', 'Error fetching recent global activities for dashboard: ' . $e->getMessage());
        }
        return $recent_activities_data;
    }

    /**
     * Helper function to convert a timestamp to a human-readable "time elapsed" string.
     * @param string $datetime MySQL DATETIME string.
     * @param bool $full If true, returns full date if more than a week ago.
     * @return string Time elapsed string.
     */
    private function timeElapsedString($datetime, $full = false) {
        try {
            $now = new DateTime;
            $ago = new DateTime($datetime);
            $diff = $now->diff($ago);

            $diff->w = floor($diff->d / 7);
            $diff->d -= $diff->w * 7;

            $string = array(
                'y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day',
                'h' => 'hour', 'i' => 'minute', 's' => 'second',
            );
            foreach ($string as $k => &$v) {
                if ($diff->$k) {
                    $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
                } else {
                    unset($string[$k]);
                }
            }

            if (!$full) $string = array_slice($string, 0, 1);
            return $string ? implode(', ', $string) . ' ago' : 'just now';

        } catch (Exception $e) {
            log_message('error', "Error in timeElapsedString for datetime '{$datetime}': " . $e->getMessage());
            return $datetime; // Return original datetime on error
        }
    }
}
?>
