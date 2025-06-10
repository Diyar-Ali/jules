<?php
require_once __DIR__ . '/lib/simpletest/autorun.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Lead.php'; // For relating activities
require_once __DIR__ . '/../../classes/Activity.php';
require_once __DIR__ . '/config.test.php';

class ActivityTest extends UnitTestCase {
    private $pdo;
    private $test_user_id;
    private $test_lead_id;

    // Path to the actual upload directory used by Activity class
    private $activity_upload_dir = Activity::UPLOAD_DIR; // Get it from the class constant

    function setUp() {
        reset_test_database();
        $this->pdo = get_test_pdo();
        $this->test_user_id = 1; // Admin user from reset_test_database

        // Create a Lead to relate activities to
        $lead = new Lead($this->pdo);
        $lead->name = "Test Lead for Activities";
        // Minimal fields for Lead creation for this test context
        $lead->organisation_id = null; // Assuming Lead can be created without org for tests if schema allows
        $this->assertTrue($lead->create($this->test_user_id), "Setup: Failed to create test lead for activity tests.");
        $this->test_lead_id = $lead->id;

        // Ensure the actual upload directory exists and is writable for tests
        if (!is_dir($this->activity_upload_dir)) {
            if (!mkdir($this->activity_upload_dir, 0777, true)) {
                $this->fail("Failed to create activity upload directory: {$this->activity_upload_dir}");
            }
        }
        if (!is_writable($this->activity_upload_dir)) {
            $this->fail("Activity upload directory is not writable: {$this->activity_upload_dir}");
        }

        if (session_status() == PHP_SESSION_NONE) {
            // @session_start(); // Generally avoid in CLI tests if possible
        }
        $_SESSION = ['user_id' => $this->test_user_id, 'is_admin' => true];
        $_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost/crm_app/public';
    }

    function tearDown() {
        $this->pdo = null;
        $_SESSION = [];
        // General cleanup of any test files left in activity_upload_dir can be done here
        // For more targeted cleanup, each test should remove files it creates, especially if names are unique.
        // Example: array_map('unlink', glob("{$this->activity_upload_dir}/test_file_*.*"));
    }

    /**
     * Helper to create a dummy temporary file and return its path.
     * This file is meant to be used as $_FILES['attachments']['tmp_name'].
     */
    private function createTemporaryUploadedFile($prefix = 'test_upload_', $content = "test data") {
        $tmp_path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($tmp_path, $content);
        return $tmp_path;
    }

    function testActivityCreateWithFileUpload() {
        $activity = new Activity($this->pdo);
        $activity->subject = "Activity with File Upload";
        $activity->type = "Task";
        $activity->assigned_to_user_id = $this->test_user_id;
        $activity->related_to_type = "Lead";
        $activity->related_to_id = $this->test_lead_id;

        $tmp_file1 = $this->createTemporaryUploadedFile('file1_', "Test content 1");
        $tmp_file2 = $this->createTemporaryUploadedFile('file2_', "Test image data");

        $simulated_files_array = [
            'name' => ['test_doc1.txt', 'test_image1.jpg'],
            'type' => ['text/plain', 'image/jpeg'],
            'tmp_name' => [$tmp_file1, $tmp_file2],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [strlen("Test content 1"), strlen("Test image data")]
        ];

        $this->assertTrue($activity->create($this->test_user_id, $simulated_files_array), "Activity creation with files failed.");
        $this->assertNotNull($activity->id, "Activity ID not set after creation.");

        $readActivity = new Activity($this->pdo);
        $this->assertTrue($readActivity->read($activity->id), "Failed to read activity after creation.");
        $files_metadata = json_decode($readActivity->files_json, true);

        $this->assertEqual(count($files_metadata), 2, "Incorrect number of files in metadata.");
        $this->assertEqual($files_metadata[0]['name'], 'test_doc1.txt');
        $this->assertTrue(file_exists($this->activity_upload_dir . $files_metadata[0]['path']), "Physical file for test_doc1.txt not found at {$this->activity_upload_dir}{$files_metadata[0]['path']}.");
        $this->assertEqual($files_metadata[1]['name'], 'test_image1.jpg');
        $this->assertTrue(file_exists($this->activity_upload_dir . $files_metadata[1]['path']), "Physical file for test_image1.jpg not found.");

        // Clean up physical files created by this test
        if(isset($files_metadata[0]['path'])) @unlink($this->activity_upload_dir . $files_metadata[0]['path']);
        if(isset($files_metadata[1]['path'])) @unlink($this->activity_upload_dir . $files_metadata[1]['path']);
        @unlink($tmp_file1);
        @unlink($tmp_file2);
    }

