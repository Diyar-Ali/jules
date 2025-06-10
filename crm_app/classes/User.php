<?php
// crm_app/classes/User.php

class User {
    private $pdo;
    private $table_name = "users";

    public function __construct($db) {
        $this->pdo = $db;
    }

    // Create a new user
    public function create($username, $password, $email, $first_name, $last_name, $role = 'viewer', $is_admin = false) {
        if (empty($username) || empty($password) || empty($email) || empty($first_name) || empty($last_name)) {
            return ['success' => false, 'message' => 'All fields except role and admin status are required.'];
        }

        // Check if username or email already exists
        $stmt_check = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE username = :username OR email = :email LIMIT 1");
        $stmt_check->bindParam(':username', $username);
        $stmt_check->bindParam(':email', $email);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $query = "INSERT INTO {$this->table_name}
                    (username, password_hash, email, first_name, last_name, role, is_admin, is_active, version)
                  VALUES
                    (:username, :password_hash, :email, :first_name, :last_name, :role, :is_admin, TRUE, 1)";

        $stmt = $this->pdo->prepare($query);

        // Sanitize and bind parameters
        $username = htmlspecialchars(strip_tags($username));
        $email = htmlspecialchars(strip_tags($email));
        $first_name = htmlspecialchars(strip_tags($first_name));
        $last_name = htmlspecialchars(strip_tags($last_name));
        $role = htmlspecialchars(strip_tags($role));
        $is_admin = filter_var($is_admin, FILTER_VALIDATE_BOOLEAN);

        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':is_admin', $is_admin, PDO::PARAM_BOOL);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'user_id' => $this->pdo->lastInsertId(), 'message' => 'User created successfully.'];
            } else {
                return ['success' => false, 'message' => 'Failed to create user.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "User creation failed: " . $e->getMessage()); // Requires log_helper
            return ['success' => false, 'message' => 'Database error during user creation: ' . $e->getMessage()];
        }
    }

    // User login
    public function login($username, $password) {
        $query = "SELECT id, username, password_hash, role, is_admin, is_active
                  FROM {$this->table_name}
                  WHERE username = :username LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $username = htmlspecialchars(strip_tags($username));
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (!$user['is_active']) {
                return ['success' => false, 'message' => 'Account is inactive. Please contact administrator.'];
            }
            if (password_verify($password, $user['password_hash'])) {
                return ['success' => true, 'user' => $user];
            }
        }
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    // Read all users (for admin)
    public function readAll($show_inactive = false) {
        $query = "SELECT id, username, email, first_name, last_name, role, is_admin, is_active, created_at, updated_at, version
                  FROM {$this->table_name}";
        if (!$show_inactive) {
            $query .= " WHERE is_active = TRUE";
        }
        $query .= " ORDER BY username ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Read a single user by ID
    public function readOne($id) {
        $query = "SELECT id, username, email, first_name, last_name, role, is_admin, is_active, created_at, updated_at, version
                  FROM {$this->table_name}
                  WHERE id = :id LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Update user details
    public function update($id, $email, $first_name, $last_name, $role, $is_admin, $current_version, $new_password = null) {
        // Fetch current version for optimistic locking
        $stmt_version = $this->pdo->prepare("SELECT version, password_hash FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_user = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_user) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        if ((int)$db_user['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The user data has been modified by someone else. Please refresh and try again.'];
        }

        $new_version = (int)$current_version + 1;

        $query = "UPDATE {$this->table_name} SET
                    email = :email,
                    first_name = :first_name,
                    last_name = :last_name,
                    role = :role,
                    is_admin = :is_admin,
                    version = :version";

        if (!empty($new_password)) {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $query .= ", password_hash = :password_hash";
        }

        $query .= " WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);

        $email = htmlspecialchars(strip_tags($email));
        $first_name = htmlspecialchars(strip_tags($first_name));
        $last_name = htmlspecialchars(strip_tags($last_name));
        $role = htmlspecialchars(strip_tags($role));
        $is_admin = filter_var($is_admin, FILTER_VALIDATE_BOOLEAN);

        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':is_admin', $is_admin, PDO::PARAM_BOOL);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        if (!empty($new_password)) {
            $stmt->bindParam(':password_hash', $new_password_hash);
        }

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'User updated successfully.'];
            } else {
                // Could be a version mismatch if not caught above, or no actual change in data
                // Re-check version, as rowCount() can be 0 if data is identical.
                $stmt_check_again = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $stmt_check_again->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_again->execute();
                $updated_user = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
                if ($updated_user && (int)$updated_user['version'] === $new_version) {
                     return ['success' => true, 'message' => 'User data was already up to date or updated successfully.'];
                }
                return ['success' => false, 'message' => 'Failed to update user or data conflict. Please refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "User update failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during user update: ' . $e->getMessage()];
        }
    }

    // Activate or Deactivate a user (Soft Delete)
    public function setActiveStatus($id, $is_active, $current_version) {
        // Fetch current version for optimistic locking
        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_user = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_user) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        if ((int)$db_user['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The user data has been modified. Please refresh.'];
        }

        $new_version = (int)$current_version + 1;
        $is_active_bool = filter_var($is_active, FILTER_VALIDATE_BOOLEAN);

        $query = "UPDATE {$this->table_name}
                  SET is_active = :is_active, version = :version
                  WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':is_active', $is_active_bool, PDO::PARAM_BOOL);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $action = $is_active_bool ? 'activated' : 'deactivated';
                return ['success' => true, 'message' => "User {$action} successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update user status or data conflict. Please refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "User status change failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during user status update: ' . $e->getMessage()];
        }
    }

    // Note: Hard delete is not implemented as per "soft/hard deletes" for admins,
    // but typical CRM flow favors soft deletes. If hard delete is truly needed for admins,
    // it would be a separate method. For now, only activate/deactivate.
}
?>
