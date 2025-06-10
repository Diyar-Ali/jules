<?php
// crm_app/classes/Contact.php

class Contact {
    private $pdo;
    private $table_name = "contacts";

    public function __construct($db) {
        $this->pdo = $db;
    }

    // Create a new contact
    public function create($first_name, $last_name, $email, $phone_mobile, $phone_work, $title, $organisation_id, $created_by_user_id) {
        if (empty($first_name) || empty($last_name) || empty($created_by_user_id)) {
            return ['success' => false, 'message' => 'First name, last name, and creator ID are required.'];
        }
        // Basic email validation
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format.'];
        }
        // Check if email already exists (as it's unique)
        if (!empty($email)) {
            $stmt_check_email = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE email = :email LIMIT 1");
            $stmt_check_email->bindParam(':email', $email);
            $stmt_check_email->execute();
            if ($stmt_check_email->fetch()) {
                return ['success' => false, 'message' => 'This email address is already registered to another contact.'];
            }
        }

        $query = "INSERT INTO {$this->table_name}
                    (first_name, last_name, email, phone_mobile, phone_work, title, organisation_id, created_by_user_id, is_active, version, created_at, updated_at)
                  VALUES
                    (:first_name, :last_name, :email, :phone_mobile, :phone_work, :title, :organisation_id, :created_by_user_id, TRUE, 1, NOW(), NOW())";

        $stmt = $this->pdo->prepare($query);