    function testActivityUpdateAddAndRemoveFiles() {
        // 1. Create activity with one initial file
        $activity = new Activity($this->pdo);
        $activity->subject = "Activity File Management Update Test";
        $activity->type = "Email";
        $activity->assigned_to_user_id = $this->test_user_id;
        $activity->related_to_type = "Lead";
        $activity->related_to_id = $this->test_lead_id;

        $initial_tmp_file = $this->createTemporaryUploadedFile('init_doc_', "Initial file content");
        $initial_files_arr = [
            'name' => ['initial_doc.txt'], 'type' => ['text/plain'],
            'tmp_name' => [$initial_tmp_file], 'error' => [UPLOAD_ERR_OK], 'size' => [100]
        ];
        $this->assertTrue($activity->create($this->test_user_id, $initial_files_arr), "Setup: Create activity with initial file failed.");
        $activity_id = $activity->id;

        $initial_metadata = json_decode($activity->files_json, true);
        $this->assertEqual(count($initial_metadata), 1, "Should have 1 initial file.");
        $file1_path_on_server = $initial_metadata[0]['path'];
        $this->assertTrue(file_exists($this->activity_upload_dir . $file1_path_on_server), "Initial file not found on server.");

        // 2. Update: remove initial_doc.txt, add new_doc.txt
        $activityToUpdate = new Activity($this->pdo);
        $this->assertTrue($activityToUpdate->read($activity_id), "Failed to read activity for update.");

        $new_tmp_file = $this->createTemporaryUploadedFile('new_doc_', "New file content");
        $new_files_arr_for_upload = [ // This is the structure for $_FILES['attachments']
            'name' => ['new_doc.txt'], 'type' => ['text/plain'],
            'tmp_name' => [$new_tmp_file], 'error' => [UPLOAD_ERR_OK], 'size' => [150]
        ];
        $files_to_remove_paths = [$file1_path_on_server]; // Path of file to remove

        $this->assertTrue($activityToUpdate->update($new_files_arr_for_upload, $files_to_remove_paths), "Activity update with file changes failed.");

        $updatedActivity = new Activity($this->pdo);
        $this->assertTrue($updatedActivity->read($activity_id), "Failed to read activity after update.");
        $updated_metadata = json_decode($updatedActivity->files_json, true);

        $this->assertEqual(count($updated_metadata), 1, "Should be 1 file after update. Found: " . count($updated_metadata) . " Files: " . print_r($updated_metadata, true));
        $this->assertEqual($updated_metadata[0]['name'], 'new_doc.txt', "New file name mismatch.");
        $this->assertTrue(file_exists($this->activity_upload_dir . $updated_metadata[0]['path']), "new_doc.txt physical file not found.");
        $this->assertFalse(file_exists($this->activity_upload_dir . $file1_path_on_server), "initial_doc.txt should have been physically deleted.");

        // Clean up
        if(isset($updated_metadata[0]['path']) && file_exists($this->activity_upload_dir . $updated_metadata[0]['path'])) {
             @unlink($this->activity_upload_dir . $updated_metadata[0]['path']);
        }
        @unlink($initial_tmp_file);
        @unlink($new_tmp_file);
    }

