<?php
// crm_app/classes/LeadStatusHistory.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';

class LeadStatusHistory {
    private $pdo;

    public $id;
    public $lead_id;
    public $old_status;
    public $new_status;
    public $old_temperature;
    public $new_temperature;
    public $changed_by_user_id;
    public $change_timestamp; // Maps to 'change_timestamp' column in schema

    public $changed_by_username;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Create a new lead status history record.
     * change_timestamp is set by DB default (CURRENT_TIMESTAMP).
     * @return bool True on success, false on failure.
     */
    public function create() {
        if (empty($this->lead_id) || empty($this->new_status) || empty($this->changed_by_user_id)) {
            log_message('error', 'Lead ID, new status, and changed_by_user_id are required for history.', (array)$this);
            return false;
        }

        $sql = "INSERT INTO lead_status_history (lead_id, old_status, new_status, old_temperature, new_temperature, changed_by_user_id)
                VALUES (:lead_id, :old_status, :new_status, :old_temperature, :new_temperature, :changed_by_user_id)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':lead_id', $this->lead_id, PDO::PARAM_INT);
            $stmt->bindParam(':old_status', $this->old_status);
            $stmt->bindParam(':new_status', $this->new_status);
            $stmt->bindParam(':old_temperature', $this->old_temperature);
            $stmt->bindParam(':new_temperature', $this->new_temperature);
            $stmt->bindParam(':changed_by_user_id', $this->changed_by_user_id, PDO::PARAM_INT);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();

            // Fetch the record to get the DB-generated timestamp
            $read_sql = "SELECT change_timestamp FROM lead_status_history WHERE id = :id";
            $read_stmt = $this->pdo->prepare($read_sql);
            $read_stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $read_stmt->execute();
            $data = $read_stmt->fetch(PDO::FETCH_ASSOC);
            if($data) {
                $this->change_timestamp = $data['change_timestamp'];
            }
            return true;
        } catch (PDOException $e) {
            log_message('error', 'Error creating lead status history: ' . $e->getMessage(), (array)$this);
            return false;
        }
    }

    /**
     * Read a specific history record.
     */
    public function read($history_id) {
        $sql = "SELECT lsh.*, u.username as changed_by_username
                FROM lead_status_history lsh
                LEFT JOIN users u ON lsh.changed_by_user_id = u.id
                WHERE lsh.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $history_id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $this->id = (int)$data['id'];
                $this->lead_id = (int)$data['lead_id'];
                $this->old_status = $data['old_status'];
                $this->new_status = $data['new_status'];
                $this->old_temperature = $data['old_temperature'];
                $this->new_temperature = $data['new_temperature'];
                $this->changed_by_user_id = (int)$data['changed_by_user_id'];
                $this->changed_by_username = $data['changed_by_username'] ?? null;
                $this->change_timestamp = $data['change_timestamp'];
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading lead status history entry: ' . $e->getMessage(), ['history_id' => $history_id]);
            return false;
        }
    }

    /**
     * Get all status history records for a specific lead.
     * @param PDO $pdo
     * @param int $lead_id
     * @return array Array of LeadStatusHistory objects.
     */
    public static function readAllByLeadId(PDO $pdo, $lead_id) {
        $sql = "SELECT lsh.*, u.username as changed_by_username
                FROM lead_status_history lsh
                LEFT JOIN users u ON lsh.changed_by_user_id = u.id
                WHERE lsh.lead_id = :lead_id
                ORDER BY lsh.change_timestamp DESC, lsh.id DESC";
        $history_items = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':lead_id', $lead_id, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $data) {
                $item = new LeadStatusHistory($pdo);
                $item->id = (int)$data['id'];
                $item->lead_id = (int)$data['lead_id'];
                $item->old_status = $data['old_status'];
                $item->new_status = $data['new_status'];
                $item->old_temperature = $data['old_temperature'];
                $item->new_temperature = $data['new_temperature'];
                $item->changed_by_user_id = (int)$data['changed_by_user_id'];
                $item->changed_by_username = $data['changed_by_username'] ?? null;
                $item->change_timestamp = $data['change_timestamp'];
                $history_items[] = $item;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error fetching lead status history for lead ID ' . $lead_id . ': ' . $e->getMessage());
        }
        return $history_items;
    }

    public static function getStatusOptions() {
        return ['New', 'Contacted', 'Qualified', 'Unqualified', 'Converted'];
    }
    public static function getTemperatureOptions() {
         return ['Cold', 'Warm', 'Hot'];
    }
}
?>
