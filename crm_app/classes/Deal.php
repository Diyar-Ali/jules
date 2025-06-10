<?php
// crm_app/classes/Deal.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';

class Deal {
    private $pdo;

    public $id;
    public $name;
    public $stage; // ENUM('Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost')
    public $amount; // DECIMAL(15,2)
    public $close_date; // DATE
    public $probability; // DECIMAL(5,2) , 0.00 to 1.00
    public $description;
    public $organisation_id; // Required FK
    public $contact_id; // Optional FK
    public $assigned_user_id; // Optional FK
    public $created_by_user_id; // Required
    public $is_active;
    public $created_at;
    public $updated_at;
    public $version;

    // For display
    public $organisation_name;
    public $contact_name;
    public $assigned_user_name;
    public $created_by_username;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Schema defaults: is_active = TRUE, version = 1, stage = 'Prospecting'
        $this->is_active = true;
        $this->version = 1;
        $this->stage = 'Prospecting';
        $this->probability = null;
    }

    /**
     * Create a new deal record.
     * @param int $creator_user_id ID of the user creating the deal.
     * @return bool True on success.
     * @throws Exception on failure or validation error.
     */
    public function create($creator_user_id) {
        if (empty($this->name)) throw new InvalidArgumentException("Deal name is required.");
        if (empty($this->organisation_id)) throw new InvalidArgumentException("Organisation ID is required for a deal.");
        if ($this->amount === null || !is_numeric($this->amount) || $this->amount < 0) {
             throw new InvalidArgumentException("Valid deal amount is required (must be 0 or greater).");
        }
         if ($this->probability !== null && (!is_numeric($this->probability) || $this->probability < 0 || $this->probability > 1)) {
            throw new InvalidArgumentException("Probability must be a number between 0.00 and 1.00 (e.g., 0.75 for 75%).");
        }

        $this->created_by_user_id = $creator_user_id;
        if (!is_bool($this->is_active)) {
            $this->is_active = true;
        }
        $this->version = $this->version ?? 1;

        $sql = "INSERT INTO deals (name, stage, amount, close_date, probability, description, organisation_id, contact_id, assigned_user_id, created_by_user_id, is_active, version)
                VALUES (:name, :stage, :amount, :close_date, :probability, :description, :organisation_id, :contact_id, :assigned_user_id, :created_by_user_id, :is_active, :version)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':stage', $this->stage);
            $stmt->bindParam(':amount', $this->amount);
            $stmt->bindParam(':close_date', $this->close_date);
            $stmt->bindParam(':probability', $this->probability);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':organisation_id', $this->organisation_id, PDO::PARAM_INT);
            $stmt->bindParam(':contact_id', $this->contact_id, $this->contact_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':assigned_user_id', $this->assigned_user_id, $this->assigned_user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':created_by_user_id', $this->created_by_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':version', $this->version, PDO::PARAM_INT);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();
            $this->read($this->id);
            log_message('info', 'Deal created successfully.', ['deal_id' => $this->id, 'deal_name' => $this->name]);
            return true;
        } catch (PDOException $e) {
            log_message('error', 'Error creating deal: ' . $e->getMessage(), ['deal_name' => $this->name]);
            throw $e;
        }
    }

    /**
     * Read a deal record from the database by ID.
     * @param int $id The ID of the deal to read.
     * @return bool True on success, false if not found.
     */
    public function read($id) {
        $sql = "SELECT d.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_user_name,
                       CONCAT(c.first_name, ' ', c.last_name) as contact_name,
                       org.name as organisation_name
                FROM deals d
                LEFT JOIN users u_creator ON d.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON d.assigned_user_id = u_assignee.id
                LEFT JOIN contacts c ON d.contact_id = c.id
                LEFT JOIN organisations org ON d.organisation_id = org.id -- Changed to LEFT JOIN for robustness
                WHERE d.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $this->id = (int)$data['id'];
                $this->name = $data['name'];
                $this->stage = $data['stage'];
                $this->amount = (float)$data['amount'];
                $this->close_date = $data['close_date'];
                $this->probability = $data['probability'] === null ? null : (float)$data['probability'];
                $this->description = $data['description'];
                $this->organisation_id = (int)$data['organisation_id']; // Org ID is NOT NULL in schema
                $this->contact_id = $data['contact_id'] === null ? null : (int)$data['contact_id'];
                $this->assigned_user_id = $data['assigned_user_id'] === null ? null : (int)$data['assigned_user_id'];
                $this->created_by_user_id = (int)$data['created_by_user_id'];
                $this->is_active = (bool)$data['is_active'];
                $this->created_at = $data['created_at'];
                $this->updated_at = $data['updated_at'];
                $this->version = (int)$data['version'];

                $this->organisation_name = $data['organisation_name'] ?? null; // Handle if org join fails
                $this->contact_name = trim((string)($data['contact_name'] ?? '')) ?: null;
                $this->assigned_user_name = $data['assigned_user_name'] ?? null;
                $this->created_by_username = $data['created_by_username'] ?? null;
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading deal: ' . $e->getMessage(), ['deal_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing deal record. Uses optimistic locking.
     * @return bool True on success.
     * @throws Exception on failure or validation error.
     */
    public function update() {
        if (empty($this->id) || $this->version === null) throw new InvalidArgumentException("Deal ID and version are required for updates. Please read the record first.");
        if (empty($this->name)) throw new InvalidArgumentException("Deal name is required.");
        if (empty($this->organisation_id)) throw new InvalidArgumentException("Organisation ID is required for a deal.");
        if ($this->amount === null || !is_numeric($this->amount) || $this->amount < 0) {
             throw new InvalidArgumentException("Valid deal amount is required (must be 0 or greater).");
        }
        if ($this->probability !== null && (!is_numeric($this->probability) || $this->probability < 0 || $this->probability > 1)) {
            throw new InvalidArgumentException("Probability must be a number between 0.00 and 1.00.");
        }
        if(!is_bool($this->is_active)){
            $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }


        $sql = "UPDATE deals SET
                    name = :name, stage = :stage, amount = :amount, close_date = :close_date,
                    probability = :probability, description = :description,
                    organisation_id = :organisation_id, contact_id = :contact_id,
                    assigned_user_id = :assigned_user_id, is_active = :is_active,
                    version = :new_version
                WHERE id = :id AND version = :current_version";

        try {
            $new_version = $this->version + 1;
            $stmt = $this->pdo->prepare($sql);

            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':stage', $this->stage);
            $stmt->bindParam(':amount', $this->amount);
            $stmt->bindParam(':close_date', $this->close_date);
            $stmt->bindParam(':probability', $this->probability);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':organisation_id', $this->organisation_id, PDO::PARAM_INT);
            $stmt->bindParam(':contact_id', $this->contact_id, $this->contact_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':assigned_user_id', $this->assigned_user_id, $this->assigned_user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':new_version', $new_version, PDO::PARAM_INT);
            $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':current_version', $this->version, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->version = $new_version;
                // Minimal re-fetch for updated_at
                $refetchStmt = $this->pdo->prepare("SELECT updated_at FROM deals WHERE id = :id");
                $refetchStmt->bindParam(':id', $this->id, PDO::PARAM_INT);
                $refetchStmt->execute();
                $updatedData = $refetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($updatedData) $this->updated_at = $updatedData['updated_at'];

                log_message('info', 'Deal updated successfully.', ['deal_id' => $this->id, 'new_version' => $this->version]);
                return true;
            } else {
                $checker = new Deal($this->pdo);
                if ($checker->read($this->id) && $checker->version !== $this->version) { // Check against the version we tried to update
                    throw new Exception("Update failed. The deal record was modified by someone else (version mismatch: object had {$this->version}, DB has {$checker->version}). Please refresh and try again.");
                }
                log_message('warning', 'Deal update resulted in no changed rows (possibly no actual data difference or record not found with specified version).', ['deal_id' => $this->id, 'version_tried' => $this->version]);
                return true;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error updating deal: ' . $e->getMessage(), ['deal_id' => $this->id]);
            throw $e;
        }
    }

    public function setActiveStatus($isActive) {
        if (empty($this->id) || $this->version === null) {
             throw new InvalidArgumentException("Deal ID and version are required to change active status. Please read the record first.");
        }
        $this->is_active = (bool)$isActive;
        return $this->update(); // Leverages the main update method which includes version increment
    }

    public static function hardDelete(PDO $pdo, $id) {
        // The `leads` table has `converted_to_deal_id` which is `ON DELETE SET NULL`.
        // Activities related to deals are polymorphic; their cleanup would be application logic if needed,
        // or a more complex DB schema with cascade deletes from a join table for activities.
        $sql = "DELETE FROM deals WHERE id = :id";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'Deal hard deleted successfully.', ['deal_id' => $id]);
                return true;
            }
            log_message('warning', 'Deal hard delete failed (deal not found or already deleted).', ['deal_id' => $id]);
            return false;
        } catch (PDOException $e) {
             if ($e->getCode() == '23000') {
                 log_message('error', 'Error hard deleting deal due to FK constraint: ' . $e->getMessage(), ['deal_id' => $id]);
                 throw new Exception("Cannot delete deal due to related records. Deactivate instead or check system integrity.");
            }
            log_message('error', 'Error hard deleting deal: ' . $e->getMessage(), ['deal_id' => $id]);
            throw $e;
        }
    }

    public static function readAll(PDO $pdo, $filters = []) {
        $sql = "SELECT d.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_user_name,
                       CONCAT(c.first_name, ' ', c.last_name) as contact_name,
                       org.name as organisation_name
                FROM deals d
                LEFT JOIN users u_creator ON d.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON d.assigned_user_id = u_assignee.id
                LEFT JOIN contacts c ON d.contact_id = c.id
                LEFT JOIN organisations org ON d.organisation_id = org.id"; // Changed to LEFT JOIN for safety

        $where_clauses = []; $params = [];

        $is_admin_viewing_all = (isset($filters['view']) && $filters['view'] === 'all' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);
        if (!$is_admin_viewing_all) {
            if (isset($filters['is_active'])) {
                $where_clauses[] = "d.is_active = :is_active"; $params[':is_active'] = (bool)$filters['is_active'];
            } else { $where_clauses[] = "d.is_active = TRUE"; }
        } elseif (isset($filters['is_active'])) {
             $where_clauses[] = "d.is_active = :is_active"; $params[':is_active'] = (bool)$filters['is_active'];
        }

        if (!empty($filters['search_term'])) {
            $s = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(d.name LIKE :s OR org.name LIKE :s OR CONCAT(c.first_name, ' ', c.last_name) LIKE :s OR d.description LIKE :s)";
            $params[':s'] = $s;
        }
        if (!empty($filters['stage'])) {
            $where_clauses[] = "d.stage = :stage"; $params[':stage'] = $filters['stage'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where_clauses[] = "d.assigned_user_id = :assigned_user_id"; $params[':assigned_user_id'] = $filters['assigned_user_id'];
        }
        if (!empty($filters['organisation_id'])) {
            $where_clauses[] = "d.organisation_id = :organisation_id"; $params[':organisation_id'] = $filters['organisation_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where_clauses[] = "d.contact_id = :contact_id"; $params[':contact_id'] = $filters['contact_id'];
        }

        if (!empty($where_clauses)) $sql .= " WHERE " . implode(" AND ", $where_clauses);
        $sql .= " ORDER BY d.updated_at DESC, d.name ASC";

        $deals = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $data) {
                $deal = new Deal($pdo);
                $deal->id = (int)$data['id']; $deal->name = $data['name']; $deal->stage = $data['stage'];
                $deal->amount = (float)$data['amount']; $deal->close_date = $data['close_date'];
                $deal->probability = $data['probability'] === null ? null : (float)$data['probability'];
                $deal->description = $data['description'];
                $deal->organisation_id = (int)$data['organisation_id'];
                $deal->contact_id = $data['contact_id'] === null ? null : (int)$data['contact_id'];
                $deal->assigned_user_id = $data['assigned_user_id'] === null ? null : (int)$data['assigned_user_id'];
                $deal->created_by_user_id = (int)$data['created_by_user_id'];
                $deal->is_active = (bool)$data['is_active'];
                $deal->created_at = $data['created_at']; $deal->updated_at = $data['updated_at']; $deal->version = (int)$data['version'];
                $deal->organisation_name = $data['organisation_name'] ?? null;
                $deal->contact_name = trim((string)($data['contact_name'] ?? '')) ?: null;
                $deal->assigned_user_name = $data['assigned_user_name'] ?? null;
                $deal->created_by_username = $data['created_by_username'] ?? null;
                $deals[] = $deal;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error reading all deals: ' . $e->getMessage(), ['filters' => $filters]);
        }
        return $deals;
    }

    public static function getStageOptions() {
        // These should match the ENUM definition in the 'deals' table schema
        return ['Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost'];
    }

    public function getRelatedDataForForms() {
        $data = [
            'users' => [], 'contacts' => [], 'organisations' => [],
            'stages' => self::getStageOptions()
        ];
        try {
            $stmt_users = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC");
            while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
                $data['users'][] = ['id' => $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['username']];
            }
            $stmt_contacts = $this->pdo->query("SELECT id, first_name, last_name FROM contacts WHERE is_active = TRUE ORDER BY last_name ASC, first_name ASC");
            while ($row = $stmt_contacts->fetch(PDO::FETCH_ASSOC)) {
                $data['contacts'][] = ['id' => $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
            }
            // Deals must have an organisation, so only active organisations should be listed
            $stmt_orgs = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
            $data['organisations'] = $stmt_orgs->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            log_message('error', 'Error fetching related data for deal forms: ' . $e->getMessage());
        }
        return $data;
    }
}
?>