    function testHardDeleteActivityWithFiles() {
        $activity = new Activity($this->pdo);
        $activity->subject = "To Be Deleted with File";
        $activity->type = "Task"; $activity->assigned_to_user_id = $this->test_user_id;
        $activity->related_to_type = "Lead"; $activity->related_to_id = $this->test_lead_id;

        $tmp_file_del = $this->createTemporaryUploadedFile('del_me_', "Content to be deleted");
        $files_for_delete_test = [
            'name' => ['delete_me.txt'], 'type' => ['text/plain'],
            'tmp_name' => [$tmp_file_del], 'error' => [UPLOAD_ERR_OK], 'size' => [50]
        ];
        $this->assertTrue($activity->create($this->test_user_id, $files_for_delete_test), "Setup: Failed to create activity for delete test.");
        $activity_id_del = $activity->id;

        $metadata_del = json_decode($activity->files_json, true);
        $this->assertTrue(count($metadata_del) > 0, "No file metadata found after creation for delete test.");
        $file_to_delete_path_on_server = $this->activity_upload_dir . $metadata_del[0]['path'];

        $this->assertTrue(file_exists($file_to_delete_path_on_server), "File to be deleted does not exist at {$file_to_delete_path_on_server} before hard delete.");

        $this->assertTrue(Activity::hardDelete($this->pdo, $activity_id_del), "Hard delete of activity failed.");

        $checkActivity = new Activity($this->pdo);
        $this->assertFalse($checkActivity->read($activity_id_del), "Activity record should not exist after hard delete.");
        $this->assertFalse(file_exists($file_to_delete_path_on_server), "Physical file should be deleted after activity hard delete.");
        @unlink($tmp_file_del); // Clean up original tmp file if it wasn't moved (it should have been)
    }