        $first_name = htmlspecialchars(strip_tags($first_name));
        $last_name = htmlspecialchars(strip_tags($last_name));
        $email = !empty($email) ? htmlspecialchars(strip_tags($email)) : null;
        $phone_mobile = htmlspecialchars(strip_tags($phone_mobile)) ?: null;
        $phone_work = htmlspecialchars(strip_tags($phone_work)) ?: null;
        $title = htmlspecialchars(strip_tags($title)) ?: null;
        $organisation_id = filter_var($organisation_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $created_by_user_id = filter_var($created_by_user_id, FILTER_VALIDATE_INT);

        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone_mobile', $phone_mobile);
        $stmt->bindParam(':phone_work', $phone_work);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':organisation_id', $organisation_id, $organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':created_by_user_id', $created_by_user_id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'contact_id' => $this->pdo->lastInsertId(), 'message' => 'Contact created successfully.'];
            } else {
                return ['success' => false, 'message' => 'Failed to create contact.'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') { // Integrity constraint violation (e.g. duplicate email if somehow missed check or race condition)
                // log_message('ERROR', "Contact creation failed (duplicate email likely): " . $e->getMessage(), $created_by_user_id);
                return ['success' => false, 'message' => 'This email address might already be in use. Please check. Error: ' . $e->getMessage()];
            }
            // log_message('ERROR', "Contact creation failed: " . $e->getMessage(), $created_by_user_id);
            return ['success' => false, 'message' => 'Database error during contact creation: ' . $e->getMessage()];
        }
    }

    // Read all contacts
    public function readAll($is_admin = false, $is_active_filter = true, $organisation_id_filter = null) {
        $sql_params = [];
        $query = "SELECT c.*, u.username as created_by_username, o.name as organisation_name
                  FROM {$this->table_name} c
                  JOIN users u ON c.created_by_user_id = u.id
                  LEFT JOIN organisations o ON c.organisation_id = o.id";

        $where_clauses = [];
        if (!$is_admin && $is_active_filter) {
            $where_clauses[] = "c.is_active = TRUE";
        } elseif ($is_admin && !$is_active_filter) {
            // No active filter for admin if they want all
        } elseif ($is_active_filter) {
            $where_clauses[] = "c.is_active = TRUE";
        }

        if ($organisation_id_filter !== null) {
            $where_clauses[] = "c.organisation_id = :org_id_filter";
            $sql_params[':org_id_filter'] = $organisation_id_filter;
        }

        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(" AND ", $where_clauses);
        }

        $query .= " ORDER BY c.last_name ASC, c.first_name ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($sql_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Read a single contact by ID
    public function readOne($id, $is_admin = false) {
        $query = "SELECT c.*, u.username as created_by_username, o.name as organisation_name
                  FROM {$this->table_name} c
                  JOIN users u ON c.created_by_user_id = u.id
                  LEFT JOIN organisations o ON c.organisation_id = o.id
                  WHERE c.id = :id";

        if (!$is_admin) {
            $query .= " AND c.is_active = TRUE";
        }
        $query .= " LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact) {
            return ['success' => true, 'data' => $contact];
        } else {
            if (!$is_admin) {
                $stmt_check_inactive = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE id = :id AND is_active = FALSE LIMIT 1");
                $stmt_check_inactive->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_inactive->execute();
                if ($stmt_check_inactive->fetch()) {
                    return ['success' => false, 'message' => 'Contact found but is inactive. Access restricted.'];
                }
            }
            return ['success' => false, 'message' => 'Contact not found or access denied.'];
        }
    }

    // Update contact details
    public function update($id, $first_name, $last_name, $email, $phone_mobile, $phone_work, $title, $organisation_id, $current_version, $user_id_making_change) {
        $stmt_version = $this->pdo->prepare("SELECT version, email FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_contact = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_contact) {
            return ['success' => false, 'message' => 'Contact not found.'];
        }
        if ((int)$db_contact['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The contact data has been modified. Please refresh.'];
        }
        // Basic email validation
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format.'];
        }
        // Check if new email already exists for another contact
        if (!empty($email) && $email !== $db_contact['email']) {
            $stmt_check_email = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE email = :email AND id != :id LIMIT 1");
            $stmt_check_email->bindParam(':email', $email);
            $stmt_check_email->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_check_email->execute();
            if ($stmt_check_email->fetch()) {
                return ['success' => false, 'message' => 'This email address is already registered to another contact.'];
            }
        }

        $new_version = (int)$current_version + 1;

        $query = "UPDATE {$this->table_name} SET
                    first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone_mobile = :phone_mobile,
                    phone_work = :phone_work,
                    title = :title,
                    organisation_id = :organisation_id,
                    version = :version,
                    updated_at = NOW()
                  WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);

        $first_name = htmlspecialchars(strip_tags($first_name));
        $last_name = htmlspecialchars(strip_tags($last_name));
        $email = !empty($email) ? htmlspecialchars(strip_tags($email)) : null;
        $phone_mobile = htmlspecialchars(strip_tags($phone_mobile)) ?: null;
        $phone_work = htmlspecialchars(strip_tags($phone_work)) ?: null;
        $title = htmlspecialchars(strip_tags($title)) ?: null;
        $organisation_id = filter_var($organisation_id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;

        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone_mobile', $phone_mobile);
        $stmt->bindParam(':phone_work', $phone_work);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':organisation_id', $organisation_id, $organisation_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Contact updated successfully.'];
            } else {
                $stmt_check_again = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $stmt_check_again->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_again->execute();
                $updated_contact = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
                if ($updated_contact && (int)$updated_contact['version'] === $new_version) {
                     return ['success' => true, 'message' => 'Contact data was already up to date or updated successfully.'];
                }
                return ['success' => false, 'message' => 'Failed to update contact or data conflict. Ensure data was changed or refresh.'];
            }
        } catch (PDOException $e) {
             if ($e->getCode() == '23000') { // Integrity constraint violation (e.g. duplicate email)
                // log_message('ERROR', "Contact update failed (duplicate email likely) for ID {$id}: " . $e->getMessage(), $user_id_making_change);
                return ['success' => false, 'message' => 'This email address might already be in use by another contact. Error: ' . $e->getMessage()];
            }
            // log_message('ERROR', "Contact update failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during contact update: ' . $e->getMessage()];
        }
    }

    // Soft delete (activate/deactivate) a contact
    public function setActiveStatus($id, $is_active, $current_version, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            // log_message('WARNING', "Non-admin user ID {$user_id_making_change} attempted to change active status for contact ID {$id}.");
            return ['success' => false, 'message' => 'Permission denied. Only administrators can change contact active status.'];
        }

        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_contact = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_contact) {
            return ['success' => false, 'message' => 'Contact not found.'];
        }
        if ((int)$db_contact['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The contact data has been modified. Please refresh.'];
        }

        $new_version = (int)$current_version + 1;
        $is_active_bool = filter_var($is_active, FILTER_VALIDATE_BOOLEAN);

        $query = "UPDATE {$this->table_name}
                  SET is_active = :is_active, version = :version, updated_at = NOW()
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
                // log_message('INFO', "Contact ID {$id} {$action} by admin ID {$user_id_making_change}.");
                return ['success' => true, 'message' => "Contact {$action} successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update contact status or data conflict. Please refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Contact status change failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during contact status update: ' . $e->getMessage()];
        }
    }

    // Hard delete for contacts (Admin only)
    // Leads.contact_id and Deals.contact_id are ON DELETE SET NULL, so this should be safe from direct FK violations.
    // Activities related to contacts might need consideration if strict cleanup is needed, but schema doesn't enforce FK.
    public function hardDelete($id, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            // log_message('WARNING', "Non-admin user ID {$user_id_making_change} attempted hard delete for contact ID {$id}.");
            return ['success' => false, 'message' => 'Permission denied. Only administrators can hard delete contacts.'];
        }

        // Optional: Check for related active leads/deals if business rule requires it, even if DB allows SET NULL.
        // For now, directly proceeding with delete as per schema's ON DELETE SET NULL.

        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    // log_message('ALERT', "Contact ID {$id} HARD DELETED by admin ID {$user_id_making_change}.");
                    return ['success' => true, 'message' => 'Contact hard deleted successfully.'];
                } else {
                    return ['success' => false, 'message' => 'Contact not found or already deleted.'];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to hard delete contact.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Contact hard delete failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during contact hard delete: ' . $e->getMessage()];
        }
    }

    // Helper to get a list of organisations for dropdowns
    public function getAllOrganisationsForSelect() {
        // Only active organisations should be linkable
        $stmt = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
