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
}
?>
