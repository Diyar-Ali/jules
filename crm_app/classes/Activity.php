<?php
// crm_app/classes/Activity.php

class Activity {
    private $pdo;
    private $table_name = "activities";
    public static $upload_dir = __DIR__ . '/../uploads/activity_files/'; // Ensure this dir exists and is writable

    // Activity types and statuses - useful for validation and forms
    public static $types = ['Call', 'Meeting', 'Email', 'Task', 'Other'];
    public static $statuses = ['Pending', 'Completed', 'Cancelled'];
    public static $related_to_types = ['Lead', 'Deal', 'Organisation', 'Contact', 'User']; // User means related to a system user

    public function __construct($db) {
        $this->pdo = $db;
        // Create upload directory if it doesn't exist
        if (!file_exists(self::$upload_dir)) {
            mkdir(self::$upload_dir, 0775, true); // Permissions suitable for web server access
        }
    }

    // Validate if related_to_id exists for the given related_to_type
    private function validateRelatedEntity($related_to_type, $related_to_id) {
        if (empty($related_to_type) || empty($related_to_id)) return false;
        if (!in_array($related_to_type, self::$related_to_types)) return false;

        $table_map = [
            'Lead' => 'leads', 'Deal' => 'deals', 'Organisation' => 'organisations',
            'Contact' => 'contacts', 'User' => 'users'
        ];
        $target_table = $table_map[$related_to_type] ?? null;
        if (!$target_table) return false;

        $stmt = $this->pdo->prepare("SELECT id FROM `{$target_table}` WHERE id = :id AND is_active = TRUE LIMIT 1");
        // For users, is_active might be different or not present in the same way, adjust if needed
        // For now, assume 'users' table also has an 'is_active' or this check is primarily for CRM entities.
        // If relating to a user entity, ensure the user exists.
        if ($target_table === 'users') {
             $stmt = $this->pdo->prepare("SELECT id FROM `users` WHERE id = :id AND is_active = TRUE LIMIT 1");
        }

        $stmt->bindParam(':id', $related_to_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ? true : false;
    }

    // Handle file uploads
    // $uploaded_files is the relevant part of $_FILES array, e.g., $_FILES['activity_files']
    private function handleFileUploads($uploaded_files, $existing_files_json = '[]') {
        $uploaded_file_metadata = json_decode($existing_files_json, true) ?: [];
        if (empty($uploaded_files['name'][0])) { // No new files uploaded
            return $uploaded_file_metadata;
        }

        $file_count = count($uploaded_files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                $original_filename = basename($uploaded_files['name'][$i]);
                $tmp_name = $uploaded_files['tmp_name'][$i];

                // Sanitize filename and create a unique name
                $safe_filename = preg_replace("/[^a-zA-Z0-9._-]/", "_", $original_filename);
                $file_extension = pathinfo($safe_filename, PATHINFO_EXTENSION);
                $unique_filename_base = uniqid(pathinfo($safe_filename, PATHINFO_FILENAME) . '_', true);
                $unique_filename = $unique_filename_base . ($file_extension ? '.' . $file_extension : '');
                $destination = self::$upload_dir . $unique_filename;

                if (move_uploaded_file($tmp_name, $destination)) {
                    $uploaded_file_metadata[] = [
                        'name' => $original_filename, // Store original name for display
                        'path' => 'activity_files/' . $unique_filename, // Relative path for storage/retrieval
                        'size' => $uploaded_files['size'][$i],
                        'type' => $uploaded_files['type'][$i]
                    ];
                } else {
                    // Handle upload error, maybe log it or add to an error array
                    // For now, just skip failed uploads
                    // log_message('ERROR', "Failed to move uploaded file: {$original_filename}");
                }
            } elseif ($uploaded_files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                // Handle other upload errors
                // log_message('ERROR', "File upload error for {$uploaded_files['name'][$i]}: error code {$uploaded_files['error'][$i]}");
            }
        }
        return $uploaded_file_metadata;
    }


