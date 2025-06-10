<?php
// crm_app/classes/Contact.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';

class Contact {
    private $pdo;

    public $id;
    public $first_name;
    public $last_name;
    public $email;
    public $phone_mobile;
    public $phone_work;
    public $title;
    public $organisation_id;
    public $created_by_user_id;
    public $is_active;
    public $created_at;
    public $updated_at;
    public $version;

    public $organisation_name;
    public $created_by_username;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // Schema defaults: is_active = TRUE, version = 1
        $this->is_active = true;
        $this->version = 1;
    }

    /**
     * Validates email format. Allows empty email as it's nullable in DB.
     * @param string|null $email The email to validate.
     * @return bool True if valid or empty, false otherwise.
     */
    private function isValidEmailFormat($email) {
        if (empty($email)) return true;
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Checks if an email is unique among active contacts, excluding a specific contact ID.
     * @param string|null $email The email to check.
     * @param int|null $exclude_contact_id The ID of the contact to exclude.
     * @return bool True if unique or empty, false otherwise.
     */
    private function isEmailUnique($email, $exclude_contact_id = null) {
        if (empty($email)) return true;

        $sql = "SELECT id FROM contacts WHERE email = :email AND is_active = TRUE";
        $params = [':email' => $email];

        if ($exclude_contact_id !== null) {
            $sql .= " AND id != :exclude_contact_id";
            $params[':exclude_contact_id'] = $exclude_contact_id;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn() === false;
        } catch (PDOException $e) {
            log_message('error', 'Error checking email uniqueness: ' . $e->getMessage(), ['email' => $email]);
            throw $e;
        }
    }

    /**
     * Create a new contact record in the database.
     * @param int $creator_user_id The ID of the user creating this contact.
     * @return bool True on success, false on failure.
     * @throws Exception if required fields are missing or validation fails.
     */
    public function create($creator_user_id) {
        if (empty($this->first_name) || empty($this->last_name)) {
            throw new InvalidArgumentException("Contact first name and last name are required.");
        }
        if (empty($creator_user_id)) {
            throw new InvalidArgumentException("Creator user ID is required.");
        }
        if (!$this->isValidEmailFormat($this->email)) {
            throw new InvalidArgumentException("Invalid email format.");
        }
        if (!$this->isEmailUnique($this->email)) {
            throw new InvalidArgumentException("This email address is already in use by another active contact.");
        }

        $this->created_by_user_id = $creator_user_id;
        // Ensure is_active is boolean; constructor sets default true.
        // If explicitly set to false before calling create, it should be respected.
        if (!is_bool($this->is_active)) {
            $this->is_active = true; // Fallback to default if not a proper boolean
        }
        $this->version = $this->version ?? 1; // Ensure version is 1 for new records (constructor default)

        $sql = "INSERT INTO contacts (first_name, last_name, email, phone_mobile, phone_work, title, organisation_id, created_by_user_id, is_active, version)
                VALUES (:first_name, :last_name, :email, :phone_mobile, :phone_work, :title, :organisation_id, :created_by_user_id, :is_active, :version)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':first_name', $this->first_name);
            $stmt->bindParam(':last_name', $this->last_name);
            $stmt->bindParam(':email', $this->email);
            $stmt->bindParam(':phone_mobile', $this->phone_mobile);
            $stmt->bindParam(':phone_work', $this->phone_work);
            $stmt->bindParam(':title', $this->title);
            $stmt->bindParam(':organisation_id', $this->organisation_id, $this->organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':created_by_user_id', $this->created_by_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':version', $this->version, PDO::PARAM_INT);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();
            $this->read($this->id);
            log_message('info', 'Contact created successfully.', ['contact_id' => $this->id, 'contact_name' => $this->first_name . ' ' . $this->last_name]);
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() == '23000' && (str_contains($e->getMessage(), 'contacts.email') || str_contains($e->getMessage(), "for key 'email'"))) {
                 throw new Exception("This email address is already registered. Please use a different email.");
            }
            log_message('error', 'Error creating contact: ' . $e->getMessage(), ['contact_name' => $this->first_name . ' ' . $this->last_name]);
            throw $e;
        }
    }

    /**
     * Read a contact record from the database by ID. Populates object properties.
     * @param int $id The ID of the contact to read.
     * @return bool True on success, false if not found.
     */
    public function read($id) {
        $sql = "SELECT c.*, u.username as created_by_username, o.name as organisation_name
                FROM contacts c
                LEFT JOIN users u ON c.created_by_user_id = u.id
                LEFT JOIN organisations o ON c.organisation_id = o.id
                WHERE c.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $this->id = (int)$data['id'];
                $this->first_name = $data['first_name'];
                $this->last_name = $data['last_name'];
                $this->email = $data['email'];
                $this->phone_mobile = $data['phone_mobile'];
                $this->phone_work = $data['phone_work'];
                $this->title = $data['title'];
                $this->organisation_id = $data['organisation_id'] === null ? null : (int)$data['organisation_id'];
                $this->organisation_name = $data['organisation_name'] ?? null;
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
            log_message('error', 'Error reading contact: ' . $e->getMessage(), ['contact_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing contact record. Uses optimistic locking.
     * @return bool True on success, false on failure or version conflict.
     * @throws Exception if validation fails or DB error.
     */
    public function update() {
        if (empty($this->id) || $this->version === null) {
            throw new InvalidArgumentException("Contact ID and version are required for updates. Please read the record first.");
        }
        if (empty($this->first_name) || empty($this->last_name)) {
            throw new InvalidArgumentException("Contact first name and last name are required.");
        }
        if (!$this->isValidEmailFormat($this->email)) {
            throw new InvalidArgumentException("Invalid email format.");
        }
        if (!$this->isEmailUnique($this->email, $this->id)) {
            throw new InvalidArgumentException("This email address is already in use by another active contact.");
        }
        if (!is_bool($this->is_active)) { // Ensure is_active is boolean before binding
             $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }

        $sql = "UPDATE contacts SET
                    first_name = :first_name, last_name = :last_name, email = :email,
                    phone_mobile = :phone_mobile, phone_work = :phone_work, title = :title,
                    organisation_id = :organisation_id, is_active = :is_active,
                    version = :new_version
                WHERE id = :id AND version = :current_version";
        try {
            $stmt = $this->pdo->prepare($sql);
            $new_version = $this->version + 1;

            $stmt->bindParam(':first_name', $this->first_name);
            $stmt->bindParam(':last_name', $this->last_name);
            $stmt->bindParam(':email', $this->email);
            $stmt->bindParam(':phone_mobile', $this->phone_mobile);
            $stmt->bindParam(':phone_work', $this->phone_work);
            $stmt->bindParam(':title', $this->title);
            $stmt->bindParam(':organisation_id', $this->organisation_id, $this->organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':new_version', $new_version, PDO::PARAM_INT);
            $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':current_version', $this->version, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->version = $new_version;
                $refetchStmt = $this->pdo->prepare("SELECT updated_at FROM contacts WHERE id = :id");
                $refetchStmt->bindParam(':id', $this->id, PDO::PARAM_INT);
                $refetchStmt->execute();
                $updatedData = $refetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($updatedData) {
                    $this->updated_at = $updatedData['updated_at'];
                }
                log_message('info', 'Contact updated successfully.', ['contact_id' => $this->id, 'new_version' => $this->version]);
                return true;
            } else {
                 $checker = new Contact($this->pdo);
                 if ($checker->read($this->id) && $checker->version != $this->version) {
                     throw new Exception("Update failed. The contact record was modified by someone else (version mismatch: object had {$this->version}, DB has {$checker->version}). Please refresh and try again.");
                 }
                 log_message('warning', 'Contact update resulted in no changed rows (possibly no actual data difference or record not found with specified version).', ['contact_id' => $this->id, 'version_tried' => $this->version]);
                 return true;
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000' && (str_contains($e->getMessage(), 'contacts.email') || str_contains($e->getMessage(), "for key 'email'"))) {
                 throw new Exception("This email address is already registered. Please use a different email.");
            }
            log_message('error', 'Error updating contact: ' . $e->getMessage(), ['contact_id' => $this->id]);
            throw $e;
        }
    }

    /**
     * Soft delete (deactivate) or reactivate a contact.
     * @param bool $isActive True to activate, false to deactivate.
     * @return bool True on success.
     * @throws Exception if update fails.
     */
    public function setActiveStatus($isActive) {
        if (empty($this->id) || $this->version === null) {
             throw new InvalidArgumentException("Contact ID and version are required to change active status. Please read the record first.");
        }
        $this->is_active = (bool)$isActive;
        return $this->update();
    }

    /**
     * Hard delete a contact from the database.
     * @param PDO $pdo The PDO connection object.
     * @param int $id The ID of the contact to delete.
     * @return bool True on success, false on failure.
     * @throws Exception on DB error or if deletion is not possible due to constraints.
     */
    public static function hardDelete(PDO $pdo, $id) {
        $sql = "DELETE FROM contacts WHERE id = :id";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'Contact hard deleted successfully.', ['contact_id' => $id]);
                return true;
            } else {
                log_message('warning', 'Contact hard delete failed (contact not found).', ['contact_id' => $id]);
                return false;
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                 log_message('error', 'Error hard deleting contact due to FK constraint: ' . $e->getMessage(), ['contact_id' => $id]);
                 throw new Exception("Cannot delete contact due to unexpected related records. Deactivate instead or check system integrity.");
            }
            log_message('error', 'Error hard deleting contact: ' . $e->getMessage(), ['contact_id' => $id]);
            throw $e;
        }
    }

    /**
     * Get all contact records from the database.
     * @param PDO $pdo The PDO connection object.
     * @param array $filters Optional filters.
     * @return array Array of Contact objects.
     */
    public static function readAll(PDO $pdo, $filters = []) {
        $sql = "SELECT c.*, u.username as created_by_username, o.name as organisation_name
                FROM contacts c
                LEFT JOIN users u ON c.created_by_user_id = u.id
                LEFT JOIN organisations o ON c.organisation_id = o.id";

        $where_clauses = [];
        $params = [];

        $is_admin_viewing_all = (isset($filters['view']) && $filters['view'] === 'all' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);

        if (!$is_admin_viewing_all) {
            if (isset($filters['is_active'])) {
                $where_clauses[] = "c.is_active = :is_active";
                $params[':is_active'] = (bool)$filters['is_active'];
            } else {
                $where_clauses[] = "c.is_active = TRUE";
            }
        } elseif (isset($filters['is_active'])) {
             $where_clauses[] = "c.is_active = :is_active";
             $params[':is_active'] = (bool)$filters['is_active'];
        }


        if (!empty($filters['search_term'])) {
            $search_term_like = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(c.first_name LIKE :search_term OR c.last_name LIKE :search_term OR c.email LIKE :search_term OR c.title LIKE :search_term OR o.name LIKE :search_term)";
            $params[':search_term'] = $search_term_like;
        }
        if (!empty($filters['organisation_id'])) {
            $where_clauses[] = "c.organisation_id = :organisation_id";
            $params[':organisation_id'] = $filters['organisation_id'];
        }
         if (!empty($filters['created_by_user_id'])) {
            $where_clauses[] = "c.created_by_user_id = :created_by_user_id";
            $params[':created_by_user_id'] = $filters['created_by_user_id'];
        }


        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }
        $sql .= " ORDER BY c.last_name ASC, c.first_name ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $contacts_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $contacts = [];
            foreach ($contacts_data as $data) {
                $contact = new Contact($pdo);
                $contact->id = (int)$data['id'];
                $contact->first_name = $data['first_name'];
                $contact->last_name = $data['last_name'];
                $contact->email = $data['email'];
                $contact->phone_mobile = $data['phone_mobile'];
                $contact->phone_work = $data['phone_work'];
                $contact->title = $data['title'];
                $contact->organisation_id = $data['organisation_id'] === null ? null : (int)$data['organisation_id'];
                $contact->organisation_name = $data['organisation_name'] ?? null;
                $contact->created_by_user_id = (int)$data['created_by_user_id'];
                $contact->created_by_username = $data['created_by_username'] ?? null;
                $contact->is_active = (bool)$data['is_active'];
                $contact->created_at = $data['created_at'];
                $contact->updated_at = $data['updated_at'];
                $contact->version = (int)$data['version'];
                $contacts[] = $contact;
            }
            return $contacts;
        } catch (PDOException $e) {
            log_message('error', 'Error reading all contacts: ' . $e->getMessage(), ['filters' => $filters]);
            throw $e;
        }
    }

    /**
     * Get related data for forms (e.g., list of active organisations).
     * @return array
     */
    public function getRelatedDataForForms() {
        $organisations_sql = "SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC";
        $organisations = [];
        try {
            $stmt = $this->pdo->query($organisations_sql);
            $organisations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            log_message('error', 'Error fetching active organisations for contact forms: ' . $e->getMessage());
        }
        return [
            'organisations' => $organisations
        ];
    }
}
?>
