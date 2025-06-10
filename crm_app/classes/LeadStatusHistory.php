<?php
// crm_app/classes/LeadStatusHistory.php

class LeadStatusHistory {
    private $pdo;
    private $table_name = "lead_status_history";

    public function __construct($db) {
        $this->pdo = $db;
    }

    public function add_history_entry($lead_id, $old_status, $new_status, $old_temperature, $new_temperature, $changed_by_user_id) {
        // Only log if there's an actual change in status or temperature
        if ($old_status === $new_status && $old_temperature === $new_temperature) {
            return true; // No change, no log needed, but operation is "successful"
        }

        $query = "INSERT INTO {$this->table_name}
                    (lead_id, old_status, new_status, old_temperature, new_temperature, changed_by_user_id, change_timestamp)
                  VALUES
                    (:lead_id, :old_status, :new_status, :old_temperature, :new_temperature, :changed_by_user_id, NOW())";

        $stmt = $this->pdo->prepare($query);

        $stmt->bindParam(':lead_id', $lead_id, PDO::PARAM_INT);
        $stmt->bindParam(':old_status', $old_status, ($old_status === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindParam(':new_status', $new_status, PDO::PARAM_STR);
        $stmt->bindParam(':old_temperature', $old_temperature, ($old_temperature === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindParam(':new_temperature', $new_temperature, ($new_temperature === null ? PDO::PARAM_NULL : PDO::PARAM_STR)); // New temp can be null if status changes but temp doesn't
        $stmt->bindParam(':changed_by_user_id', $changed_by_user_id, PDO::PARAM_INT);

        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            // In a real app, log this error more robustly
            // error_log("Failed to add lead status history: " . $e->getMessage());
            // For now, we'll let the caller (Lead class) handle the overall transaction outcome
            return false;
        }
    }
}
?>