    // Create a new activity
    public function create($type, $subject, $description, $due_date, $status, $notes_content, $related_to_type, $related_to_id, $assigned_to_user_id, $created_by_user_id, $uploaded_files_array = null) {
        if (empty($type) || empty($subject) || empty($related_to_type) || empty($related_to_id) || empty($assigned_to_user_id) || empty($created_by_user_id)) {
            return ['success' => false, 'message' => 'Type, subject, related entity, assignee, and creator are required.'];
        }
        if (!in_array($type, self::$types)) return ['success' => false, 'message' => 'Invalid activity type.'];
        if (!in_array($status, self::$statuses)) $status = 'Pending'; // Default status
        if (!$this->validateRelatedEntity($related_to_type, $related_to_id)) {
            return ['success' => false, 'message' => "Invalid or inactive related {$related_to_type} ID: {$related_to_id}."];
        }

        $files_metadata = [];
        if ($uploaded_files_array) {
            $files_metadata = $this->handleFileUploads($uploaded_files_array);
        }
        $files_json = json_encode($files_metadata);


        $query = "INSERT INTO {$this->table_name}
                    (type, subject, description, due_date, status, notes_content, files_json, related_to_type, related_to_id, assigned_to_user_id, created_by_user_id, is_active, version, created_at, updated_at)
                  VALUES
                    (:type, :subject, :description, :due_date, :status, :notes_content, :files_json, :related_to_type, :related_to_id, :assigned_to_user_id, :created_by_user_id, TRUE, 1, NOW(), NOW())";
        $stmt = $this->pdo->prepare($query);

        $subject_clean = htmlspecialchars(strip_tags($subject));
        $description_clean = $description; // Allow HTML if rich text is intended (prompt: "rich text/HTML storage")
        $notes_content_clean = $notes_content; // Allow HTML
        $due_date_clean = !empty($due_date) ? date('Y-m-d H:i:s', strtotime($due_date)) : null;

        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':subject', $subject_clean);
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':due_date', $due_date_clean);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes_content', $notes_content_clean);
        $stmt->bindParam(':files_json', $files_json);
        $stmt->bindParam(':related_to_type', $related_to_type);
        $stmt->bindParam(':related_to_id', $related_to_id, PDO::PARAM_INT);
        $stmt->bindParam(':assigned_to_user_id', $assigned_to_user_id, PDO::PARAM_INT);
        $stmt->bindParam(':created_by_user_id', $created_by_user_id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'activity_id' => $this->pdo->lastInsertId(), 'message' => 'Activity created successfully.'];
            } else {
                return ['success' => false, 'message' => 'Failed to create activity.'];
            }
        } catch (PDOException $e) {
            // log_message('ERROR', "Activity creation failed: " . $e->getMessage(), $created_by_user_id);
            return ['success' => false, 'message' => 'Database error during activity creation: ' . $e->getMessage()];
        }
    }

    // Read all activities
    public function readAll($is_admin = false, $is_active_filter = true, $filters = []) {
        $sql_params = [];
        $query = "SELECT act.*,
                         u_assignee.username as assigned_user_username,
                         u_creator.username as created_by_username
                         -- Polymorphic related name needs to be fetched dynamically or use CASE statements if limited set
                  FROM {$this->table_name} act
                  JOIN users u_creator ON act.created_by_user_id = u_creator.id
                  JOIN users u_assignee ON act.assigned_to_user_id = u_assignee.id";

        $where_clauses = [];
        if (!$is_admin && $is_active_filter) {
            $where_clauses[] = "act.is_active = TRUE";
        } elseif ($is_admin && !$is_active_filter) { /* No active filter */ }
        elseif ($is_active_filter) { $where_clauses[] = "act.is_active = TRUE"; }

        if (!empty($filters['related_to_type']) && !empty($filters['related_to_id'])) {
            $where_clauses[] = "act.related_to_type = :filter_rel_type";
            $sql_params[':filter_rel_type'] = $filters['related_to_type'];
            $where_clauses[] = "act.related_to_id = :filter_rel_id";
            $sql_params[':filter_rel_id'] = $filters['related_to_id'];
        }
        if (!empty($filters['assigned_to_user_id'])) {
            $where_clauses[] = "act.assigned_to_user_id = :filter_assigned_id";
            $sql_params[':filter_assigned_id'] = $filters['assigned_to_user_id'];
        }
        if (!empty($filters['type'])) {
            $where_clauses[] = "act.type = :filter_type";
            $sql_params[':filter_type'] = $filters['type'];
        }
         if (!empty($filters['status'])) {
            $where_clauses[] = "act.status = :filter_status";
            $sql_params[':filter_status'] = $filters['status'];
        }

        if (!empty($where_clauses)) { $query .= " WHERE " . implode(" AND ", $where_clauses); }
        $query .= " ORDER BY act.due_date DESC, act.created_at DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($sql_params);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Post-process to get related entity names (can be slow for large sets, consider alternatives)
        foreach ($activities as $key => $activity) {
            $activities[$key]['related_entity_name'] = $this->getRelatedEntityName($activity['related_to_type'], $activity['related_to_id']);
            $activities[$key]['files_json'] = json_decode($activity['files_json'] ?: '[]', true); // Decode JSON for easier use
        }
        return $activities;
    }

    // Helper to get polymorphic related entity name
    public function getRelatedEntityName($type, $id) {
        if (empty($type) || empty($id)) return 'N/A';
        $table_map = [
            'Lead' => ['table' => 'leads', 'name_col' => 'name'],
            'Deal' => ['table' => 'deals', 'name_col' => 'name'],
            'Organisation' => ['table' => 'organisations', 'name_col' => 'name'],
            'Contact' => ['table' => 'contacts', 'name_col' => "CONCAT(first_name, ' ', last_name)"], // Needs alias
            'User' => ['table' => 'users', 'name_col' => 'username']
        ];
        if (!isset($table_map[$type])) return 'Invalid Type';

        $table = $table_map[$type]['table'];
        $name_col_expr = $table_map[$type]['name_col'];

        $name_col_alias = 'entity_name'; // Alias for expressions like CONCAT

        $stmt = $this->pdo->prepare("SELECT {$name_col_expr} as {$name_col_alias} FROM `{$table}` WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result[$name_col_alias] : 'Unknown/Inactive';
    }


    // Read a single activity by ID
    public function readOne($id, $is_admin = false) {
        $query = "SELECT act.*,
                         u_assignee.username as assigned_user_username,
                         u_creator.username as created_by_username
                  FROM {$this->table_name} act
                  JOIN users u_creator ON act.created_by_user_id = u_creator.id
                  JOIN users u_assignee ON act.assigned_to_user_id = u_assignee.id
                  WHERE act.id = :id";

        if (!$is_admin) { $query .= " AND act.is_active = TRUE"; }
        $query .= " LIMIT 1";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activity) {
            $activity['related_entity_name'] = $this->getRelatedEntityName($activity['related_to_type'], $activity['related_to_id']);
            $activity['files_json'] = json_decode($activity['files_json'] ?: '[]', true);
            return ['success' => true, 'data' => $activity];
        } else {
            // Check if inactive for non-admins
            return ['success' => false, 'message' => 'Activity not found or access denied.'];
        }
    }

    // Update activity details
    public function update($id, $type, $subject, $description, $due_date, $status, $notes_content, $related_to_type, $related_to_id, $assigned_to_user_id, $current_version, $user_id_making_change, $uploaded_files_array = null, $files_to_remove_json = '[]') {
        $stmt_fetch = $this->pdo->prepare("SELECT version, files_json FROM {$this->table_name} WHERE id = :id");
        $stmt_fetch->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_fetch->execute();
        $db_activity = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

        if (!$db_activity) return ['success' => false, 'message' => 'Activity not found.'];
        if ((int)$db_activity['version'] !== (int)$current_version) return ['success' => false, 'message' => 'Data conflict. Please refresh.'];

        if (!in_array($type, self::$types)) return ['success' => false, 'message' => 'Invalid activity type.'];
        if (!in_array($status, self::$statuses)) return ['success' => false, 'message' => 'Invalid activity status.'];
        if (!$this->validateRelatedEntity($related_to_type, $related_to_id)) {
             return ['success' => false, 'message' => "Invalid or inactive related {$related_to_type} ID: {$related_to_id}."];
        }

        // File handling: Remove marked files, then add new ones
        $current_files_metadata = json_decode($db_activity['files_json'] ?: '[]', true);
        $files_to_remove = json_decode($files_to_remove_json, true) ?: [];

        $updated_files_metadata = [];
        foreach ($current_files_metadata as $file_meta) {
            $should_remove = false;
            foreach ($files_to_remove as $file_to_remove_path) {
                if ($file_meta['path'] === $file_to_remove_path) {
                    $should_remove = true;
                    // Actually delete the file from server
                    $full_path = self::$upload_dir . basename($file_meta['path']); // Use basename to be safe
                    if (file_exists($full_path)) {
                        unlink($full_path);
                        // log_message('INFO', "Deleted file: {$full_path} for activity ID {$id}");
                    }
                    break;
                }
            }
            if (!$should_remove) {
                $updated_files_metadata[] = $file_meta;
            }
        }

        if ($uploaded_files_array) {
            $updated_files_metadata = $this->handleFileUploads($uploaded_files_array, json_encode($updated_files_metadata));
        }
        $files_json = json_encode($updated_files_metadata);

        $new_version = (int)$current_version + 1;
        $query = "UPDATE {$this->table_name} SET
                    type = :type, subject = :subject, description = :description, due_date = :due_date,
                    status = :status, notes_content = :notes_content, files_json = :files_json,
                    related_to_type = :related_to_type, related_to_id = :related_to_id,
                    assigned_to_user_id = :assigned_to_user_id, version = :version, updated_at = NOW()
                  WHERE id = :id AND version = :current_version";
        $stmt = $this->pdo->prepare($query);

        $subject_clean = htmlspecialchars(strip_tags($subject));
        $description_clean = $description;
        $notes_content_clean = $notes_content;
        $due_date_clean = !empty($due_date) ? date('Y-m-d H:i:s', strtotime($due_date)) : null;

        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':subject', $subject_clean);
        // ... (bind other params similar to create)
        $stmt->bindParam(':description', $description_clean);
        $stmt->bindParam(':due_date', $due_date_clean);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes_content', $notes_content_clean);
        $stmt->bindParam(':files_json', $files_json);
        $stmt->bindParam(':related_to_type', $related_to_type);
        $stmt->bindParam(':related_to_id', $related_to_id, PDO::PARAM_INT);
        $stmt->bindParam(':assigned_to_user_id', $assigned_to_user_id, PDO::PARAM_INT);
        $stmt->bindParam(':version', $new_version, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':current_version', $current_version, PDO::PARAM_INT);

        try {
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Activity updated successfully.'];
            } else {
                 // Check if version was bumped even if data was identical (due to file changes maybe)
                $check_stmt = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
                $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $check_stmt->execute();
                $refetched_activity = $check_stmt->fetch(PDO::FETCH_ASSOC);
                if ($refetched_activity && (int)$refetched_activity['version'] === $new_version) {
                    return ['success' => true, 'message' => 'Activity data was unchanged or updated successfully (file changes processed).'];
                }
                return ['success' => false, 'message' => 'Failed to update activity or data conflict.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Soft delete (activate/deactivate) an activity
    public function setActiveStatus($id, $is_active, $current_version, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
            return ['success' => false, 'message' => 'Permission denied. Only administrators can change activity active status.'];
        }
        // Standard optimistic locking and update logic...
        $stmt_version = $this->pdo->prepare("SELECT version FROM {$this->table_name} WHERE id = :id");
        $stmt_version->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_version->execute();
        $db_act = $stmt_version->fetch(PDO::FETCH_ASSOC);

        if (!$db_act) return ['success' => false, 'message' => 'Activity not found.'];
        if ((int)$db_act['version'] !== (int)$current_version) return ['success' => false, 'message' => 'Data conflict. Please refresh.'];

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
                return ['success' => true, 'message' => "Activity " . ($is_active_bool ? 'activated' : 'deactivated') . " successfully."];
            } else {
                return ['success' => false, 'message' => 'Failed to update activity status or data conflict.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Hard delete (Admin only) - also remove associated files
    public function hardDelete($id, $user_id_making_change, $is_admin_making_change) {
        if (!$is_admin_making_change) {
             return ['success' => false, 'message' => 'Permission denied. Only administrators can hard delete activities.'];
        }

        // Fetch file list before deleting DB record
        $stmt_files = $this->pdo->prepare("SELECT files_json FROM {$this->table_name} WHERE id = :id");
        $stmt_files->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_files->execute();
        $files_json = $stmt_files->fetchColumn();

        $query = "DELETE FROM {$this->table_name} WHERE id = :id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    // Delete associated files
                    if ($files_json) {
                        $files_metadata = json_decode($files_json, true);
                        foreach ($files_metadata as $file_meta) {
                            $full_path = self::$upload_dir . basename($file_meta['path']);
                             if (file_exists($full_path)) { unlink($full_path); }
                        }
                    }
                    // log_message('ALERT', "Activity ID {$id} HARD DELETED by admin ID {$user_id_making_change}. Associated files removed.");
                    return ['success' => true, 'message' => 'Activity hard deleted successfully.'];
                } else {
                    return ['success' => false, 'message' => 'Activity not found or already deleted.'];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to hard delete activity.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Helper to get lists for dropdowns
    public function getRelatedDataForForms($specific_related_type = null, $specific_related_id = null) {
        $data = [];
        $data['users'] = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
        $data['types'] = self::$types;
        $data['statuses'] = self::$statuses;
        $data['related_to_types'] = self::$related_to_types;

        // For populating "Related To ID" dropdown based on selected "Related To Type"
        // This is a simplified version; a real UI might use AJAX for this.
        // Here, we can pre-populate if type and ID are known (e.g., adding activity from a Lead's page)
        if ($specific_related_type && $specific_related_id) {
            $data['specific_related_entity'] = [
                'type' => $specific_related_type,
                'id' => $specific_related_id,
                'name' => $this->getRelatedEntityName($specific_related_type, $specific_related_id)
            ];
        }
        // For a generic form, one might fetch all active Leads, Deals, etc. This can be large.
        // For now, the form will need to handle how it presents these options.
        return $data;
    }
}
?>
