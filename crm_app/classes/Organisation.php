<?php
// crm_app/classes/Organisation.php

class Organisation {
    private $pdo;
    private $table_name = "organisations";

    // Foreign key related table names (optional, for future use or complex queries)
    // private $user_table = "users";

    public function __construct($db) {
        $this->pdo = $db;
    }

    // Create a new organisation
    public function create($name, $website, $phone, $address_street, $address_city, $address_state, $address_zip, $address_country, $description, $industry, $annual_revenue, $created_by_user_id) {
        if (empty($name) || empty($created_by_user_id)) {
            return ['success' => false, 'message' => 'Organisation name and creator ID are required.'];
        }

        $query = "INSERT INTO {$this->table_name}
                    (name, website, phone, address_street, address_city, address_state, address_zip, address_country, description, industry, annual_revenue, created_by_user_id, is_active, version, created_at, updated_at)
                  VALUES
                    (:name, :website, :phone, :address_street, :address_city, :address_state, :address_zip, :address_country, :description, :industry, :annual_revenue, :created_by_user_id, TRUE, 1, NOW(), NOW())";

        $stmt = $this->pdo->prepare($query);

        // Sanitize and bind parameters
        $name = htmlspecialchars(strip_tags($name));
        $website = filter_var($website, FILTER_SANITIZE_URL) ?: null;
        $phone = htmlspecialchars(strip_tags($phone)) ?: null;
        $address_street = htmlspecialchars(strip_tags($address_street)) ?: null;
        $address_city = htmlspecialchars(strip_tags($address_city)) ?: null;
        $address_state = htmlspecialchars(strip_tags($address_state)) ?: null;
        $address_zip = htmlspecialchars(strip_tags($address_zip)) ?: null;
        $address_country = htmlspecialchars(strip_tags($address_country)) ?: null;
        $description = htmlspecialchars(strip_tags($description)) ?: null;
        $industry = htmlspecialchars(strip_tags($industry)) ?: null;
        $annual_revenue = filter_var($annual_revenue, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;
        $created_by_user_id = filter_var($created_by_user_id, FILTER_VALIDATE_INT);

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':website', $website);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':address_street', $address_street);
        $stmt->bindParam(':address_city', $address_city);
        $stmt->bindParam(':address_state', $address_state);
        $stmt->bindParam(':address_zip', $address_zip);
        $stmt->bindParam(':address_country', $address_country);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':industry', $industry);
        $stmt->bindParam(':annual_revenue', $annual_revenue);
        $stmt->bindParam(':created_by_user_id', $created_by_user_id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'organisation_id' => $this->pdo->lastInsertId(), 'message' => 'Organisation created successfully.'];
            } else {
                // This path might be less common with PDO::ERRMODE_EXCEPTION
                return ['success' => false, 'message' => 'Failed to create organisation due to statement execution error.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Organisation creation failed: " . $e->getMessage(), $created_by_user_id);
            return ['success' => false, 'message' => 'Database error during organisation creation: ' . $e->getMessage()];
        }
    }

    // Read all organisations
    // Admins can see inactive ones, others only see active ones.
    public function readAll($is_admin = false, $is_active_filter = true) {
        $query = "SELECT o.*, u.username as created_by_username
                  FROM {$this->table_name} o
                  JOIN users u ON o.created_by_user_id = u.id";

        if (!$is_admin && $is_active_filter) {
            $query .= " WHERE o.is_active = TRUE";
        } elseif ($is_admin && !$is_active_filter) {
            // show all for admin if is_active_filter is false (e.g. an admin view for all records)
             // No additional WHERE clause needed for is_active
        } elseif ($is_active_filter) { // Default for non-admin or admin wanting only active
             $query .= " WHERE o.is_active = TRUE";
        }
        // If $is_active_filter is false and user is not admin, this implies they should not see inactive.
        // This logic ensures non-admins *always* see only active, unless explicitly told otherwise (which is not the case here).
        // If admin and $is_active_filter is true, they see active. If $is_active_filter is false, they see all.

        $query .= " ORDER BY o.name ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Read a single organisation by ID
    // Admins can see inactive ones, others only see active ones.
    public function readOne($id, $is_admin = false) {
        $query = "SELECT o.*, u.username as created_by_username
                  FROM {$this->table_name} o
                  JOIN users u ON o.created_by_user_id = u.id
                  WHERE o.id = :id";

        if (!$is_admin) {
            $query .= " AND o.is_active = TRUE";
        }
        $query .= " LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $organisation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($organisation) {
            return ['success' => true, 'data' => $organisation];
        } else {
            // Check if it exists but is inactive for non-admins
            if (!$is_admin) {
                $stmt_check_inactive = $this->pdo->prepare("SELECT id FROM {$this->table_name} WHERE id = :id AND is_active = FALSE LIMIT 1");
                $stmt_check_inactive->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_inactive->execute();
                if ($stmt_check_inactive->fetch()) {
                    return ['success' => false, 'message' => 'Organisation found but is inactive. Access restricted.'];
                }
            }
            return ['success' => false, 'message' => 'Organisation not found or access denied.'];
        }
    }

    // Update organisation details
    public function update($id, $name, $website, $phone, $address_street, $address_city, $address_state, $address_zip, $address_country, $description, $industry, $annual_revenue, $current_version, $user_id_making_change) {

        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_org = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_org) {
            return ['success' => false, 'message' => 'Organisation not found.'];
        }
        if ((int)$db_org['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The organisation data has been modified by someone else. Please refresh and try again.'];
        }

        $new_version = (int)$current_version + 1;

        $query = "UPDATE {$this->table_name} SET
                    name = :name,
                    website = :website,
                    phone = :phone,
                    address_street = :address_street,
                    address_city = :address_city,
                    address_state = :address_state,
                    address_zip = :address_zip,
                    address_country = :address_country,
                    description = :description,
                    industry = :industry,
                    annual_revenue = :annual_revenue,
                    version = :version,
                    updated_at = NOW()
                  WHERE id = :id AND version = :current_version";

        $stmt = $this->pdo->prepare($query);

        $name = htmlspecialchars(strip_tags($name));
        $website = filter_var($website, FILTER_SANITIZE_URL) ?: null;
        $phone = htmlspecialchars(strip_tags($phone)) ?: null;
        $address_street = htmlspecialchars(strip_tags($address_street)) ?: null;
        $address_city = htmlspecialchars(strip_tags($address_city)) ?: null;
        $address_state = htmlspecialchars(strip_tags($address_state)) ?: null;
        $address_zip = htmlspecialchars(strip_tags($address_zip)) ?: null;
        $address_country = htmlspecialchars(strip_tags($address_country)) ?: null;
        $description = htmlspecialchars(strip_tags($description)) ?: null;
        $industry = htmlspecialchars(strip_tags($industry)) ?: null;
        $annual_revenue = filter_var($annual_revenue, FILTER_VALIDATE_FLOAT, ['flags' => FILTER_NULL_ON_FAILURE]) ?: null;

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':website', $website);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':address_street', $address_street);
        $stmt->bindParam(':address_city', $address_city);
        $stmt->bindParam(':address_state', $address_state);
        $stmt->bindParam(':address_zip', $address_zip);
        $stmt->bindParam(':address_country', $address_country);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':industry', $industry);
        $stmt->bindParam(':annual_revenue', $annual_revenue);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Organisation updated successfully.'];
            } else {
                 // Re-check version, as rowCount() can be 0 if data is identical.
                $stmt_check_again = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $stmt_check_again->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_check_again->execute();
                $updated_org = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
                if ($updated_org && (int)$updated_org['version'] === $new_version) {
                     return ['success' => true, 'message' => 'Organisation data was already up to date or updated successfully.'];
                }
                return ['success' => false, 'message' => 'Failed to update organisation or data conflict. Ensure data was changed or refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Organisation update failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during organisation update: ' . $e->getMessage()];
        }
    }

    // Soft delete (activate/deactivate) an organisation
    // Only admins can perform this as per "Admins have full control, including soft/hard deletes".
    // Standard users can view/edit active data, implying they cannot soft delete.
    public function setActiveStatus($id, $is_active, $current_version, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            // log_message('WARNING', "Non-admin user ID {$user_id_making_change} attempted to change active status for organisation ID {$id}.");
            return ['success' => false, 'message' => 'Permission denied. Only administrators can change organisation active status.'];
        }

        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_org = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_org) {
            return ['success' => false, 'message' => 'Organisation not found.'];
        }
        if ((int)$db_org['version'] !== (int)$current_version) {
            return ['success' => false, 'message' => 'Data conflict. The organisation data has been modified. Please refresh.'];
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
                // log_message('INFO', "Organisation ID {$id} {$action} by admin ID {$user_id_making_change}.");
                return ['success' => true, 'message' => "Organisation {$action} successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update organisation status or data conflict. Please refresh.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Organisation status change failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            return ['success' => false, 'message' => 'Database error during organisation status update: ' . $e->getMessage()];
        }
    }

    // Hard delete (for admins only) - as per "soft/hard deletes, and access to all data (active/inactive)" for admins
    public function hardDelete($id, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            // log_message('WARNING', "Non-admin user ID {$user_id_making_change} attempted hard delete for organisation ID {$id}.");
            return ['success' => false, 'message' => 'Permission denied. Only administrators can hard delete organisations.'];
        }

        // Consider implications: related contacts, leads, deals might be affected by ON DELETE constraints or require manual handling.
        // The schema for contacts.organisation_id is ON DELETE SET NULL.
        // The schema for leads.organisation_id is ON DELETE SET NULL.
        // The schema for deals.organisation_id is ON DELETE RESTRICT. This will prevent deletion if deals exist.
        // Application logic should check for related deals before allowing hard delete.

        // Check for related deals
        $stmt_deals = $this->pdo->prepare("SELECT COUNT(*) as deal_count FROM deals WHERE organisation_id = :id AND is_active = TRUE");
        $stmt_deals->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_deals->execute();
        $deal_count = $stmt_deals->fetchColumn();

        if ($deal_count > 0) {
            return ['success' => false, 'message' => "Cannot hard delete organisation. There are {$deal_count} active deal(s) associated with it. Please reassign or delete them first."];
        }

        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    // log_message('ALERT', "Organisation ID {$id} HARD DELETED by admin ID {$user_id_making_change}.");
                    return ['success' => true, 'message' => 'Organisation hard deleted successfully.'];
                } else {
                    return ['success' => false, 'message' => 'Organisation not found or already deleted.'];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to hard delete organisation.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Organisation hard delete failed for ID {$id}: " . $e->getMessage(), $user_id_making_change);
            if ($e->getCode() == '23000') { // Integrity constraint violation
                 return ['success' => false, 'message' => 'Cannot hard delete organisation due to existing related records (e.g., deals). Please check and remove dependencies. PDO Error: ' . $e->getMessage()];
            }
            return ['success' => false, 'message' => 'Database error during organisation hard delete: ' . $e->getMessage()];
        }
    }
}
?>
