<?php
// crm_app/classes/Deal.php

class Deal {
    private $pdo;
    private $table_name = "deals";

    // Deal stages - useful for validation and forms
    public static $stages = ['Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost'];

    public function __construct($db) {
        $this->pdo = $db;
    }

    // Create a new deal
    public function create($name, $stage, $amount, $close_date, $probability, $description, $organisation_id, $contact_id, $assigned_user_id, $created_by_user_id) {
        if (empty($name) || empty($organisation_id) || empty($created_by_user_id) || !isset($amount)) {
            return ['success' => false, 'message' => 'Deal name, amount, organisation ID, and creator ID are required.'];
        }
        if (!in_array($stage, self::$stages) && !empty($stage)) {
            $stage = 'Prospecting'; // Default if invalid
        }
        if ($probability !== null && ($probability < 0 || $probability > 1)) { // Probability is decimal(5,2) e.g. 0.75 for 75%
            return ['success' => false, 'message' => 'Probability must be between 0 and 1 (e.g., 0.75 for 75%).'];
        }


        $query = "INSERT INTO {$this->table_name}
                    (name, stage, amount, close_date, probability, description, organisation_id, contact_id, assigned_user_id, created_by_user_id, is_active, version, created_at, updated_at)
                  VALUES
                    (:name, :stage, :amount, :close_date, :probability, :description, :organisation_id, :contact_id, :assigned_user_id, :created_by_user_id, TRUE, 1, NOW(), NOW())";

        $stmt = $this->pdo->prepare($query);

        $name = htmlspecialchars(strip_tags($name));
        $description_clean = htmlspecialchars(strip_tags($description)) ?: null;
        $amount_clean = filter_var($amount, FILTER_VALIDATE_FLOAT); // Amount is NOT NULL
        $close_date_clean = !empty($close_date) ? date('Y-m-d', strtotime($close_date)) : null;
        $probability_clean = ($probability !== null) ? filter_var($probability, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) : null;

        $contact_id_clean = filter_var($contact_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $organisation_id_clean = filter_var($organisation_id, FILTER_VALIDATE_INT); // Required
        $assigned_user_id_clean = filter_var($assigned_user_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $created_by_user_id_clean = filter_var($created_by_user_id, FILTER_VALIDATE_INT);


        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':stage', $stage);
        $stmt->bindParam(':amount', $amount_clean);
        $stmt->bindParam(':close_date', $close_date_clean);
        $stmt->bindParam(':probability', $probability_clean);
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':organisation_id', $organisation_id_clean, PDO::PARAM_INT);
        $stmt->bindParam(':contact_id', $contact_id_clean, ($contact_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':assigned_user_id', $assigned_user_id_clean, ($assigned_user_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':created_by_user_id', $created_by_user_id_clean, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'deal_id' => $this->pdo->lastInsertId(), 'message' => 'Deal created successfully.'];
            } else {
                return ['success' => false, 'message' => 'Failed to create deal.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Deal creation failed: " . $e->getMessage(), $created_by_user_id_clean);
            return ['success' => false, 'message' => 'Database error during deal creation: ' . $e->getMessage()];
        }
    }

    // Read all deals
    public function readAll($is_admin = false, $is_active_filter = true, $filters = []) {
        $sql_params = [];
        $query = "SELECT d.*,
                         org.name as organisation_name,
                         c.first_name as contact_first_name, c.last_name as contact_last_name,
                         u_assignee.username as assigned_user_username,
                         u_creator.username as created_by_username
                  FROM {$this->table_name} d
                  JOIN organisations org ON d.organisation_id = org.id
                  LEFT JOIN contacts c ON d.contact_id = c.id
                  LEFT JOIN users u_assignee ON d.assigned_user_id = u_assignee.id
                  JOIN users u_creator ON d.created_by_user_id = u_creator.id";

        $where_clauses = [];
        if (!$is_admin && $is_active_filter) {
            $where_clauses[] = "d.is_active = TRUE";
        } elseif ($is_admin && !$is_active_filter) {
            // No active filter for admin
        } elseif ($is_active_filter) {
            $where_clauses[] = "d.is_active = TRUE";
        }

        if (!empty($filters['organisation_id'])) {
            $where_clauses[] = "d.organisation_id = :filter_org_id";
            $sql_params[':filter_org_id'] = $filters['organisation_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where_clauses[] = "d.contact_id = :filter_contact_id";
            $sql_params[':filter_contact_id'] = $filters['contact_id'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where_clauses[] = "d.assigned_user_id = :filter_assigned_id";
            $sql_params[':filter_assigned_id'] = $filters['assigned_user_id'];
        }
        if (!empty($filters['stage'])) {
            $where_clauses[] = "d.stage = :filter_stage";
            $sql_params[':filter_stage'] = $filters['stage'];
        }


        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(" AND ", $where_clauses);
        }

        $query .= " ORDER BY d.updated_at DESC, d.name ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($sql_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Read a single deal by ID
    public function readOne($id, $is_admin = false) {
        $query = "SELECT d.*,
                         org.name as organisation_name,
                         c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                         u_assignee.username as assigned_user_username,
                         u_creator.username as created_by_username
                  FROM {$this->table_name} d
                  JOIN organisations org ON d.organisation_id = org.id
                  LEFT JOIN contacts c ON d.contact_id = c.id
                  LEFT JOIN users u_assignee ON d.assigned_user_id = u_assignee.id
                  JOIN users u_creator ON d.created_by_user_id = u_creator.id
                  WHERE d.id = :id";

        if (!$is_admin) {
            $query .= " AND d.is_active = TRUE";
        }
        $query .= " LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $deal = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($deal) {
            return ['success' => true, 'data' => $deal];
        } else {
            if (!$is_admin) {
                $stmt_check_inactive = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE id = :id AND is_active = FALSE LIMIT 1");
                $stmt_check_inactive->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_inactive->execute();
                if ($stmt_check_inactive->fetch()) {
                    return ['success' => false, 'message' => 'Deal found but is inactive. Access restricted.'];
                }
            }
            return ['success' => false, 'message' => 'Deal not found or access denied.'];
        }
    }

    // Update deal details
    public function update($id, $name, $stage, $amount, $close_date, $probability, $description, $organisation_id, $contact_id, $assigned_user_id, $current_version, $user_id_making_change) {
        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_deal = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_deal) {
            return ['success' => false, 'message' => 'Deal not found.'];
        }
        if ((int)$db_deal['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The deal data has been modified. Please refresh.'];
        }
        if (!in_array($stage, self::$stages)) {
             return ['success' => false, 'message' => 'Invalid deal stage provided.'];
        }
        if ($probability !== null && ($probability < 0 || $probability > 1)) {
            return ['success' => false, 'message' => 'Probability must be between 0 and 1.'];
        }
        if (empty($organisation_id)) { // Organisation is required for deals
            return ['success' => false, 'message' => 'Organisation ID is required for a deal.'];
        }


        $new_version = (int)$current_version + 1;

        $query = "UPDATE {$this->table_name} SET
                    name = :name, stage = :stage, amount = :amount, close_date = :close_date,
                    probability = :probability, description = :description, organisation_id = :organisation_id,
                    contact_id = :contact_id, assigned_user_id = :assigned_user_id,
                    version = :version, updated_at = NOW()
                  WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);

        $name = htmlspecialchars(strip_tags($name));
        $description_clean = htmlspecialchars(strip_tags($description)) ?: null;
        $amount_clean = filter_var($amount, FILTER_VALIDATE_FLOAT);
        $close_date_clean = !empty($close_date) ? date('Y-m-d', strtotime($close_date)) : null;
        $probability_clean = ($probability !== null) ? filter_var($probability, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) : null;
        $contact_id_clean = filter_var($contact_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $organisation_id_clean = filter_var($organisation_id, FILTER_VALIDATE_INT);
        $assigned_user_id_clean = filter_var($assigned_user_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':stage', $stage);
        $stmt->bindParam(':amount', $amount_clean);
        $stmt->bindParam(':close_date', $close_date_clean);
        $stmt->bindParam(':probability', $probability_clean);
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':organisation_id', $organisation_id_clean, PDO::PARAM_INT);
        $stmt->bindParam(':contact_id', $contact_id_clean, ($contact_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':assigned_user_id', $assigned_user_id_clean, ($assigned_user_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Deal updated successfully.'];
            } else {
                $stmt_check_again = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $stmt_check_again->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_again->execute();
                $updated_deal = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
                if ($updated_deal && (int)$updated_deal['version'] === $new_version) {
                     return ['success' => true, 'message' => 'Deal data was already up to date or updated successfully.'];
                }
                return ['success' => false, 'message' => 'Failed to update deal or data conflict. Ensure data was changed or refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Deal update failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during deal update: ' . $e->getMessage()];
        }
    }

    // Soft delete (activate/deactivate) a deal
    public function setActiveStatus($id, $is_active, $current_version, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            return ['success' => false, 'message' => 'Permission denied. Only administrators can change deal active status.'];
        }

        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_deal = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_deal) return ['success' => false, 'message' => 'Deal not found.'];
        if ((int)$db_deal['version'] !== (int)$current_version) return ['success' => false, 'message' => 'Data conflict. Please refresh.'];

        $new_version = (int)$current_version + 1;
        $is_active_bool = filter_var($is_active, FILTER_VALIDATE_BOOLEAN);

        $query = "UPDATE {$this->table_name} SET is_active = :is_active, version = :version, updated_at = NOW() WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':is_active', $is_active_bool, PDO::PARAM_BOOL);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $action = $is_active_bool ? 'activated' : 'deactivated';
                return ['success' => true, 'message' => "Deal {$action} successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update deal status or data conflict.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Hard delete (Admin only)
    // Leads.converted_to_deal_id is ON DELETE SET NULL.
    // Activities related to deals might need consideration.
    public function hardDelete($id, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
             return ['success' => false, 'message' => 'Permission denied. Only administrators can hard delete deals.'];
        }

        // Before deleting a deal, set converted_to_deal_id to NULL in any leads pointing to it.
        // This is handled by ON DELETE SET NULL in the leads table's FK, but good to be aware.
        // No direct FK from activities, managed by application logic.

        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                 if ($stmt->rowCount() > 0) {
                    // log_message('ALERT', "Deal ID {$id} HARD DELETED by admin ID {$user_id_making_change}.");
                    return ['success' => true, 'message' => 'Deal hard deleted successfully.'];
                } else {
                    return ['success' => false, 'message' => 'Deal not found or already deleted.'];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to hard delete deal.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Deal hard delete failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during deal hard delete: ' . $e->getMessage()];
        }
    }

    // Helper to get lists for dropdowns
    public function getRelatedDataForForms() {
        $data = [];
        $stmt_users = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC");
        $data['users'] = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

        $stmt_contacts = $this->pdo->query("SELECT id, first_name, last_name, email FROM contacts WHERE is_active = TRUE ORDER BY last_name ASC, first_name ASC");
        $data['contacts'] = $stmt_contacts->fetchAll(PDO::FETCH_ASSOC);

        // Deals MUST be linked to an organisation. Only show active ones.
        $stmt_orgs = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
        $data['organisations'] = $stmt_orgs->fetchAll(PDO::FETCH_ASSOC);

        $data['stages'] = self::$stages;
        return $data;
    }
}
?>
