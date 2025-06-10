<?php
// crm_app/classes/Organisation.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';

class Organisation {
    private $pdo;

    public $id;
    public $name;
    public $website;
    public $phone;
    public $address_street;
    public $address_city;
    public $address_state;
    public $address_zip;
    public $address_country;
    public $description;
    public $industry;
    public $annual_revenue;
    public $created_by_user_id;
    public $is_active;
    public $created_at;
    public $updated_at;
    public $version;

    public $created_by_username;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Default values for new organisations, matching schema defaults where applicable
        // Schema: is_active DEFAULT TRUE, version DEFAULT 1
        $this->is_active = true;
        $this->version = 1;
    }

    /**
     * Create a new organisation record in the database.
     * @param int $creator_user_id The ID of the user creating this organisation.
     * @return bool True on success, false on failure.
     * @throws Exception if required fields are missing or validation fails.
     */
    public function create($creator_user_id) {
        if (empty($this->name)) {
            throw new InvalidArgumentException("Organisation name is required.");
        }
        if (empty($creator_user_id)) {
            throw new InvalidArgumentException("Creator user ID is required.");
        }
        $this->created_by_user_id = $creator_user_id;
        // Ensure is_active is boolean; constructor sets default. If explicitly set, validate.
        // If $this->is_active was explicitly set to false before calling create, respect that.
        if (!is_bool($this->is_active)) { // If not explicitly set to true/false, use default
            $this->is_active = true;
        }
        // Version is set in constructor, will be used in bindParam.

        $sql = "INSERT INTO organisations (name, website, phone, address_street, address_city, address_state, address_zip, address_country, description, industry, annual_revenue, created_by_user_id, is_active, version)
                VALUES (:name, :website, :phone, :address_street, :address_city, :address_state, :address_zip, :address_country, :description, :industry, :annual_revenue, :created_by_user_id, :is_active, :version)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':website', $this->website);
            $stmt->bindParam(':phone', $this->phone);
            $stmt->bindParam(':address_street', $this->address_street);
            $stmt->bindParam(':address_city', $this->address_city);
            $stmt->bindParam(':address_state', $this->address_state);
            $stmt->bindParam(':address_zip', $this->address_zip);
            $stmt->bindParam(':address_country', $this->address_country);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':industry', $this->industry);
            $stmt->bindParam(':annual_revenue', $this->annual_revenue);
            $stmt->bindParam(':created_by_user_id', $this->created_by_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':version', $this->version, PDO::PARAM_INT); // Use object's version (default 1)

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();
            $this->read($this->id); // Re-fetch to get DB defaults like created_at, updated_at and confirm version
            log_message('info', 'Organisation created successfully.', ['org_id' => $this->id, 'org_name' => $this->name, 'created_by' => $this->created_by_user_id]);
            return true;
        } catch (PDOException $e) {
            log_message('error', 'Error creating organisation: ' . $e->getMessage(), ['org_name' => $this->name]);
            throw $e;
        }
    }

    /**
     * Read an organisation record from the database by ID.
     * Populates the object's properties.
     * @param int $id The ID of the organisation to read.
     * @return bool True on success, false if not found.
     */
    public function read($id) {
        $sql = "SELECT o.*, u.username as created_by_username
                FROM organisations o
                LEFT JOIN users u ON o.created_by_user_id = u.id
                WHERE o.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $this->id = (int)$data['id'];
                $this->name = $data['name'];
                $this->website = $data['website'];
                $this->phone = $data['phone'];
                $this->address_street = $data['address_street'];
                $this->address_city = $data['address_city'];
                $this->address_state = $data['address_state'];
                $this->address_zip = $data['address_zip'];
                $this->address_country = $data['address_country'];
                $this->description = $data['description'];
                $this->industry = $data['industry'];
                $this->annual_revenue = $data['annual_revenue'] === null ? null : (float)$data['annual_revenue'];
                $this->created_by_user_id = (int)$data['created_by_user_id'];
                $this->created_by_username = $data['created_by_username'] ?? null;
                $this->is_active = (bool)$data['is_active'];
                $this->created_at = $data['created_at'];
                $this->updated_at = $data['updated_at'];
                $this->version = (int)$data['version'];
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading organisation: ' . $e->getMessage(), ['org_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing organisation record in the database.
     * Uses optimistic locking with the version column.
     * @return bool True on success, false on failure or version conflict.
     * @throws Exception if version conflict or other DB error.
     */
    public function update() {
        if (empty($this->id) || $this->version === null) {
            throw new InvalidArgumentException("Organisation ID and version are required for updates. Please read the record first.");
        }
        if (empty($this->name)) {
            throw new InvalidArgumentException("Organisation name is required.");
        }
        if (!is_bool($this->is_active)) { // Ensure is_active is boolean before binding
             $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }


        $sql = "UPDATE organisations SET
                    name = :name, website = :website, phone = :phone,
                    address_street = :address_street, address_city = :address_city, address_state = :address_state,
                    address_zip = :address_zip, address_country = :address_country, description = :description,
                    industry = :industry, annual_revenue = :annual_revenue, is_active = :is_active,
                    version = :new_version
                WHERE id = :id AND version = :current_version";
        try {
            $stmt = $this->pdo->prepare($sql);

            $new_version = $this->version + 1;

            $stmt->bindParam(':name', $this->name);
            $stmt->bindParam(':website', $this->website);
            $stmt->bindParam(':phone', $this->phone);
            $stmt->bindParam(':address_street', $this->address_street);
            $stmt->bindParam(':address_city', $this->address_city);
            $stmt->bindParam(':address_state', $this->address_state);
            $stmt->bindParam(':address_zip', $this->address_zip);
            $stmt->bindParam(':address_country', $this->address_country);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':industry', $this->industry);
            $stmt->bindParam(':annual_revenue', $this->annual_revenue);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':new_version', $new_version, PDO::PARAM_INT);
            $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':current_version', $this->version, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->version = $new_version; // Update object's version
                // Fetch just updated_at to avoid overwriting any potentially modified object properties during a complex transaction
                $refetchStmt = $this->pdo->prepare("SELECT updated_at FROM organisations WHERE id = :id");
                $refetchStmt->bindParam(':id', $this->id, PDO::PARAM_INT);
                $refetchStmt->execute();
                $updatedData = $refetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($updatedData) {
                    $this->updated_at = $updatedData['updated_at'];
                }
                log_message('info', 'Organisation updated successfully.', ['org_id' => $this->id, 'new_version' => $this->version]);
                return true;
            } else {
                // Re-check version to distinguish between "no change" and "optimistic lock fail"
                $checker = new Organisation($this->pdo);
                if ($checker->read($this->id) && $checker->version != $this->version) {
                    throw new Exception("Update failed. The organisation record was modified by someone else (version mismatch: object had {$this->version}, DB has {$checker->version}). Please refresh and try again.");
                }
                log_message('warning', 'Organisation update resulted in no changed rows (possibly no actual data difference or record not found with specified version).', ['org_id' => $this->id, 'version_tried' => $this->version]);
                return true;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error updating organisation: ' . $e->getMessage(), ['org_id' => $this->id]);
            throw $e;
        }
    }

    /**
     * Soft delete (deactivate) or reactivate an organisation.
     * @param bool $isActive True to activate, false to deactivate.
     * @return bool True on success, false on failure.
     * @throws Exception if update fails.
     */
    public function setActiveStatus($isActive) {
        if (empty($this->id) || $this->version === null) {
             throw new InvalidArgumentException("Organisation ID and version are required to change active status. Please read the record first.");
        }
        $this->is_active = (bool)$isActive;
        return $this->update(); // Leverages the main update method's optimistic locking
    }

    /**
     * Hard delete an organisation from the database.
     * PREVENTS deletion if there are any ACTIVE deals associated with this organisation.
     * Active deals are those where `deals.is_active = TRUE` AND `deals.stage` is NOT 'Won' AND `deals.stage` is NOT 'Lost'.
     * @param PDO $pdo The PDO connection object.
     * @param int $id The ID of the organisation to delete.
     * @return bool True on success, false on failure.
     * @throws Exception if deletion is prevented or a DB error occurs.
     */
    public static function hardDelete(PDO $pdo, $id) {
        $check_sql = "SELECT COUNT(*) FROM deals
                      WHERE organisation_id = :org_id
                      AND deals.is_active = TRUE
                      AND deals.stage NOT IN ('Won', 'Lost')"; // Corrected schema name 'stage'
        try {
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->bindParam(':org_id', $id, PDO::PARAM_INT);
            $check_stmt->execute();
            $active_deals_count = $check_stmt->fetchColumn();

            if ($active_deals_count > 0) {
                log_message('warning', "Attempt to delete organisation ID {$id} with {$active_deals_count} active deals failed.");
                throw new Exception("Cannot delete organisation: There are {$active_deals_count} active (non-Won/Lost) deals associated. Please close or reassign these deals.");
            }

            $sql = "DELETE FROM organisations WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'Organisation hard deleted successfully.', ['org_id' => $id]);
                return true;
            } else {
                log_message('warning', 'Organisation hard delete failed (organisation not found).', ['org_id' => $id]);
                return false;
            }
        } catch (PDOException $e) {
             if ($e->getCode() == '23000') {
                 log_message('error', 'Error hard deleting organisation due to foreign key constraint: ' . $e->getMessage(), ['org_id' => $id]);
                 throw new Exception("Cannot delete organisation due to related records (e.g., contacts). Deactivate instead or remove associations.");
            }
            log_message('error', 'Error hard deleting organisation: ' . $e->getMessage(), ['org_id' => $id]);
            throw $e;
        }
    }

    /**
     * Get all organisation records from the database.
     * @param PDO $pdo The PDO connection object.
     * @param array $filters Optional filters (e.g., ['is_active' => true, 'search_term' => 'Corp'])
     * @param bool $fetch_created_by_username If true, joins with users table to get username. Defaults to true.
     * @return array Array of Organisation objects.
     */
    public static function readAll(PDO $pdo, $filters = [], $fetch_created_by_username = true) {
        $select_fields = "o.*";
        $joins = "";
        if ($fetch_created_by_username) {
            $select_fields .= ", u.username as created_by_username";
            $joins .= " LEFT JOIN users u ON o.created_by_user_id = u.id";
        }

        $sql = "SELECT {$select_fields} FROM organisations o {$joins}";
        $where_clauses = [];
        $params = [];

        $is_admin_viewing_all = (isset($filters['view']) && $filters['view'] === 'all' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);

        if (!$is_admin_viewing_all) {
            if (isset($filters['is_active'])) {
                $where_clauses[] = "o.is_active = :is_active";
                $params[':is_active'] = (bool)$filters['is_active'];
            } else {
                $where_clauses[] = "o.is_active = TRUE";
            }
        } elseif (isset($filters['is_active'])) { // Admin viewing all, but specifically filtering by active status
            $where_clauses[] = "o.is_active = :is_active";
            $params[':is_active'] = (bool)$filters['is_active'];
        }


        if (!empty($filters['search_term'])) {
            $search_term_like = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(o.name LIKE :search_term OR o.website LIKE :search_term OR o.phone LIKE :search_term OR o.address_city LIKE :search_term OR o.industry LIKE :search_term)";
            $params[':search_term'] = $search_term_like;
        }
         if (!empty($filters['industry'])) {
            $where_clauses[] = "o.industry = :industry";
            $params[':industry'] = $filters['industry'];
        }


        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }
        $sql .= " ORDER BY o.name ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $organisations_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $organisations = [];
            foreach ($organisations_data as $data) {
                $org = new Organisation($pdo);
                $org->id = (int)$data['id'];
                $org->name = $data['name'];
                $org->website = $data['website'];
                $org->phone = $data['phone'];
                $org->address_street = $data['address_street'];
                $org->address_city = $data['address_city'];
                $org->address_state = $data['address_state'];
                $org->address_zip = $data['address_zip'];
                $org->address_country = $data['address_country'];
                $org->description = $data['description'];
                $org->industry = $data['industry'];
                $org->annual_revenue = $data['annual_revenue'] === null ? null : (float)$data['annual_revenue'];
                $org->created_by_user_id = (int)$data['created_by_user_id'];
                if ($fetch_created_by_username && isset($data['created_by_username'])) {
                     $org->created_by_username = $data['created_by_username'];
                } else if ($fetch_created_by_username) {
                     $org->created_by_username = null;
                }
                $org->is_active = (bool)$data['is_active'];
                $org->created_at = $data['created_at'];
                $org->updated_at = $data['updated_at'];
                $org->version = (int)$data['version'];
                $organisations[] = $org;
            }
            return $organisations;
        } catch (PDOException $e) {
            log_message('error', 'Error reading all organisations: ' . $e->getMessage(), ['filters' => $filters]);
            throw $e;
        }
    }

    /**
     * Provides a list of distinct industries for filter dropdowns.
     * @param PDO $pdo
     * @return array
     */
    public static function getDistinctIndustries(PDO $pdo) {
        $sql = "SELECT DISTINCT industry FROM organisations WHERE industry IS NOT NULL AND industry != '' ORDER BY industry ASC";
        try {
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            log_message('error', 'Error fetching distinct industries: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get related data for forms (e.g., users for 'created_by_user_id' if needed, though it's auto-set).
     * This is a placeholder, as organisations don't have many dropdowns for their own fields beyond industry.
     * @return array
     */
    public function getRelatedDataForForms() {
        return [
            'industries' => self::getDistinctIndustries($this->pdo)
        ];
    }
}
?>
