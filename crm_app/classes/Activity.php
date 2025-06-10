<?php
// crm_app/classes/Activity.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/log_helper.php';

class Activity {
    private $pdo;

    public $id;
    public $type; // ENUM('Call', 'Meeting', 'Email', 'Task', 'Other')
    public $subject;
    public $description; // General description, plain text
    public $due_date; // DATETIME
    public $status; // ENUM('Pending', 'Completed', 'Cancelled')
    public $notes_content; // TEXT, stores HTML
    public $files_json; // JSON, stores array of file metadata objects
    public $related_to_type; // ENUM('Lead', 'Deal', 'Organisation', 'Contact', 'User')
    public $related_to_id; // INT
    public $assigned_to_user_id; // Required FK to users
    public $created_by_user_id; // Required FK to users
    public $is_active;
    public $created_at;
    public $updated_at;
    public $version;

    // For display
    public $assigned_to_user_name;
    public $created_by_username;
    public $related_to_entity_name; // e.g., Name of the Lead, Deal, Org, Contact, or User

    const UPLOAD_DIR = __DIR__ . '/../uploads/activities/'; // More specific path

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->is_active = true;
        $this->version = 1;
        $this->status = 'Pending';
        $this->files_json = json_encode([]);
    }

    /**
     * Validates if the related_to_id exists for the given related_to_type.
     * @return bool True if valid, false otherwise.
     */
    private function isValidRelatedEntity() {
        if (empty($this->related_to_type) || empty($this->related_to_id)) return false;

        $table_map = [
            'Lead' => 'leads', 'Deal' => 'deals', 'Organisation' => 'organisations',
            'Contact' => 'contacts', 'User' => 'users'
        ];
        if (!isset($table_map[$this->related_to_type])) return false;

        $table_name = $table_map[$this->related_to_type];
        $sql = "SELECT id FROM `{$table_name}` WHERE id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $this->related_to_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            log_message('error', "Error validating related entity {$this->related_to_type} ID {$this->related_to_id}: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Create a new activity record.
     * @param int $creator_user_id
     * @param array $uploaded_files Raw $_FILES array for new attachments.
     * @return bool True on success.
     * @throws Exception on failure.
     */
    public function create($creator_user_id, $uploaded_files = []) {
        if (empty($this->subject) || empty($this->type) || empty($this->assigned_to_user_id)) {
            throw new InvalidArgumentException("Subject, type, and assigned user are required.");
        }
        if (empty($this->related_to_type) || empty($this->related_to_id)) {
            throw new InvalidArgumentException("Activity must be related to an entity (Lead, Deal, etc.).");
        }
        if (!$this->isValidRelatedEntity()) {
            throw new InvalidArgumentException("The related entity (type: {$this->related_to_type}, ID: {$this->related_to_id}) does not exist or is invalid.");
        }

        $this->created_by_user_id = $creator_user_id;
        if(!is_bool($this->is_active)){ // Ensure boolean before DB
             $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }
        $this->version = 1;

        $processed_files_metadata = []; // To hold metadata of successfully uploaded files
        try {
            // Handle file uploads and prepare files_json
            // For a new activity, current_files_metadata is empty.
            $processed_files_metadata = $this->processFileUploads($uploaded_files, []);
            $this->files_json = json_encode($processed_files_metadata);

            $sql = "INSERT INTO activities (type, subject, description, due_date, status, notes_content, files_json, related_to_type, related_to_id, assigned_to_user_id, created_by_user_id, is_active, version)
                    VALUES (:type, :subject, :description, :due_date, :status, :notes_content, :files_json, :related_to_type, :related_to_id, :assigned_to_user_id, :created_by_user_id, :is_active, :version)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':type', $this->type);
            $stmt->bindParam(':subject', $this->subject);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':due_date', $this->due_date);
            $stmt->bindParam(':status', $this->status);
            $stmt->bindParam(':notes_content', $this->notes_content);
            $stmt->bindParam(':files_json', $this->files_json);
            $stmt->bindParam(':related_to_type', $this->related_to_type);
            $stmt->bindParam(':related_to_id', $this->related_to_id, PDO::PARAM_INT);
            $stmt->bindParam(':assigned_to_user_id', $this->assigned_to_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':created_by_user_id', $this->created_by_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':version', $this->version, PDO::PARAM_INT);

            $stmt->execute();
            $this->id = $this->pdo->lastInsertId();
            $this->read($this->id);
            log_message('info', 'Activity created successfully.', ['activity_id' => $this->id, 'subject' => $this->subject]);
            return true;
        } catch (Exception $e) { // Catch PDOException or RuntimeException from uploads
            log_message('error', 'Error creating activity: ' . $e->getMessage(), ['subject' => $this->subject]);
            // If files were processed (moved) but DB insert failed, they are now orphaned. Delete them.
            if (!empty($processed_files_metadata)) {
                $this->deletePhysicalFiles(array_column($processed_files_metadata, 'path'));
            }
            throw $e; // Re-throw original exception
        }
    }

    /**
     * Read an activity record from the database by ID.
     * @param int $id
     * @return bool True on success.
     */
    public function read($id) {
        $sql = "SELECT a.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_to_user_name
                FROM activities a
                LEFT JOIN users u_creator ON a.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON a.assigned_to_user_id = u_assignee.id
                WHERE a.id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                $this->id = (int)$data['id'];
                $this->type = $data['type']; $this->subject = $data['subject'];
                $this->description = $data['description']; $this->due_date = $data['due_date'];
                $this->status = $data['status']; $this->notes_content = $data['notes_content'];
                $this->files_json = $data['files_json'];
                $this->related_to_type = $data['related_to_type'];
                $this->related_to_id = (int)$data['related_to_id'];
                $this->assigned_to_user_id = (int)$data['assigned_to_user_id'];
                $this->created_by_user_id = (int)$data['created_by_user_id'];
                $this->is_active = (bool)$data['is_active'];
                $this->created_at = $data['created_at']; $this->updated_at = $data['updated_at'];
                $this->version = (int)$data['version'];
                $this->assigned_to_user_name = $data['assigned_to_user_name'] ?? null;
                $this->created_by_username = $data['created_by_username'] ?? null;

                $this->related_to_entity_name = $this->getRelatedEntityName($this->related_to_type, $this->related_to_id);
                return true;
            }
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error reading activity: ' . $e->getMessage(), ['activity_id' => $id]);
            throw $e;
        }
    }

    /**
     * Update an existing activity record.
     * @param array $uploaded_files Raw $_FILES array for new attachments.
     * @param array $files_to_remove Array of file paths (relative to UPLOAD_DIR) to be removed.
     * @return bool True on success.
     * @throws Exception on failure.
     */
    public function update($uploaded_files = [], $files_to_remove = []) {
        if (empty($this->id) || $this->version === null) throw new InvalidArgumentException("Activity ID and version required for update. Please read first.");
        if (empty($this->subject) || empty($this->type) || empty($this->assigned_to_user_id)) {
            throw new InvalidArgumentException("Subject, type, and assigned user are required.");
        }
        if (empty($this->related_to_type) || empty($this->related_to_id)) {
            throw new InvalidArgumentException("Activity must be related to an entity.");
        }
        if (!$this->isValidRelatedEntity()) {
            throw new InvalidArgumentException("The related entity (type: {$this->related_to_type}, ID: {$this->related_to_id}) does not exist or is invalid.");
        }
        if(!is_bool($this->is_active)){
             $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        }

        $newly_processed_paths = []; // To track files uploaded in this update transaction
        try {
            // File Management
            $current_files_metadata = json_decode($this->files_json ?: '[]', true);
            $updated_files_metadata = [];
            $physically_deleted_paths_temp = [];

            foreach ($current_files_metadata as $file_meta) {
                if (in_array($file_meta['path'], $files_to_remove)) {
                    $physically_deleted_paths_temp[] = $file_meta['path'];
                } else {
                    $updated_files_metadata[] = $file_meta;
                }
            }
            // $this->deletePhysicalFiles($physically_deleted_paths_temp); // Defer physical deletion until after DB commit if possible, or handle rollback carefully

            // Process new uploads and add to metadata list that already had removals processed
            $final_files_metadata = $this->processFileUploads($uploaded_files, $updated_files_metadata);
            // Extract paths of newly added files for potential rollback
            foreach ($final_files_metadata as $meta) {
                $found = false;
                foreach ($updated_files_metadata as $existing_meta) {
                    if ($meta['path'] === $existing_meta['path']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $newly_processed_paths[] = $meta['path'];
                }
            }
            $this->files_json = json_encode($final_files_metadata);


            $sql = "UPDATE activities SET
                        type = :type, subject = :subject, description = :description, due_date = :due_date,
                        status = :status, notes_content = :notes_content, files_json = :files_json,
                        related_to_type = :related_to_type, related_to_id = :related_to_id,
                        assigned_to_user_id = :assigned_to_user_id, is_active = :is_active,
                        version = :new_version
                    WHERE id = :id AND version = :current_version";

            $new_version = $this->version + 1;
            $stmt = $this->pdo->prepare($sql);

            $stmt->bindParam(':type', $this->type); $stmt->bindParam(':subject', $this->subject);
            $stmt->bindParam(':description', $this->description); $stmt->bindParam(':due_date', $this->due_date);
            $stmt->bindParam(':status', $this->status); $stmt->bindParam(':notes_content', $this->notes_content);
            $stmt->bindParam(':files_json', $this->files_json);
            $stmt->bindParam(':related_to_type', $this->related_to_type);
            $stmt->bindParam(':related_to_id', $this->related_to_id, PDO::PARAM_INT);
            $stmt->bindParam(':assigned_to_user_id', $this->assigned_to_user_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_active', $this->is_active, PDO::PARAM_BOOL);
            $stmt->bindParam(':new_version', $new_version, PDO::PARAM_INT);
            $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':current_version', $this->version, PDO::PARAM_INT);

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->deletePhysicalFiles($physically_deleted_paths_temp); // Now commit physical file deletions
                $this->version = $new_version;
                $this->read($this->id);
                log_message('info', 'Activity updated successfully.', ['activity_id' => $this->id]);
                return true;
            } else {
                // If rowCount is 0, it might be an optimistic lock conflict or no data changed.
                // Re-check version from DB.
                $checker = new Activity($this->pdo);
                if ($checker->read($this->id) && $checker->version !== $this->version) { // Version is pre-increment here
                    $this->deletePhysicalFiles($newly_processed_paths); // Rollback new uploads if version conflict
                    throw new Exception("Update failed. The activity was modified by someone else. Please refresh.");
                }
                // If versions match, it means no data fields changed. But files might have changed.
                // If files_json was the only change, and it was updated to the same JSON string, rowCount might be 0.
                // This part needs careful consideration: if files_json changed but other fields didn't,
                // we should still consider it a success if the DB write for files_json was intended.
                // For simplicity, if rowCount is 0 and no version conflict, assume it's "no change needed" or files were same.
                // However, if files were processed and $this->files_json *did* change, this indicates an issue.
                // A more robust check would compare $this->files_json with the original $current_files_metadata (after removals).
                 $this->deletePhysicalFiles($newly_processed_paths); // If DB update failed, cleanup newly uploaded files.
                log_message('warning', 'Activity update no rows changed.', ['activity_id' => $this->id]);
                return true;
            }
        } catch (Exception $e) { // PDOException or RuntimeException from uploads
            log_message('error', 'Error updating activity: ' . $e->getMessage(), ['activity_id' => $this->id]);
            // Rollback any new file uploads if DB update failed
            $this->deletePhysicalFiles($newly_processed_paths);
            throw $e;
        }
    }

    public function setActiveStatus($isActive) {
        if (empty($this->id) || $this->version === null) {
             throw new InvalidArgumentException("Activity ID and version required for update. Please read first.");
        }
        $this->is_active = (bool)$isActive;
        // For setActiveStatus, we are not handling file uploads/deletions, so pass empty arrays
        return $this->update([], []);
    }

    public static function hardDelete(PDO $pdo, $id) {
        $activity_to_delete = new Activity($pdo);
        if (!$activity_to_delete->read($id)) {
            // Already logged by read() if it fails to find.
            // Or throw new Exception("Activity not found for deletion.");
            return false;
        }

        $files_metadata = json_decode($activity_to_delete->files_json ?: '[]', true);
        $paths_to_delete = array_column($files_metadata, 'path');
        // Physical file deletion should ideally be in a transaction with DB delete,
        // but that's complex. Delete files first, then DB record. If DB fails, files are gone.
        // Or delete DB first, then files. If file delete fails, record is gone but files remain (orphaned).
        // Current approach: delete files, then DB record.
        $activity_to_delete->deletePhysicalFiles($paths_to_delete);

        $sql = "DELETE FROM activities WHERE id = :id";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                log_message('info', 'Activity hard deleted successfully.', ['activity_id' => $id]);
                return true;
            }
            log_message('warning', 'Activity hard delete failed (activity not found in DB).', ['activity_id' => $id]);
            return false;
        } catch (PDOException $e) {
            log_message('error', 'Error hard deleting activity DB record: ' . $e->getMessage(), ['activity_id' => $id]);
            // Files might have been deleted. This state is inconsistent.
            // A more robust system might re-attempt file deletion or log for manual cleanup.
            throw $e;
        }
    }

    private function processFileUploads($files_array, $existing_metadata = []) {
        if (empty($files_array) || !isset($files_array['name']) || (is_array($files_array['name']) && empty($files_array['name'][0])) ) {
             return $existing_metadata; // No files submitted or empty array
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            if (!mkdir(self::UPLOAD_DIR, 0755, true)) { // Create recursive if doesn't exist
                throw new RuntimeException("Failed to create upload directory: " . self::UPLOAD_DIR);
            }
        }
        if (!is_writable(self::UPLOAD_DIR)) {
            throw new RuntimeException("Upload directory is not writable: " . self::UPLOAD_DIR);
        }

        $processed_metadata = $existing_metadata;

        if (is_array($files_array['name'])) { // Multiple files
            for ($i = 0; $i < count($files_array['name']); $i++) {
                if ($files_array['error'][$i] === UPLOAD_ERR_OK) {
                    $original_name = basename($files_array['name'][$i]);
                    $tmp_name = $files_array['tmp_name'][$i];
                    $file_size = $files_array['size'][$i];
                    $file_type = $files_array['type'][$i];

                    $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    $safe_original_name_part = preg_replace("/[^a-zA-Z0-9_.-]/", "_", pathinfo($original_name, PATHINFO_FILENAME));
                    // Include activity ID in filename if available (i.e., during update)
                    $activity_id_part = $this->id ? '_act' . $this->id . '_' : '_newact_';
                    $new_filename = uniqid('file_', true) . $activity_id_part . $safe_original_name_part . '.' . $file_extension;
                    $destination_path = self::UPLOAD_DIR . $new_filename;

                    if (move_uploaded_file($tmp_name, $destination_path)) {
                        $processed_metadata[] = [
                            'name' => $original_name, 'path' => $new_filename,
                            'size' => $file_size, 'type' => $file_type
                        ];
                        log_message('info', "File uploaded: {$original_name} to {$new_filename} for activity ID " . ($this->id ?? 'NEW'));
                    } else {
                        throw new RuntimeException("Failed to move uploaded file: {$original_name}");
                    }
                } elseif ($files_array['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException("File upload error for {$files_array['name'][$i]}: error code {$files_array['error'][$i]}");
                }
            }
        } else { // Single file
             if ($files_array['error'] === UPLOAD_ERR_OK) {
                $original_name = basename($files_array['name']);
                $tmp_name = $files_array['tmp_name'];
                $file_size = $files_array['size'];
                $file_type = $files_array['type'];
                $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $safe_original_name_part = preg_replace("/[^a-zA-Z0-9_.-]/", "_", pathinfo($original_name, PATHINFO_FILENAME));
                $activity_id_part = $this->id ? '_act' . $this->id . '_' : '_newact_';
                $new_filename = uniqid('file_', true) . $activity_id_part . $safe_original_name_part . '.' . $file_extension;
                $destination_path = self::UPLOAD_DIR . $new_filename;
                 if (move_uploaded_file($tmp_name, $destination_path)) {
                    $processed_metadata[] = [
                        'name' => $original_name, 'path' => $new_filename,
                        'size' => $file_size, 'type' => $file_type
                    ];
                    log_message('info', "File uploaded: {$original_name} to {$new_filename} for activity ID " . ($this->id ?? 'NEW'));
                } else {  throw new RuntimeException("Failed to move uploaded file: {$original_name}"); }
             } elseif ($files_array['error'] !== UPLOAD_ERR_NO_FILE) {
                 throw new RuntimeException("File upload error for {$files_array['name']}: error code {$files_array['error']}");
             }
        }
        return $processed_metadata;
    }

    private function deletePhysicalFiles($paths) {
        if (empty($paths)) return;
        foreach ($paths as $relative_path) {
            if (empty($relative_path)) continue; // Skip empty paths
            $full_path = self::UPLOAD_DIR . basename($relative_path);
            if (file_exists($full_path)) {
                if (is_writable($full_path)) { // Check if writable before attempting delete
                    if (unlink($full_path)) {
                        log_message('info', "Physically deleted file: {$full_path}");
                    } else {
                        log_message('error', "Failed to physically delete file (unlink failed): {$full_path}");
                    }
                } else {
                     log_message('error', "File not writable, cannot delete: {$full_path}");
                }
            } else {
                 log_message('warning', "File not found for physical deletion: {$full_path}");
            }
        }
    }

    public function getRelatedEntityName($type, $id) {
        if (empty($type) || empty($id)) return null;
        $table_map = [
            'Lead' => ['table' => 'leads', 'name_col' => 'name'],
            'Deal' => ['table' => 'deals', 'name_col' => 'name'],
            'Organisation' => ['table' => 'organisations', 'name_col' => 'name'],
            'Contact' => ['table' => 'contacts', 'name_col' => "CONCAT(first_name, ' ', last_name)"],
            'User' => ['table' => 'users', 'name_col' => 'username']
        ];
        if (!isset($table_map[$type])) return null;

        $table_info = $table_map[$type];
        $sql = "SELECT {$table_info['name_col']} as entity_name FROM `{$table_info['table']}` WHERE id = :id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['entity_name'] : null;
        } catch (PDOException $e) {
            log_message('error', "Error fetching related entity name ({$type} ID {$id}): " . $e->getMessage());
            return null;
        }
    }

    // --- Static helper methods & Data for Forms ---
    public static function getActivityTypeOptions() {
        return ['Call', 'Meeting', 'Email', 'Task', 'Other'];
    }
    public static function getActivityStatusOptions() {
        return ['Pending', 'Completed', 'Cancelled'];
    }
    public static function getRelatedToTypeOptions() {
        return ['Lead', 'Deal', 'Organisation', 'Contact', 'User'];
    }

    public function getRelatedDataForForms() {
        $data = [
            'users' => [], // For assigned_to_user_id
            'leads' => [], 'deals' => [], 'organisations' => [], 'contacts' => [] // For related_to_id
        ];
        try {
            // Fetch active users
            $stmt_users = $this->pdo->query("SELECT id, username, first_name, last_name FROM users WHERE is_active = TRUE ORDER BY username ASC");
            while ($row = $stmt_users->fetch(PDO::FETCH_ASSOC)) {
                $data['users'][] = ['id' => $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['username']];
            }
            // Fetch active leads
            $stmt_leads = $this->pdo->query("SELECT id, name FROM leads WHERE is_active = TRUE ORDER BY name ASC");
            $data['leads'] = $stmt_leads->fetchAll(PDO::FETCH_ASSOC);
            // Fetch active deals
            $stmt_deals = $this->pdo->query("SELECT id, name FROM deals WHERE is_active = TRUE ORDER BY name ASC");
            $data['deals'] = $stmt_deals->fetchAll(PDO::FETCH_ASSOC);
            // Fetch active organisations
            $stmt_orgs = $this->pdo->query("SELECT id, name FROM organisations WHERE is_active = TRUE ORDER BY name ASC");
            $data['organisations'] = $stmt_orgs->fetchAll(PDO::FETCH_ASSOC);
            // Fetch active contacts
            $stmt_contacts = $this->pdo->query("SELECT id, first_name, last_name FROM contacts WHERE is_active = TRUE ORDER BY last_name ASC, first_name ASC");
            while ($row = $stmt_contacts->fetch(PDO::FETCH_ASSOC)) {
                $data['contacts'][] = ['id' => $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
            }
        } catch (PDOException $e) {
            log_message('error', 'Error fetching related data for activity forms: ' . $e->getMessage());
        }
        return $data;
    }

    public static function readAll(PDO $pdo, $filters = []) {
        // Basic structure, can be expanded like other readAll methods
        $sql = "SELECT a.*,
                       u_creator.username as created_by_username,
                       u_assignee.username as assigned_to_user_name
                FROM activities a
                LEFT JOIN users u_creator ON a.created_by_user_id = u_creator.id
                LEFT JOIN users u_assignee ON a.assigned_to_user_id = u_assignee.id";

        $where_clauses = [];
        $params = [];

        // Visibility (is_active)
        $is_admin_viewing_all = (isset($filters['view']) && $filters['view'] === 'all' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);
        if (!$is_admin_viewing_all) {
            if (isset($filters['is_active'])) {
                $where_clauses[] = "a.is_active = :is_active"; $params[':is_active'] = (bool)$filters['is_active'];
            } else { $where_clauses[] = "a.is_active = TRUE"; }
        } elseif (isset($filters['is_active'])) {
             $where_clauses[] = "a.is_active = :is_active"; $params[':is_active'] = (bool)$filters['is_active'];
        }

        if (!empty($filters['search_term'])) {
            $s = '%' . $filters['search_term'] . '%';
            $where_clauses[] = "(a.subject LIKE :s OR a.description LIKE :s OR a.notes_content LIKE :s)";
            $params[':s'] = $s;
        }
        if (!empty($filters['type'])) {
            $where_clauses[] = "a.type = :type"; $params[':type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where_clauses[] = "a.status = :status"; $params[':status'] = $filters['status'];
        }
        if (!empty($filters['assigned_to_user_id'])) {
            $where_clauses[] = "a.assigned_to_user_id = :assigned_user_id"; $params[':assigned_user_id'] = $filters['assigned_to_user_id'];
        }
        if (!empty($filters['related_to_type']) && !empty($filters['related_to_id'])) {
            $where_clauses[] = "a.related_to_type = :related_to_type AND a.related_to_id = :related_to_id";
            $params[':related_to_type'] = $filters['related_to_type'];
            $params[':related_to_id'] = $filters['related_to_id'];
        }
        // Date range filters (e.g., for due_date) can be added here
        if (!empty($filters['due_date_from'])) {
             $where_clauses[] = "a.due_date >= :due_date_from"; $params[':due_date_from'] = $filters['due_date_from'];
        }
         if (!empty($filters['due_date_to'])) {
             $where_clauses[] = "a.due_date <= :due_date_to"; $params[':due_date_to'] = $filters['due_date_to'];
        }


        if (!empty($where_clauses)) $sql .= " WHERE " . implode(" AND ", $where_clauses);
        $sql .= " ORDER BY a.due_date ASC, a.updated_at DESC"; // Order by due date, then recent updates

        $activities = [];
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $data) {
                $activity = new Activity($pdo);
                $activity->id = (int)$data['id'];
                $activity->type = $data['type']; $activity->subject = $data['subject'];
                $activity->description = $data['description']; $activity->due_date = $data['due_date'];
                $activity->status = $data['status']; $activity->notes_content = $data['notes_content'];
                $activity->files_json = $data['files_json'];
                $activity->related_to_type = $data['related_to_type'];
                $activity->related_to_id = (int)$data['related_to_id'];
                $activity->assigned_to_user_id = (int)$data['assigned_to_user_id'];
                $activity->created_by_user_id = (int)$data['created_by_user_id'];
                $activity->is_active = (bool)$data['is_active'];
                $activity->created_at = $data['created_at']; $activity->updated_at = $data['updated_at'];
                $activity->version = (int)$data['version'];
                $activity->assigned_to_user_name = $data['assigned_to_user_name'] ?? null;
                $activity->created_by_username = $data['created_by_username'] ?? null;
                $activity->related_to_entity_name = $activity->getRelatedEntityName($activity->related_to_type, $activity->related_to_id);
                $activities[] = $activity;
            }
        } catch (PDOException $e) {
            log_message('error', 'Error reading all activities: ' . $e->getMessage(), ['filters' => $filters]);
        }
        return $activities;
    }

}
?>
