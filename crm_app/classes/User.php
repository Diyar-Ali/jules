<?php
require_once __DIR__ . '/../config/db.php'; // For $pdo
require_once __DIR__ . '/../includes/log_helper.php'; // For log_message

class User {
    private $pdo;

    public $id;
    public $username;
    private $password_hash; // Not directly settable, use setPassword
    public $email;
    public $first_name;
    public $last_name;
    public $role;
    public $is_admin;
    public $is_active;
    public $created_at;
    public $updated_at;
    public $version;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Set user password by hashing it.
     * @param string $password The plain text password.
     */
    public function setPassword($password) {
        if (empty($password)) {
            throw new InvalidArgumentException("Password cannot be empty.");
        }
        $this->password_hash = password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify a given password against the user's stored hash.
     * @param string $password The plain text password to verify.
     * @return bool True if password matches, false otherwise.
     */
    public function verifyPassword($password) {
        if (empty($this->password_hash) || empty($password)) {
            return false;
        }
        return password_verify($password, $this->password_hash);
    }

    /**
     * Get the password hash.
     * Used internally for saving, not for direct output.
     * @return string|null The password hash.
     */
    private function getPasswordHash() {
        return $this->password_hash;
    }

    /**
     * Create a new user record in the database.
     * Requires username and a password (set via setPassword()) to be set.
     * @return bool True on success, false on failure.
     * @throws Exception if username or email already exists, or other PDO/validation exceptions.
     */
    public function create() {
        if (empty($this->username)) {
            log_message('error', 'Username is required to create a user.');
            throw new InvalidArgumentException("Username is required to create a user.");
        }
        if (empty($this->password_hash)) { // Ensure setPassword() was called
            log_message('error', 'Password is required to create a user. Call setPassword().');
            throw new InvalidArgumentException("Password is required. Call setPassword() before creating.");
        }

        // Check for duplicate username
        if (self::findByUsername($this->pdo, $this->username, false)) { // Check all users, not just active
             log_message('warning', 'Attempt to create user with duplicate username: ' . $this->username);
             throw new Exception("Username already exists.");
        }
        // Check for duplicate email if provided
        if (!empty($this->email) && self::findByEmail($this->pdo, $this->email)) {
            log_message('warning', 'Attempt to create user with duplicate email: ' . $this->email);
            throw new Exception("Email already exists.");
        }

        // Apply defaults if properties are not set
        $this->role = $this->role ?? 'viewer';
        $this->is_admin = $this->is_admin ?? false;
        $this->is_active = $this->is_active ?? true;
        // version will be set to 1 by SQL

        $sql = "INSERT INTO users (username, password_hash, email, first_name, last_name, role, is_admin, is_active, version)
                VALUES (:username, :password_hash, :email, :first_name, :last_name, :role, :is_admin, :is_active, 1)";
        try {
            $stmt = $this->pdo->prepare($sql);

            $stmt->bindParam(':username', $this->username);
            $stmt->bindParam(':password_hash', $this->password_hash);
            $stmt->bindParam(':email', $this->email);
            $stmt->bindParam(':first_name', $this->first_name);
            $stmt->bindParam(':last_name', $this->last_name);
            $stmt->bindParam(':role', $this->role);
            $stmt->bindParam(':is_admin', $this->is_admin, PDO::PARAM_BOOL);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();
            // Re-fetch to get created_at, updated_at, and confirm version
            $this->read($this->id);
            log_message('info', 'User created successfully.', ['user_id' => $this->id, 'username' => $this->username]);
            return true;
        } catch (PDOException $e) {
            log_message('error', 'Error creating user: ' . $e->getMessage(), ['username' => $this->username]);
            throw $e;
        }
    }

    /**
     * Read a user record from the database by ID.
     * Populates the object's properties.
     * @param int $id The ID of the user to read.
     * @return bool True on success, false if not found.
     * @throws PDOException on database error.
     */
    public function read($id) {
        $sql = "SELECT * FROM users WHERE id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data) {
                $this->id = (int)$user_data['id'];
                $this->username = $user_data['username'];
                $this->password_hash = $user_data['password_hash'];
                $this->email = $user_data['email'];
                $this->first_name = $user_data['first_name'];
                $this->last_name = $user_data['last_name'];
                $this->role = $user_data['role'];
                $this->is_admin = (bool)$user_data['is_admin'];
                $this->is_active = (bool)$user_data['is_active'];
                $this->created_at = $user_data['created_at'];
                $this->updated_at = $user_data['updated_at'];
                $this->version = (int)$user_data['version']; // Schema: NOT NULL DEFAULT 1
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading user: ' . $e->getMessage(), ['user_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing user record in the database.
     * Uses optimistic locking with the version column.
     * @return bool True on success, false on failure or version conflict.
     * @throws Exception on error or validation/conflict issues.
     */
    public function update() {
        if (empty($this->id) || $this->version === null) {
            throw new Exception("User ID and version are required for updates. Please read the user first.");
        }

        $currentUserData = new User($this->pdo);
        if (!$currentUserData->read($this->id)) { // Fetch current DB state
             throw new Exception("User with ID {$this->id} not found for update.");
        }

        // Optimistic lock check: compare object's version with the one just fetched
        if ($currentUserData->version !== $this->version) {
            log_message('warning', 'Optimistic lock conflict for user update.', [
                'user_id' => $this->id,
                'object_version' => $this->version,
                'db_version' => $currentUserData->version
            ]);
            throw new Exception("Update failed. The record has been modified by someone else. Please refresh and try again.");
        }

        // Check for duplicate username (if changed)
        if ($this->username !== $currentUserData->username) {
            if (self::findByUsername($this->pdo, $this->username, false, $this->id)) {
                throw new Exception("Username '{$this->username}' already exists.");
            }
        }
        // Check for duplicate email (if changed and not empty)
        if (!empty($this->email) && $this->email !== $currentUserData->email) {
             if (self::findByEmail($this->pdo, $this->email, $this->id)) {
                throw new Exception("Email '{$this->email}' already exists.");
            }
        }

        $sql_parts = [];
        $params = [];

        // Build the SET part of the SQL query dynamically
        if ($this->username !== $currentUserData->username) {
            $sql_parts[] = "username = :username"; $params[':username'] = $this->username;
        }
        if ($this->email !== $currentUserData->email) {
            $sql_parts[] = "email = :email"; $params[':email'] = $this->email;
        }
        if ($this->first_name !== $currentUserData->first_name) {
            $sql_parts[] = "first_name = :first_name"; $params[':first_name'] = $this->first_name;
        }
        if ($this->last_name !== $currentUserData->last_name) {
            $sql_parts[] = "last_name = :last_name"; $params[':last_name'] = $this->last_name;
        }
        if ($this->role !== $currentUserData->role) {
            $sql_parts[] = "role = :role"; $params[':role'] = $this->role;
        }
        if ($this->is_admin !== $currentUserData->is_admin) {
            $sql_parts[] = "is_admin = :is_admin"; $params[':is_admin'] = (bool)$this->is_admin;
        }
        if ($this->is_active !== $currentUserData->is_active) {
            $sql_parts[] = "is_active = :is_active"; $params[':is_active'] = (bool)$this->is_active;
        }

        // Only include password_hash in SQL if a new password was explicitly set (via setPassword())
        // and it's different from the current hash
        if (!empty($this->password_hash) && $this->password_hash !== $currentUserData->getPasswordHash()) {
            $sql_parts[] = "password_hash = :password_hash";
            $params[':password_hash'] = $this->password_hash;
        }

        if (empty($sql_parts)) { // No actual data changed
            log_message('info', 'User update called but no data fields were different.', ['user_id' => $this->id]);
            return true;
        }

        $sql = "UPDATE users SET " . implode(", ", $sql_parts) . ", version = :new_version
                WHERE id = :id AND version = :current_version";

        $params[':new_version'] = $this->version + 1;
        $params[':id'] = $this->id;
        $params[':current_version'] = $this->version;

        try {
            $stmt = $this->pdo->prepare($sql);
            // Bind boolean parameters with explicit type
            if (isset($params[':is_admin'])) $stmt->bindParam(':is_admin', $params[':is_admin'], PDO::PARAM_BOOL);
            if (isset($params[':is_active'])) $stmt->bindParam(':is_active', $params[':is_active'], PDO::PARAM_BOOL);

            // Bind other parameters (bindParam needs variable reference)
            foreach ($params as $key => &$value) { // Pass $value by reference
                 if ($key !== ':is_admin' && $key !== ':is_active') { // Already bound with type
                    $stmt->bindParam($key, $value);
                 }
            }
            unset($value); // Unset reference

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->version++;
                // Re-read to get the updated_at timestamp from DB and confirm changes
                $this->read($this->id);
                log_message('info', 'User updated successfully.', ['user_id' => $this->id, 'new_version' => $this->version]);
                return true;
            } else {
                // If rowCount is 0, it means the WHERE clause (id and version) didn't match.
                // We already checked if the version was stale. If it wasn't, this means user ID not found (less likely here)
                // or some other concurrent modification made the version check fail.
                // Re-fetch to be sure about the current DB state.
                $freshCheck = new User($this->pdo);
                if ($freshCheck->read($this->id) && $freshCheck->version !== $this->version) {
                    log_message('warning', 'User update failed after dynamic query: Optimistic lock conflict detected on re-check.', [
                        'user_id' => $this->id, 'tried_version' => $this->version, 'current_db_version' => $freshCheck->version
                    ]);
                    throw new Exception("Update failed. The record was modified by someone else during the update process. Please refresh and try again.");
                }
                log_message('warning', 'User update affected 0 rows. User not found with ID and version, or no data values were actually changed by the SET clause.', ['user_id' => $this->id, 'version_tried' => $this->version]);
                return false; // Or true if "no change" is okay, but 0 rows affected usually indicates a problem with WHERE
            }
        } catch (PDOException $e) {
            log_message('error', 'Error updating user: ' . $e->getMessage(), ['user_id' => $this->id, 'params' => array_keys($params)]);
            throw $e;
        }
    }

    /**
     * Activate or deactivate a user.
     * @param bool $isActive True to activate, false to deactivate.
     * @return bool True on success, false on failure.
     * @throws Exception on error or if user not found.
     */
    public function setActiveStatus($isActive) {
        if (empty($this->id)) { // Version check will be handled by read() and update()
            throw new Exception("User ID is required to change active status. Please read the user first.");
        }
        // Ensure the object has the latest data, especially version
        if (!$this->read($this->id)) {
            throw new Exception("User not found when trying to set active status.");
        }
        $this->is_active = (bool)$isActive;
        return $this->update();
    }

    /**
     * Delete a user record from the database (hard delete).
     * @param PDO $pdo The PDO connection object.
     * @param int $id The ID of the user to delete.
     * @return bool True on success, false on failure.
     * @throws Exception on error or if user is referenced by other records.
     */
    public static function hardDelete(PDO $pdo, $id) {
        $sql = "DELETE FROM users WHERE id = :id";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'User hard deleted successfully.', ['user_id' => $id]);
                return true;
            } else {
                log_message('warning', 'User hard delete failed (user not found).', ['user_id' => $id]);
                return false;
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') { // Integrity constraint violation
                 log_message('error', 'Error hard deleting user due to foreign key constraint: ' . $e->getMessage(), ['user_id' => $id]);
                 throw new Exception("Cannot delete user. This user is referenced by other records (e.g., created organisations, leads, activities, etc.). Please reassign or delete related records first, or deactivate the user instead.");
            }
            log_message('error', 'Error hard deleting user: ' . $e->getMessage(), ['user_id' => $id]);
            throw $e;
        }
    }

    /**
     * Find a user by username.
     * @param PDO $pdo The PDO connection object.
     * @param string $username The username to search for.
     * @param bool $onlyActive If true (default), only search active users.
     * @param int|null $excludeId Optional user ID to exclude from search (for updates).
     * @return User|false User object if found, false otherwise.
     * @throws PDOException on database error.
     */
    public static function findByUsername(PDO $pdo, $username, $onlyActive = true, $excludeId = null) {
        $sql = "SELECT * FROM users WHERE username = :username";
        $params = [':username' => $username];
        if ($onlyActive) {
            $sql .= " AND is_active = TRUE";
        }
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = (int)$excludeId;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data) {
                $user = new User($pdo);
                $user->id = (int)$user_data['id'];
                $user->username = $user_data['username'];
                $user->password_hash = $user_data['password_hash'];
                $user->email = $user_data['email'];
                $user->first_name = $user_data['first_name'];
                $user->last_name = $user_data['last_name'];
                $user->role = $user_data['role'];
                $user->is_admin = (bool)$user_data['is_admin'];
                $user->is_active = (bool)$user_data['is_active'];
                $user->created_at = $user_data['created_at'];
                $user->updated_at = $user_data['updated_at'];
                $user->version = (int)$user_data['version'];
                return $user;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error finding user by username: ' . $e->getMessage(), ['username' => $username]);
            throw $e;
        }
    }

    /**
     * Find a user by email.
     * @param PDO $pdo The PDO connection object.
     * @param string $email The email to search for.
     * @param int|null $excludeId Optional user ID to exclude from search (for updates).
     * @return User|false User object if found, false otherwise.
     * @throws PDOException on database error.
     */
    public static function findByEmail(PDO $pdo, $email, $excludeId = null) {
        $sql = "SELECT * FROM users WHERE email = :email";
        $params = [':email' => $email];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = (int)$excludeId;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user_data) {
                $user = new User($pdo);
                $user->id = (int)$user_data['id'];
                $user->username = $user_data['username'];
                $user->password_hash = $user_data['password_hash'];
                $user->email = $user_data['email'];
                $user->first_name = $user_data['first_name'];
                $user->last_name = $user_data['last_name'];
                $user->role = $user_data['role'];
                $user->is_admin = (bool)$user_data['is_admin'];
                $user->is_active = (bool)$user_data['is_active'];
                $user->created_at = $user_data['created_at'];
                $user->updated_at = $user_data['updated_at'];
                $user->version = (int)$user_data['version'];
                return $user;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', "Database error in findByEmail: " . $e->getMessage(), ['email' => $email]);
            throw $e;
        }
    }

    /**
     * Get all users from the database.
     * @param PDO $pdo The PDO connection object.
     * @param array $filters Optional filters (e.g., ['is_active' => true, 'role' => 'sales', 'search_term' => 'john'])
     * @return array Array of User objects.
     * @throws PDOException on database error.
     */
    public static function readAll(PDO $pdo, $filters = []) {
        $sql = "SELECT * FROM users";
        $where_clauses = [];
        $query_params = []; // Renamed from $params to avoid conflict with method parameter $filters

        if (isset($filters['is_active'])) {
            $where_clauses[] = "is_active = :is_active";
            $query_params[':is_active'] = (bool)$filters['is_active'];
        }
        if (!empty($filters['role'])) {
            $where_clauses[] = "role = :role";
            $query_params[':role'] = $filters['role'];
        }
        if (!empty($filters['search_term'])) {
            $search_term_like = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(username LIKE :search_term OR email LIKE :search_term OR first_name LIKE :search_term OR last_name LIKE :search_term)";
            $query_params[':search_term'] = $search_term_like;
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }
        $sql .= " ORDER BY username ASC";

        try {
            $stmt = $pdo->prepare($sql);
            // Bind parameters for PDO if using named placeholders in $query_params
            foreach ($query_params as $key => $value) {
                if ($key === ':is_active') {
                    $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->execute(); // Execute without params if using bindValue, or with $query_params if not using bindValue

            $users_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $users = [];
            foreach ($users_data as $user_data) {
                $user = new User($pdo);
                $user->id = (int)$user_data['id'];
                $user->username = $user_data['username'];
                // Omit password_hash for lists
                $user->email = $user_data['email'];
                $user->first_name = $user_data['first_name'];
                $user->last_name = $user_data['last_name'];
                $user->role = $user_data['role'];
                $user->is_admin = (bool)$user_data['is_admin'];
                $user->is_active = (bool)$user_data['is_active'];
                $user->created_at = $user_data['created_at'];
                $user->updated_at = $user_data['updated_at'];
                $user->version = (int)$user_data['version'];
                $users[] = $user;
            }
            return $users;
        } catch (PDOException $e) {
            log_message('error', 'Error reading all users: ' . $e->getMessage(), ['filters' => $filters]);
            throw $e;
        }
    }

    /**
     * Utility function to get roles for forms.
     * @return array
     */
    public static function getRoleOptions() {
        return ['sales', 'consultant', 'support', 'manager', 'viewer'];
    }
}
?>
