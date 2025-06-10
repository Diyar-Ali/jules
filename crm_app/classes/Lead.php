<?php
// crm_app/classes/Lead.php
require_once __DIR__ . '/LeadStatusHistory.php';
require_once __DIR__ . '/Deal.php';

class Lead {
    private $pdo;
    private $table_name = "leads";
    private $lead_status_history_handler;

    // Lead statuses and temperatures - useful for validation and forms
    public static $statuses = ['New', 'Contacted', 'Qualified', 'Unqualified', 'Converted'];
    public static $temperatures = ['Cold', 'Warm', 'Hot'];

    public function __construct($db) {
        $this->pdo = $db;
        $this->lead_status_history_handler = new LeadStatusHistory($db);
    }

    // Create a new lead
    public function create($name, $source, $status, $temperature, $description, $value, $expected_close_date, $contact_id, $organisation_id, $assigned_user_id, $created_by_user_id) {
        if (empty($name) || empty($created_by_user_id)) {
            return ['success' => false, 'message' => 'Lead name and creator ID are required.'];
        }
        if (!in_array($status, self::$statuses) && !empty($status)) { // status can be empty if using default
             $status = 'New'; // Default if invalid provided
        }
        if (!in_array($temperature, self::$temperatures) && !empty($temperature)) {
            $temperature = 'Cold'; // Default if invalid provided
        }


        $query = "INSERT INTO {$this->table_name}
                    (name, source, status, temperature, description, value, expected_close_date, contact_id, organisation_id, assigned_user_id, created_by_user_id, is_active, version, created_at, updated_at)
                  VALUES
                    (:name, :source, :status, :temperature, :description, :value, :expected_close_date, :contact_id, :organisation_id, :assigned_user_id, :created_by_user_id, TRUE, 1, NOW(), NOW())";

        $stmt = $this->pdo->prepare($query);

        $name = htmlspecialchars(strip_tags($name));
        $source = htmlspecialchars(strip_tags($source)) ?: null;
        $description_clean = htmlspecialchars(strip_tags($description)) ?: null; // Renamed to avoid conflict
        $value_clean = filter_var($value, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $expected_close_date_clean = !empty($expected_close_date) ? date('Y-m-d', strtotime($expected_close_date)) : null;
        $contact_id_clean = filter_var($contact_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $organisation_id_clean = filter_var($organisation_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $assigned_user_id_clean = filter_var($assigned_user_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $created_by_user_id_clean = filter_var($created_by_user_id, FILTER_VALIDATE_INT);

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':source', $source);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':temperature', $temperature);
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':value', $value_clean);
        $stmt->bindParam(':expected_close_date', $expected_close_date_clean);
        $stmt->bindParam(':contact_id', $contact_id_clean, ($contact_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':organisation_id', $organisation_id_clean, ($organisation_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':assigned_user_id', $assigned_user_id_clean, ($assigned_user_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':created_by_user_id', $created_by_user_id_clean, PDO::PARAM_INT);

        try {
            $this->pdo->beginTransaction();
            if ($stmt->execute()) {
                $lead_id = $this->pdo->lastInsertId();
                // Log initial status/temperature
                $history_logged = $this->lead_status_history_handler->add_history_entry($lead_id, null, $status, null, $temperature, $created_by_user_id_clean);
                if ($history_logged) {
                    $this->pdo->commit();
                    return ['success' => true, 'lead_id' => $lead_id, 'message' => 'Lead created successfully.'];
                } else {
                    $this->pdo->rollBack();
                    // log_message('ERROR', "Lead creation succeeded but history logging failed for lead ID {$lead_id}.", $created_by_user_id_clean);
                    return ['success' => false, 'message' => 'Lead created but failed to log initial status history. Transaction rolled back.'];
                }
            } else {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Failed to create lead.'];
            }
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            // log_message('ERROR', "Lead creation failed: " . $e->getMessage(), $created_by_user_id_clean);
            return ['success' => false, 'message' => 'Database error during lead creation: ' . $e->getMessage()];
        }
    }

    // Read all leads
    public function readAll($is_admin = false, $is_active_filter = true, $filters = []) {
        $sql_params = [];
        $query = "SELECT l.*,
                         u_creator.username as created_by_username,
                         u_assignee.username as assigned_user_username,
                         c.first_name as contact_first_name, c.last_name as contact_last_name,
                         org.name as organisation_name
                  FROM {$this->table_name} l
                  JOIN users u_creator ON l.created_by_user_id = u_creator.id
                  LEFT JOIN users u_assignee ON l.assigned_user_id = u_assignee.id
                  LEFT JOIN contacts c ON l.contact_id = c.id
                  LEFT JOIN organisations org ON l.organisation_id = org.id";

        $where_clauses = [];
        if (!$is_admin && $is_active_filter) {
            $where_clauses[] = "l.is_active = TRUE";
        } elseif ($is_admin && !$is_active_filter) {
            // No active filter for admin if they want all
        } elseif ($is_active_filter) {
            $where_clauses[] = "l.is_active = TRUE";
        }

        // Example filters (can be expanded)
        if (!empty($filters['contact_id'])) {
            $where_clauses[] = "l.contact_id = :filter_contact_id";
            $sql_params[':filter_contact_id'] = $filters['contact_id'];
        }
        if (!empty($filters['organisation_id'])) {
            $where_clauses[] = "l.organisation_id = :filter_org_id";
            $sql_params[':filter_org_id'] = $filters['organisation_id'];
        }
         if (!empty($filters['assigned_user_id'])) {
            $where_clauses[] = "l.assigned_user_id = :filter_assigned_id";
            $sql_params[':filter_assigned_id'] = $filters['assigned_user_id'];
        }


        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(" AND ", $where_clauses);
        }

        $query .= " ORDER BY l.updated_at DESC, l.name ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($sql_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Read a single lead by ID
    public function readOne($id, $is_admin = false) {
        $query = "SELECT l.*,
                         u_creator.username as created_by_username,
                         u_assignee.username as assigned_user_username,
                         c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                         org.name as organisation_name
                  FROM {$this->table_name} l
                  JOIN users u_creator ON l.created_by_user_id = u_creator.id
                  LEFT JOIN users u_assignee ON l.assigned_user_id = u_assignee.id
                  LEFT JOIN contacts c ON l.contact_id = c.id
                  LEFT JOIN organisations org ON l.organisation_id = org.id
                  WHERE l.id = :id";

        if (!$is_admin) {
            $query .= " AND l.is_active = TRUE";
        }
        $query .= " LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lead) {
            return ['success' => true, 'data' => $lead];
        } else {
             if (!$is_admin) {
                $stmt_check_inactive = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE id = :id AND is_active = FALSE LIMIT 1");
                $stmt_check_inactive->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_inactive->execute();
                if ($stmt_check_inactive->fetch()) {
                    return ['success' => false, 'message' => 'Lead found but is inactive. Access restricted.'];
                }
            }
            return ['success' => false, 'message' => 'Lead not found or access denied.'];
        }
    }

    // Update lead details
    public function update($id, $name, $source, $status, $temperature, $description, $value, $expected_close_date, $contact_id, $organisation_id, $assigned_user_id, $current_version, $user_id_making_change) {
        $stmt_fetch_old = $this->pdo->prepare("SELECT version, status as old_status, temperature as old_temperature FROM {$this->table_name} WHERE id = :id");
        $stmt_fetch_old->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_fetch_old->execute();
        $db_lead = $stmt_fetch_old->fetch(PDO::FETCH_ASSOC);

        if (!$db_lead) {
            return ['success' => false, 'message' => 'Lead not found.'];
        }
        if ((int)$db_lead['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The lead data has been modified. Please refresh.'];
        }
        if (!in_array($status, self::$statuses)) {
             return ['success' => false, 'message' => 'Invalid lead status provided.'];
        }
        if (!in_array($temperature, self::$temperatures) && !empty($temperature)) { // temperature can be null if not changing
             return ['success' => false, 'message' => 'Invalid lead temperature provided.'];
        }


        $new_version = (int)$current_version + 1;

        $query = "UPDATE {$this->table_name} SET
                    name = :name, source = :source, status = :status, temperature = :temperature,
                    description = :description, value = :value, expected_close_date = :expected_close_date,
                    contact_id = :contact_id, organisation_id = :organisation_id, assigned_user_id = :assigned_user_id,
                    version = :version, updated_at = NOW()
                  WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);

        $name = htmlspecialchars(strip_tags($name));
        $source = htmlspecialchars(strip_tags($source)) ?: null;
        $description_clean = htmlspecialchars(strip_tags($description)) ?: null;
        $value_clean = filter_var($value, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $expected_close_date_clean = !empty($expected_close_date) ? date('Y-m-d', strtotime($expected_close_date)) : null;
        $contact_id_clean = filter_var($contact_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $organisation_id_clean = filter_var($organisation_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $assigned_user_id_clean = filter_var($assigned_user_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':source', $source);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':temperature', $temperature);
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':value', $value_clean);
        $stmt->bindParam(':expected_close_date', $expected_close_date_clean);
        $stmt->bindParam(':contact_id', $contact_id_clean, ($contact_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':organisation_id', $organisation_id_clean, ($organisation_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':assigned_user_id', $assigned_user_id_clean, ($assigned_user_id_clean === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $this->pdo->beginTransaction();
            $stmt->execute();
            $affected_rows = $stmt->rowCount();

            if ($affected_rows > 0) {
                // Log status/temperature change if any
                $history_logged = $this->lead_status_history_handler->add_history_entry(
                    $id,
                    $db_lead['old_status'], $status,
                    $db_lead['old_temperature'], $temperature,
                    $user_id_making_change
                );

                if ($history_logged) {
                    $this->pdo->commit();
                    return ['success' => true, 'message' => 'Lead updated successfully.'];
                } else {
                    $this->pdo->rollBack();
                    // log_message('ERROR', "Lead update succeeded but history logging failed for lead ID {$id}.", $user_id_making_change);
                    return ['success' => false, 'message' => 'Lead updated but failed to log status history. Transaction rolled back.'];
                }
            } else {
                // Check if it was a version conflict not caught, or no actual data change
                $stmt_check_again = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $stmt_check_again->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_again->execute();
                $updated_lead = $stmt_check_again->fetch(PDO::FETCH_ASSOC);

                if ($updated_lead && (int)$updated_lead['version'] === $new_version) { // Data was identical, version bumped.
                     // Still log history if status/temp changed, even if other fields didn't
                    if ($db_lead['old_status'] !== $status || $db_lead['old_temperature'] !== $temperature) {
                        $history_logged = $this->lead_status_history_handler->add_history_entry($id, $db_lead['old_status'], $status, $db_lead['old_temperature'], $temperature, $user_id_making_change);
                        if ($history_logged) {
                            $this->pdo->commit();
                            return ['success' => true, 'message' => 'Lead data was already up to date or updated successfully. Status history logged.'];
                        } else {
                             $this->pdo->rollBack();
                             return ['success' => false, 'message' => 'Lead data unchanged, but failed to log status history. Transaction rolled back.'];
                        }
                    } else {
                        $this->pdo->commit(); // Commit version bump even if no other changes and no history needed
                        return ['success' => true, 'message' => 'Lead data was already up to date.'];
                    }
                }
                $this->pdo->rollBack(); // Rollback if version not bumped (e.g. true version conflict)
                return ['success' => false, 'message' => 'Failed to update lead or data conflict. Ensure data was changed or refresh.'];
            }
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            // log_message('ERROR', "Lead update failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during lead update: ' . $e->getMessage()];
        }
    }

    // Soft delete (activate/deactivate) a lead
    public function setActiveStatus($id, $is_active, $current_version, $user_id_making_change, $is_admin_making_change) {
        // Per prompt, standard users can edit all active CRM data.
        // Soft delete is an edit. Admins have "full control, including soft/hard deletes".
        // This implies standard users can soft-delete (deactivate) if they can edit.
        // However, typically soft-delete might be admin-only. Let's assume admin-only for status changes for safety.
        if (!$is_admin_making_change) {
            return ['success' => false, 'message' => 'Permission denied. Only administrators can change lead active status.'];
        }

        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_lead = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_lead) return ['success' => false, 'message' => 'Lead not found.'];
        if ((int)$db_lead['version'] !== (int)$current_version) return ['success' => false, 'message' => 'Data conflict. Please refresh.'];

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
                return ['success' => true, 'message' => "Lead {$action} successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update lead status or data conflict.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Hard delete (Admin only)
    // LeadStatusHistory has ON DELETE CASCADE for lead_id.
    // converted_to_deal_id in leads has ON DELETE SET NULL if a deal points back.
    public function hardDelete($id, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
             return ['success' => false, 'message' => 'Permission denied. Only administrators can hard delete leads.'];
        }
        // Check if lead is converted to a deal. Generally, converted leads shouldn't be hard-deleted.
        $check_converted = $this->readOne($id, true); // Use admin read to see it even if inactive
        if ($check_converted['success'] && !empty($check_converted['data']['converted_to_deal_id'])) {
            return ['success' => false, 'message' => 'Cannot hard delete. This lead has been converted to Deal ID: ' . $check_converted['data']['converted_to_deal_id'] . '. Consider deactivating instead.'];
        }


        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                return ['success' => ($stmt->rowCount() > 0), 'message' => ($stmt->rowCount() > 0 ? 'Lead hard deleted successfully.' : 'Lead not found or already deleted.')];
            } else {
                return ['success' => false, 'message' => 'Failed to hard delete lead.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Helper to get lists for dropdowns
    public function getRelatedDataForForms() {
        $data = [];
        $stmt_users = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC");
        $data['users'] = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

        $stmt_contacts = $this->pdo->query("SELECT id, first_name, last_name, email FROM contacts WHERE is_active = TRUE ORDER BY last_name ASC, first_name ASC");
        $data['contacts'] = $stmt_contacts->fetchAll(PDO::FETCH_ASSOC);

        $stmt_orgs = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
        $data['organisations'] = $stmt_orgs->fetchAll(PDO::FETCH_ASSOC);

        $data['statuses'] = self::$statuses;
        $data['temperatures'] = self::$temperatures;
        return $data;
    }

    // crm_app/classes/Lead.php ... (inside the Lead class)

    public function convertToDeal($lead_id, $user_id_converting, $deal_stage = 'Qualification', $deal_name = null) {
        $this->pdo->beginTransaction();

        try {
            // 1. Fetch the lead data, ensuring it's not already converted and is active
            $stmt_lead = $this->pdo->prepare("SELECT * FROM {$this->table_name} WHERE id = :lead_id AND is_active = TRUE FOR UPDATE"); // Lock the row
            $stmt_lead->bindParam(':lead_id', $lead_id, PDO::PARAM_INT);
            $stmt_lead->execute();
            $lead = $stmt_lead->fetch(PDO::FETCH_ASSOC);

            if (!$lead) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Lead not found or is inactive.'];
            }

            if (!empty($lead['converted_to_deal_id'])) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'This lead has already been converted to Deal ID: ' . $lead['converted_to_deal_id'] . '.'];
            }

            // 2. A deal requires an organisation.
            if (empty($lead['organisation_id'])) {
                // Try to get organisation from contact if lead has a contact
                if (!empty($lead['contact_id'])) {
                    $stmt_contact_org = $this->pdo->prepare("SELECT organisation_id FROM contacts WHERE id = :contact_id AND is_active = TRUE");
                    $stmt_contact_org->bindParam(':contact_id', $lead['contact_id'], PDO::PARAM_INT);
                    $stmt_contact_org->execute();
                    $contact_org_id = $stmt_contact_org->fetchColumn();
                    if ($contact_org_id) {
                        $lead['organisation_id'] = $contact_org_id;
                    } else {
                        $this->pdo->rollBack();
                        return ['success' => false, 'message' => 'Lead conversion failed: Organisation is required for a Deal, and the linked Contact (if any) does not have an Organisation. Please update the Lead or Contact first.'];
                    }
                } else {
                     $this->pdo->rollBack();
                     return ['success' => false, 'message' => 'Lead conversion failed: Organisation is required for a Deal. Please associate an Organisation with this Lead first.'];
                }
            }

            // 3. Create a new Deal record
            $deal_handler = new Deal($this->pdo); // Deal class needs to be included
            $final_deal_name = !empty($deal_name) ? $deal_name : $lead['name'] . " - Deal"; // Default Deal name

            $deal_create_result = $deal_handler->create(
                $final_deal_name,
                $deal_stage, // Default or specified stage
                $lead['value'] ?? 0.00, // Amount from lead's value, default to 0
                $lead['expected_close_date'] ?? null, // Close date
                null, // Probability - typically set manually on the deal later
                $lead['description'] ?? null, // Description
                $lead['organisation_id'], // Organisation ID (now ensured)
                $lead['contact_id'] ?? null, // Contact ID
                $lead['assigned_user_id'] ?? $user_id_converting, // Assigned user (lead's assignee or converter)
                $user_id_converting // Created by user
            );

            if (!$deal_create_result['success']) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Failed to create Deal during conversion: ' . ($deal_create_result['message'] ?? 'Unknown error')];
            }
            $new_deal_id = $deal_create_result['deal_id'];

            // 4. Update the Lead: status to 'Converted', link to new_deal_id, increment version
            $new_lead_version = (int)$lead['version'] + 1;
            $old_status = $lead['status'];
            $old_temperature = $lead['temperature'];
            $new_status = 'Converted';
            // Temperature might become irrelevant or be cleared for converted leads, or kept. Let's keep it.

            $stmt_update_lead = $this->pdo->prepare(
                "UPDATE {$this->table_name}
                 SET status = :new_status,
                     converted_to_deal_id = :deal_id,
                     version = :new_version,
                     updated_at = NOW()
                 WHERE id = :lead_id AND version = :current_version"
            );
            $stmt_update_lead->bindParam(':new_status', $new_status);
            $stmt_update_lead->bindParam(':deal_id', $new_deal_id, PDO::PARAM_INT);
            $stmt_update_lead->bindParam(':new_version', $new_lead_version, PDO::PARAM_INT);
            $stmt_update_lead->bindParam(':lead_id', $lead_id, PDO::PARAM_INT);
            $stmt_update_lead->bindParam(':current_version', $lead['version'], PDO::PARAM_INT);

            if (!$stmt_update_lead->execute() || $stmt_update_lead->rowCount() == 0) {
                $this->pdo->rollBack();
                // log_message('ERROR', "Lead conversion: Failed to update lead ID {$lead_id} status after deal creation.", $user_id_converting);
                return ['success' => false, 'message' => 'Failed to update lead status after deal creation. Version mismatch or other error.'];
            }

            // 5. Log the status change for the lead
            $history_logged = $this->lead_status_history_handler->add_history_entry(
                $lead_id,
                $old_status, $new_status,
                $old_temperature, $lead['temperature'], // Using current lead temp as new_temp for history
                $user_id_converting
            );

            if (!$history_logged) {
                $this->pdo->rollBack();
                // log_message('ERROR', "Lead conversion: Failed to log status history for lead ID {$lead_id} after conversion.", $user_id_converting);
                return ['success' => false, 'message' => 'Lead converted and deal created, but failed to log lead status history. Transaction rolled back.'];
            }

            $this->pdo->commit();
            return ['success' => true, 'deal_id' => $new_deal_id, 'message' => 'Lead successfully converted to Deal ID: ' . $new_deal_id];

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            // log_message('ERROR', "Lead conversion failed for lead ID {$lead_id}: " . $e->getMessage(), $user_id_converting);
            return ['success' => false, 'message' => 'Database error during lead conversion: ' . $e->getMessage()];
        }
    }
}
?>
