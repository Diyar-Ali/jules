<?php
// crm_app/classes/Lead.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/LeadStatusHistory.php';
// require_once __DIR__ . '/Deal.php'; // Will be needed for convertToDeal - Keep commented for this subtask

class Lead {
    private $pdo;

    public $id;
    public $name;
    public $source;
    public $status;
    public $temperature;
    public $description;
    public $value;
    public $expected_close_date;
    public $contact_id;
    public $organisation_id;
    public $assigned_user_id;
    public $created_by_user_id;
    public $is_active;
    public $converted_to_deal_id;
    public $created_at;
    public $updated_at;
    public $version;

    // For display
    public $contact_name;
    public $organisation_name;
    public $assigned_user_name;
    public $created_by_username;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Schema defaults: is_active = TRUE, version = 1, status = 'New', temperature = 'Cold'
        $this->is_active = true;
        $this->version = 1;
        $this->status = 'New';
        $this->temperature = 'Cold';
    }

    /**
     * Create a new lead record.
     * @param int $creator_user_id ID of the user creating the lead.
     * @return bool True on success.
     * @throws Exception on failure or validation error.
     */
    public function create($creator_user_id) {
        if (empty($this->name)) {
            throw new InvalidArgumentException("Lead name is required.");
        }
        $this->created_by_user_id = $creator_user_id;
        // Ensure is_active is boolean; constructor sets default. If explicitly set, validate.
        if (!is_bool($this->is_active)) {
            $this->is_active = true; // Fallback to default if not a proper boolean
        }
        $this->version = $this->version ?? 1; // Ensure version is set (constructor default is 1)


        // Store initial status/temp for history BEFORE saving
        $initial_status = $this->status;
        $initial_temp = $this->temperature;

        $sql = "INSERT INTO leads (name, source, status, temperature, description, value, expected_close_date, contact_id, organisation_id, assigned_user_id, created_by_user_id, is_active, version, converted_to_deal_id)
                VALUES (:name, :source, :status, :temperature, :description, :value, :expected_close_date, :contact_id, :organisation_id, :assigned_user_id, :created_by_user_id, :is_active, :version, NULL)";

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':source', $this->source);
            $stmt->bindParam(':status', $this->status);
            $stmt->bindParam(':temperature', $this->temperature);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':value', $this->value);
            $stmt->bindParam(':expected_close_date', $this->expected_close_date);
            $stmt->bindParam(':contact_id', $this->contact_id, $this->contact_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':organisation_id', $this->organisation_id, $this->organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':assigned_user_id', $this->assigned_user_id, $this->assigned_user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':created_by_user_id', $this->created_by_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':version', $this->version, PDO::PARAM_INT);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();

            $history = new LeadStatusHistory($this->pdo);
            $history->lead_id = $this->id;
            $history->old_status = null;
            $history->new_status = $initial_status;
            $history->old_temperature = null;
            $history->new_temperature = $initial_temp;
            $history->changed_by_user_id = $creator_user_id;
            if (!$history->create()) {
                 log_message('warning', "Failed to log initial status/temperature for new lead ID {$this->id}");
            }

            $this->pdo->commit();

            $this->read($this->id);
            log_message('info', 'Lead created successfully.', ['lead_id' => $this->id, 'lead_name' => $this->name]);
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            log_message('error', 'Error creating lead: ' . $e->getMessage(), ['lead_name' => $this->name]);
            throw $e;
        }
    }

    /**
     * Read a lead record from the database by ID.
     * @param int $id The ID of the lead to read.
     * @return bool True on success, false if not found.
     */
    public function read($id) {
        $sql = "SELECT l.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_user_name,
                       CONCAT(c.first_name, ' ', c.last_name) as contact_full_name,
                       org.name as organisation_name
                FROM leads l
                LEFT JOIN users u_creator ON l.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON l.assigned_user_id = u_assignee.id
                LEFT JOIN contacts c ON l.contact_id = c.id
                LEFT JOIN organisations org ON l.organisation_id = org.id
                WHERE l.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $this->id = (int)$data['id'];
                $this->name = $data['name'];
                $this->source = $data['source'];
                $this->status = $data['status'];
                $this->temperature = $data['temperature'];
                $this->description = $data['description'];
                $this->value = $data['value'] === null ? null : (float)$data['value'];
                $this->expected_close_date = $data['expected_close_date'];
                $this->contact_id = $data['contact_id'] === null ? null : (int)$data['contact_id'];
                $this->organisation_id = $data['organisation_id'] === null ? null : (int)$data['organisation_id'];
                $this->assigned_user_id = $data['assigned_user_id'] === null ? null : (int)$data['assigned_user_id'];
                $this->created_by_user_id = (int)$data['created_by_user_id'];
                $this->is_active = (bool)$data['is_active'];
                $this->converted_to_deal_id = $data['converted_to_deal_id'] === null ? null : (int)$data['converted_to_deal_id'];
                $this->created_at = $data['created_at'];
                $this->updated_at = $data['updated_at'];
                $this->version = (int)$data['version'];

                $this->created_by_username = $data['created_by_username'] ?? null;
                $this->assigned_user_name = $data['assigned_user_name'] ?? null;
                $this->contact_name = trim((string)($data['contact_full_name'] ?? '')) ?: null;
                $this->organisation_name = $data['organisation_name'] ?? null;
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading lead: ' . $e->getMessage(), ['lead_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing lead record. Uses optimistic locking.
     * Records status/temperature changes in lead_status_history.
     * @param int $updater_user_id ID of the user performing the update.
     * @return bool True on success.
     * @throws Exception on failure or validation error.
     */
    public function update($updater_user_id) {
        if (empty($this->id) || $this->version === null) {
            throw new InvalidArgumentException("Lead ID and version are required for updates. Please read the record first.");
        }
        if ($this->isConverted() && $this->status !== 'Converted') {
             // Allow updates if status is 'Converted' (e.g. by convertToDeal), but prevent changing status *from* Converted by other means.
             // Or more strictly: if converted, only allow certain fields to change, or no changes at all.
             // For now, a simple check: if it's marked converted, don't allow updates that might revert it or change key details unless it's part of conversion itself.
             // The convertToDeal method specifically sets status to 'Converted' and updates.
            if ($this->converted_to_deal_id !== null && $this->status !== 'Converted') { // Trying to change status of an already converted lead
                 throw new Exception("Cannot update a lead that is already converted, unless setting its status to 'Converted'.");
            }
        }

        if (empty($this->name)) {
            throw new InvalidArgumentException("Lead name is required.");
        }
        if(!is_bool($this->is_active)) {
             $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }


        $current_lead_state = new Lead($this->pdo);
        if (!$current_lead_state->read($this->id)) {
            throw new Exception("Lead not found for update (ID: {$this->id}).");
        }
        if ($current_lead_state->version !== $this->version) {
             throw new Exception("Data conflict. The lead record was updated by someone else (version mismatch: object had {$this->version}, DB has {$current_lead_state->version}). Please refresh and try again.");
        }

        $old_status = $current_lead_state->status;
        $old_temperature = $current_lead_state->temperature;
        $status_changed = ($this->status !== $old_status);
        $temperature_changed = ($this->temperature !== $old_temperature);

        $sql = "UPDATE leads SET
                    name = :name, source = :source, status = :status, temperature = :temperature,
                    description = :description, value = :value, expected_close_date = :expected_close_date,
                    contact_id = :contact_id, organisation_id = :organisation_id,
                    assigned_user_id = :assigned_user_id, is_active = :is_active,
                    converted_to_deal_id = :converted_to_deal_id,
                    version = :new_version
                WHERE id = :id AND version = :current_version";

        try {
            $this->pdo->beginTransaction();
            $new_version = $this->version + 1;

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':source', $this->source);
            $stmt->bindParam(':status', $this->status);
            $stmt->bindParam(':temperature', $this->temperature);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':value', $this->value);
            $stmt->bindParam(':expected_close_date', $this->expected_close_date);
            $stmt->bindParam(':contact_id', $this->contact_id, $this->contact_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':organisation_id', $this->organisation_id, $this->organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':assigned_user_id', $this->assigned_user_id, $this->assigned_user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':converted_to_deal_id', $this->converted_to_deal_id, $this->converted_to_deal_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':new_version', $new_version, PDO::PARAM_INT);
            $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':current_version', $this->version, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->version = $new_version;

                if ($status_changed || $temperature_changed) {
                    $history = new LeadStatusHistory($this->pdo);
                    $history->lead_id = $this->id;
                    $history->old_status = $status_changed ? $old_status : null;
                    $history->new_status = $this->status;
                    $history->old_temperature = $temperature_changed ? $old_temperature : null;
                    $history->new_temperature = $this->temperature;
                    $history->changed_by_user_id = $updater_user_id;
                    if (!$history->create()) {
                        log_message('warning', "Failed to log status/temperature change for lead ID {$this->id}");
                    }
                }
                $this->pdo->commit();
                // Fetch minimal data to update object after successful transaction
                $refetchStmt = $this->pdo->prepare("SELECT updated_at FROM leads WHERE id = :id");
                $refetchStmt->bindParam(':id', $this->id, PDO::PARAM_INT);
                $refetchStmt->execute();
                $updatedData = $refetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($updatedData) $this->updated_at = $updatedData['updated_at'];

                log_message('info', 'Lead updated successfully.', ['lead_id' => $this->id, 'new_version' => $this->version]);
                return true;
            } else {
                $this->pdo->rollBack();
                // Re-check version to distinguish no-change from actual conflict (if rowCount was 0)
                $checker = new Lead($this->pdo);
                if ($checker->read($this->id) && $checker->version !== $this->version) {
                    throw new Exception("Update failed. The lead record was modified by someone else during the update process. Please refresh and try again.");
                }
                log_message('warning', 'Lead update resulted in no changed rows (possibly no actual data difference or record not found with specified version).', ['lead_id' => $this->id, 'version_tried' => $this->version]);
                return true;
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            log_message('error', 'Error updating lead: ' . $e->getMessage(), ['lead_id' => $this->id]);
            throw $e;
        }
    }

    /**
     * Check if the lead has been converted to a deal.
     * @return bool True if converted_to_deal_id is set, false otherwise.
     */
    public function isConverted() {
        return !empty($this->converted_to_deal_id);
    }

    /**
     * Convert a Lead into a Deal.
     * @param int $converter_user_id The user ID performing the conversion.
     * @param string $deal_name Name for the new Deal.
     * @param string $initial_deal_stage Initial stage for the new Deal.
     * @return int|false The ID of the newly created Deal on success, false on failure.
     * @throws Exception If lead is inactive, already converted, or other error.
     */
    public function convertToDeal($converter_user_id, $deal_name, $initial_deal_stage) {
        if (!$this->id) throw new Exception("Lead must be saved and have an ID before conversion.");
        if (!$this->is_active) throw new Exception("Cannot convert an inactive lead.");
        if ($this->isConverted()) throw new Exception("This lead has already been converted to Deal ID: {$this->converted_to_deal_id}.");
        if (empty($deal_name) || empty($initial_deal_stage)) {
            throw new InvalidArgumentException("Deal name and initial stage are required for conversion.");
        }
        if (!$this->organisation_id) {
             throw new Exception("Lead must be associated with an Organisation before conversion.");
        }

        // require_once __DIR__ . '/Deal.php'; // This line will be uncommented when Deal.php is created
        if (!class_exists('Deal')) {
            log_message('error', "Deal class not found during lead conversion for Lead ID {$this->id}. Deal.php might be missing or not included.");
            throw new Exception("Deal class not found. Cannot proceed with lead conversion.");
        }


        $deal = new Deal($this->pdo); // Assumes Deal class exists
        $deal->name = $deal_name;
        $deal->stage = $initial_deal_stage;
        $deal->amount = $this->value ?? 0.00;
        $deal->close_date = $this->expected_close_date;
        $deal->description = "Converted from Lead: " . $this->name . "\n\nLead Description:\n" . $this->description;
        $deal->organisation_id = $this->organisation_id;
        $deal->contact_id = $this->contact_id;
        $deal->assigned_user_id = $this->assigned_user_id;

        try {
            $this->pdo->beginTransaction();

            if ($deal->create($converter_user_id)) {
                $this->status = 'Converted'; // Mark lead as converted
                $this->converted_to_deal_id = $deal->id; // Link to the new deal

                // Update the lead record itself to save new status and deal ID
                // The update method handles versioning and history logging
                if ($this->update($converter_user_id)) {
                    $this->pdo->commit();
                    log_message('info', "Lead ID {$this->id} converted to Deal ID {$deal->id} by User ID {$converter_user_id}");
                    return $deal->id;
                } else {
                    throw new Exception("Failed to update lead status after deal creation during conversion.");
                }
            } else {
                throw new Exception("Failed to create deal during lead conversion.");
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            log_message('error', "Lead conversion failed for Lead ID {$this->id}: " . $e->getMessage());
            // Reset temporary changes to lead object if conversion fails mid-way
            if ($this->status === 'Converted' && $this->converted_to_deal_id === $deal->id) {
                $this->status = 'Qualified'; // Or fetch previous status before attempting conversion
                $this->converted_to_deal_id = null;
            }
            throw $e;
        }
        // return false; // Should not be reached
    }


    /**
     * Soft delete (deactivate) or reactivate a lead.
     * @param bool $isActive True to activate, false to deactivate.
     * @param int $updater_user_id User performing the action.
     * @return bool True on success.
     * @throws Exception If lead is converted or update fails.
     */
    public function setActiveStatus($isActive, $updater_user_id) {
        if (empty($this->id) || $this->version === null) {
             throw new InvalidArgumentException("Lead ID and version are required. Please read the record first.");
        }
        if ($this->isConverted()) {
            throw new Exception("Cannot change active status of a converted lead.");
        }
        $this->is_active = (bool)$isActive;
        return $this->update($updater_user_id);
    }

    /**
     * Hard delete a lead. Prevents deletion if converted.
     * @param PDO $pdo
     * @param int $id
     * @return bool True on success.
     * @throws Exception If lead is converted or DB error.
     */
    public static function hardDelete(PDO $pdo, $id) {
        $lead_to_delete = new Lead($pdo);
        if (!$lead_to_delete->read($id)) {
            throw new Exception("Lead not found for deletion (ID: {$id}).");
        }
        if ($lead_to_delete->isConverted()) {
            throw new Exception("Cannot permanently delete a lead (ID: {$id}) that has been converted to a deal (Deal ID: {$lead_to_delete->converted_to_deal_id}).");
        }

        // The `lead_status_history` table has `ON DELETE CASCADE` for `lead_id`, so history will be auto-deleted.

        $sql = "DELETE FROM leads WHERE id = :id";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'Lead hard deleted successfully.', ['lead_id' => $id]);
                return true;
            }
            log_message('warning', 'Lead hard delete failed (lead not found or already deleted).', ['lead_id' => $id]);
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error hard deleting lead: ' . $e->getMessage(), ['lead_id' => $id]);
            throw $e;
        }
    }

    /**
     * Get all lead records from the database.
     * @param PDO $pdo
     * @param array $filters
     * @return array Array of Lead objects.
     */
    public static function readAll(PDO $pdo, $filters = []) {
        $sql = "SELECT l.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_user_name,
                       CONCAT(c.first_name, ' ', c.last_name) as contact_name_full,
                       org.name as organisation_name
                FROM leads l
                LEFT JOIN users u_creator ON l.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON l.assigned_user_id = u_assignee.id
                LEFT JOIN contacts c ON l.contact_id = c.id
                LEFT JOIN organisations org ON l.organisation_id = org.id";

        $where_clauses = [];
        $params = [];

        $is_admin_viewing_all = (isset($filters['view']) && $filters['view'] === 'all' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);
        if (!$is_admin_viewing_all) {
            if (isset($filters['is_active'])) {
                $where_clauses[] = "l.is_active = :is_active";
                $params[':is_active'] = (bool)$filters['is_active'];
            } else { $where_clauses[] = "l.is_active = TRUE"; }
        } elseif (isset($filters['is_active'])) {
             $where_clauses[] = "l.is_active = :is_active";
             $params[':is_active'] = (bool)$filters['is_active'];
        }


        if (!empty($filters['search_term'])) {
            $search_term_like = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(l.name LIKE :search_term OR l.source LIKE :search_term OR l.description LIKE :search_term OR CONCAT(c.first_name, ' ', c.last_name) LIKE :search_term OR org.name LIKE :search_term)";
            $params[':search_term'] = $search_term_like;
        }
        if (!empty($filters['status'])) {
            $where_clauses[] = "l.status = :status";
            $params[':status'] = $filters['status'];
        }
         if (!empty($filters['temperature'])) {
            $where_clauses[] = "l.temperature = :temperature";
            $params[':temperature'] = $filters['temperature'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where_clauses[] = "l.assigned_user_id = :assigned_user_id";
            $params[':assigned_user_id'] = $filters['assigned_user_id'];
        }
        if (!empty($filters['organisation_id'])) {
            $where_clauses[] = "l.organisation_id = :organisation_id";
            $params[':organisation_id'] = $filters['organisation_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where_clauses[] = "l.contact_id = :contact_id";
            $params[':contact_id'] = $filters['contact_id'];
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }
        $sql .= " ORDER BY l.updated_at DESC, l.name ASC";

        $leads = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $data) {
                $lead = new Lead($pdo);
                $lead->id = (int)$data['id'];
                $lead->name = $data['name'];
                $lead->source = $data['source'];
                $lead->status = $data['status'];
                $lead->temperature = $data['temperature'];
                $lead->description = $data['description'];
                $lead->value = $data['value'] === null ? null : (float)$data['value'];
                $lead->expected_close_date = $data['expected_close_date'];
                $lead->contact_id = $data['contact_id'] === null ? null : (int)$data['contact_id'];
                $lead->organisation_id = $data['organisation_id'] === null ? null : (int)$data['organisation_id'];
                $lead->assigned_user_id = $data['assigned_user_id'] === null ? null : (int)$data['assigned_user_id'];
                $lead->created_by_user_id = (int)$data['created_by_user_id'];
                $lead->is_active = (bool)$data['is_active'];
                $lead->converted_to_deal_id = $data['converted_to_deal_id'] === null ? null : (int)$data['converted_to_deal_id'];
                $lead->created_at = $data['created_at'];
                $lead->updated_at = $data['updated_at'];
                $lead->version = (int)$data['version'];

                $lead->created_by_username = $data['created_by_username'] ?? null;
                $lead->assigned_user_name = $data['assigned_user_name'] ?? null;
                $lead->contact_name = trim((string)($data['contact_name_full'] ?? '')) ?: null;
                $lead->organisation_name = $data['organisation_name'] ?? null;
                $leads[] = $lead;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error reading all leads: ' . $e->getMessage(), ['filters' => $filters]);
        }
        return $leads;
    }

    /**
     * Get related data for forms (users, contacts, organisations, statuses, temperatures).
     * @return array
     */
    public function getRelatedDataForForms() {
        $data = [
            'users' => [],
            'contacts' => [],
            'organisations' => [],
            'statuses' => LeadStatusHistory::getStatusOptions(),
            'temperatures' => LeadStatusHistory::getTemperatureOptions()
        ];
        try {
            $stmt_users = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC");
            while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
                $data['users'][] = [
                    'id' => $row['id'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['username']
                ];
            }
            $stmt_contacts = $this->pdo->query("SELECT id, first_name, last_name FROM contacts WHERE is_active = TRUE ORDER BY last_name ASC, first_name ASC");
             while ($row = $stmt_contacts->fetch(PDO::FETCH_ASSOC)) {
                $data['contacts'][] = ['id' => $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
            }
            $stmt_orgs = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
            $data['organisations'] = $stmt_orgs->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            log_message('error', 'Error fetching related data for lead forms: ' . $e->getMessage());
        }
        return $data;
    }
}
?>