    function testReadAllActivitiesWithFilters() {
        // Setup: Create a diverse set of activities
        $user1 = $this->test_user_id; // Admin user (ID 1)

        $user2_obj = new User($this->pdo);
        $user2_obj->username = 'activityuser2'; $user2_obj->setPassword('pass'); $user2_obj->email = 'actuser2@example.com';
        $user2_obj->role = 'viewer'; // A valid role
        $this->assertTrue($user2_obj->create(), "Failed to create user2 for activity tests");
        $user2 = $user2_obj->id;

        $lead2_obj = new Lead($this->pdo);
        $lead2_obj->name = "Second Lead for Activity Filter";
        // $lead2_obj->organisation_id = $this->test_org_id; // Assuming test_org_id is set up if Lead requires it
        $this->assertTrue($lead2_obj->create($user1), "Failed to create lead2 for activity tests");
        $lead2_id = $lead2_obj->id;

        $activities_to_create = [
            // Activity 1: Task, Pending, User1, Lead1 (this->test_lead_id), Due Tomorrow, Active
            ['subject' => 'A1 - Task Pending U1 L1 DueTmrw', 'type' => 'Task', 'status' => 'Pending', 'assigned_to_user_id' => $user1, 'related_to_type' => 'Lead', 'related_to_id' => $this->test_lead_id, 'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')), 'is_active' => true],
            // Activity 2: Call, Completed, User2, Lead2, Due Yesterday, Active
            ['subject' => 'A2 - Call Completed U2 L2 DueYstd', 'type' => 'Call', 'status' => 'Completed', 'assigned_to_user_id' => $user2, 'related_to_type' => 'Lead', 'related_to_id' => $lead2_id, 'due_date' => date('Y-m-d H:i:s', strtotime('-1 day')), 'is_active' => true],
            // Activity 3: Meeting, Pending, User1, Lead1 (this->test_lead_id), Due Next Week, Inactive (Admin only view)
            ['subject' => 'A3 - Meeting Pending U1 L1 DueNxtWk Inactive', 'type' => 'Meeting', 'status' => 'Pending', 'assigned_to_user_id' => $user1, 'related_to_type' => 'Lead', 'related_to_id' => $this->test_lead_id, 'due_date' => date('Y-m-d H:i:s', strtotime('+7 day')), 'is_active' => false],
            // Activity 4: Email, Cancelled, User2, Lead2, No Due Date, Active
            ['subject' => 'A4 - Email Cancelled U2 L2 NoDue', 'type' => 'Email', 'status' => 'Cancelled', 'assigned_to_user_id' => $user2, 'related_to_type' => 'Lead', 'related_to_id' => $lead2_id, 'due_date' => null, 'is_active' => true],
        ];

        $created_activity_ids = [];
        foreach ($activities_to_create as $act_data) {
            $activity = new Activity($this->pdo);
            foreach ($act_data as $key => $value) {
                $activity->$key = $value;
            }
            $this->assertTrue($activity->create($user1), "Failed to create activity: {$act_data['subject']}");
            $created_activity_ids[] = $activity->id;
        }

        // Test Scenarios
        // 1. Default view (non-admin, should see active - 3 activities: A1, A2, A4)
        $_SESSION['is_admin'] = false;
        $results = Activity::readAll($this->pdo);
        $this->assertEqual(count($results), 3, "Default non-admin view failed. Expected 3, got " . count($results) . $this->getSubjects($results));
        foreach($results as $r) { $this->assertTrue($r->is_active); }

        // 2. Admin view all (should see 4 activities)
        $_SESSION['is_admin'] = true;
        $results = Activity::readAll($this->pdo, ['view' => 'all']);
        $this->assertEqual(count($results), 4, "Admin view='all' failed. Expected 4, got " . count($results) . $this->getSubjects($results));

        // 3. Filter by type 'Task' (Admin view all context)
        $results = Activity::readAll($this->pdo, ['type' => 'Task', 'view' => 'all']);
        $this->assertEqual(count($results), 1, "Filter by type 'Task' failed." . $this->getSubjects($results));
        if(count($results)==1) $this->assertEqual($results[0]->subject, 'A1 - Task Pending U1 L1 DueTmrw');

        // 4. Filter by status 'Completed' (Admin view all context)
        $results = Activity::readAll($this->pdo, ['status' => 'Completed', 'view' => 'all']);
        $this->assertEqual(count($results), 1, "Filter by status 'Completed' failed." . $this->getSubjects($results));
        if(count($results)==1) $this->assertEqual($results[0]->subject, 'A2 - Call Completed U2 L2 DueYstd');

        // 5. Filter by assigned_to_user_id = $user2 (Admin view all context)
        $results = Activity::readAll($this->pdo, ['assigned_to_user_id' => $user2, 'view' => 'all']);
        $this->assertEqual(count($results), 2, "Filter by assigned_to_user_id {$user2} failed. Expected 2, got " . count($results) . $this->getSubjects($results));

        // 6. Filter by related_to_type 'Lead' and related_to_id $this->test_lead_id (Admin view all)
        $results = Activity::readAll($this->pdo, ['related_to_type' => 'Lead', 'related_to_id' => $this->test_lead_id, 'view' => 'all']);
        $this->assertEqual(count($results), 2, "Filter by related entity (Lead ID {$this->test_lead_id}) failed. Expected 2, got " . count($results) . $this->getSubjects($results));

        // 7. Filter by due_date (Admin view all, active only for relevance of due date)
        $_SESSION['is_admin'] = true; // Ensure admin for 'view' => 'all' or explicit is_active
        $tomorrow_date_only = date('Y-m-d', strtotime('+1 day'));
        // Activity::readAll uses DATE(a.due_date) >= :due_date_from and DATE(a.due_date) <= :due_date_to
        // So, to get activities for a specific day, set start and end to that day.
        $results = Activity::readAll($this->pdo, ['due_date_from' => $tomorrow_date_only, 'due_date_to' => $tomorrow_date_only, 'is_active' => true]);
        $this->assertEqual(count($results), 1, "Filter by due_date (tomorrow, active only) failed. Expected 1, got " . count($results) . ". Date: {$tomorrow_date_only}" . $this->getSubjects($results));
        if(count($results) == 1) $this->assertEqual($results[0]->subject, 'A1 - Task Pending U1 L1 DueTmrw');

        // 8. Admin view: inactive only (A3)
        $_SESSION['is_admin'] = true;
        $results = Activity::readAll($this->pdo, ['is_active' => false]); // 'view' => 'all' is implied if is_active is set by admin
        $this->assertEqual(count($results), 1, "Admin view inactive only failed." . $this->getSubjects($results));
        if(count($results) == 1) $this->assertEqual($results[0]->subject, 'A3 - Meeting Pending U1 L1 DueNxtWk Inactive');

        // 9. Search term (Admin view all)
        $results = Activity::readAll($this->pdo, ['search_term' => 'Cancelled', 'view' => 'all']);
        $this->assertEqual(count($results), 1, "Search term 'Cancelled' failed." . $this->getSubjects($results));
        if(count($results) == 1) $this->assertEqual($results[0]->subject, 'A4 - Email Cancelled U2 L2 NoDue');
    }

    // Helper to get subjects for debugging
    private function getSubjects($activities) {
        if (empty($activities)) return " (No activities found)";
        $subjects = [];
        foreach ($activities as $act) {
            $subjects[] = $act->subject;
        }
        return " (Found: " . implode(", ", $subjects) . ")";
    }
}
?>
