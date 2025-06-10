<?php
require_once __DIR__ . '/lib/simpletest/autorun.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Organisation.php';
require_once __DIR__ . '/../../classes/Contact.php';
require_once __DIR__ . '/config.test.php';

class ContactTest extends UnitTestCase {
    private $pdo;
    private $test_user_id;
    private $test_org_id;

    function setUp() {
        reset_test_database();
        $this->pdo = get_test_pdo();

        // Default user (from reset_test_database, which seeds user ID 1 as testadmin)
        $this->test_user_id = 1;

        // Default organisation
        $org = new Organisation($this->pdo);
        $org->name = "Test Org for Contacts";
        // Need to set all required fields for Organisation based on its class for successful creation
        $org->phone = "123456789"; // Example, assuming phone is required by class/db
        $org->address_street = "1 Test St";
        $org->address_city = "Test City";
        $org->address_state = "TS";
        $org->address_zip = "12345";
        $org->address_country = "Testland";
        $org->industry = "Testing";
        $org->annual_revenue = 100000;
        // is_active and version are defaulted by Organisation constructor
        $this->assertTrue($org->create($this->test_user_id), "Setup: Failed to create test organisation.");
        $this->test_org_id = $org->id;

        // Mock session
        if (session_status() == PHP_SESSION_NONE) {
            // @session_start(); // Avoid if CLI and not strictly needed by classes
        }
        $_SESSION = ['user_id' => $this->test_user_id, 'is_admin' => true];
        $_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost/crm_app/public';

    }

    function tearDown() {
        $this->pdo = null; $_SESSION = [];
    }

    function testContactCreateAndRead() {
        $contact = new Contact($this->pdo);
        $contact->first_name = "John";
        $contact->last_name = "Doe";
        $contact->email = "john.doe@example.com";
        $contact->phone_mobile = "111-222-3333";
        $contact->phone_work = "444-555-6666";
        $contact->title = "Tester";
        $contact->organisation_id = $this->test_org_id;
        // is_active and version are defaulted by Contact constructor

        $this->assertTrue($contact->create($this->test_user_id), "Contact creation failed. Error: " . ($this->pdo->errorInfo()[2] ?? 'N/A'));
        $this->assertNotNull($contact->id, "Contact ID not set.");

        $readContact = new Contact($this->pdo);
        $this->assertTrue($readContact->read($contact->id), "Failed to read contact.");
        $this->assertEqual($readContact->email, "john.doe@example.com");
        $this->assertEqual($readContact->organisation_id, $this->test_org_id);
        $this->assertEqual($readContact->version, 1, "Version mismatch on create. Expected 1, got {$readContact->version}");
        $this->assertTrue($readContact->is_active, "Contact should be active by default.");
    }

    function testContactEmailValidationAndUniqueness() {
        $contact1 = new Contact($this->pdo);
        $contact1->first_name = "Jane"; $contact1->last_name = "Doe";
        $contact1->email = "jane.doe@example.com";
        $contact1->organisation_id = $this->test_org_id;
        $this->assertTrue($contact1->create($this->test_user_id), "Creation with valid email failed.");
        $contact1_id = $contact1->id;

        $contactInvalid = new Contact($this->pdo);
        $contactInvalid->first_name = "Bad"; $contactInvalid->last_name = "Email";
        $contactInvalid->email = "notanemail";
        $this->expectException(new InvalidArgumentException("Invalid email format."));
        $contactInvalid->create($this->test_user_id);

        $contactDuplicate = new Contact($this->pdo);
        $contactDuplicate->first_name = "Duplicate"; $contactDuplicate->last_name = "Emailer";
        $contactDuplicate->email = "jane.doe@example.com";
        $this->expectException(new InvalidArgumentException("This email address is already in use by another active contact."));
        $contactDuplicate->create($this->test_user_id);

        // Test uniqueness on update (allow same email for self)
        $contact1_to_update = new Contact($this->pdo);
        $this->assertTrue($contact1_to_update->read($contact1_id), "Failed to read contact1 for update test");
        $contact1_to_update->phone_mobile = "999-999-9999";
        $this->assertTrue($contact1_to_update->update(), "Update with same email for self failed.");

        // Create another contact, then try to update contact1 to its email
        $contact2 = new Contact($this->pdo);
        $contact2->first_name = "Other"; $contact2->last_name = "Person";
        $contact2->email = "other.person@example.com";
        $contact2->create($this->test_user_id);

        $contact1_to_update_again = new Contact($this->pdo);
        $this->assertTrue($contact1_to_update_again->read($contact1_id));
        $contact1_to_update_again->email = "other.person@example.com";
        $this->expectException(new InvalidArgumentException("This email address is already in use by another active contact."));
        $contact1_to_update_again->update();
    }

    function testContactUpdateAndOptimisticLock() {
        $contact = new Contact($this->pdo);
        $contact->first_name = "Opti"; $contact->last_name = "Lock";
        $contact->email = "opti@example.com";
        $contact->create($this->test_user_id);
        $id = $contact->id; $v1 = $contact->version; // Should be 1

        $c1 = new Contact($this->pdo);
        $this->assertTrue($c1->read($id));
        $c1->title = "Manager";
        $this->assertTrue($c1->update());
        $this->assertEqual($c1->version, $v1 + 1, "Version after first update should be ".($v1+1).", got ".$c1->version);

        $c2_stale = new Contact($this->pdo);
        $this->assertTrue($c2_stale->read($id)); // Now version is $v1 + 1
        $c2_stale->version = $v1; // Set to stale version
        $c2_stale->phone_mobile = "123";
        $this->expectException(new Exception("Update failed. The contact record was modified by someone else (version mismatch: object had {$v1}, DB has ".($v1+1)."). Please refresh and try again."));
        $c2_stale->update();
    }

    function testReadAllContacts() {
        $c1 = new Contact($this->pdo); $c1->first_name="Alice"; $c1->last_name="Smith"; $c1->email="alice@ex.com"; $c1->organisation_id = $this->test_org_id; $c1->create($this->test_user_id);
        $c2 = new Contact($this->pdo); $c2->first_name="Bob"; $c2->last_name="Johnson"; $c2->email="bob@ex.com"; $c2->create($this->test_user_id);

        $c3_obj = new Contact($this->pdo); $c3_obj->first_name="Charlie"; $c3_obj->last_name="Brown"; $c3_obj->email="charlie@ex.com"; $c3_obj->is_active = false; $c3_obj->create($this->test_user_id);
        $c3_obj->read($c3_obj->id); // Re-read to get current version
        $c3_obj->setActiveStatus(false); // Make inactive

        $_SESSION['is_admin'] = true;
        $allContacts = Contact::readAll($this->pdo, ['view' => 'all']);
        $this->assertEqual(count($allContacts), 3, "Admin with view=all should see 3 contacts. Found: ".count($allContacts));

        $_SESSION['is_admin'] = false;
        $activeContacts = Contact::readAll($this->pdo);
        $this->assertEqual(count($activeContacts), 2, "Non-admin should see 2 active contacts. Found: ".count($activeContacts));

        $_SESSION['is_admin'] = true;
        $inactiveContacts = Contact::readAll($this->pdo, ['is_active' => false, 'view' => 'all']);
        $this->assertEqual(count($inactiveContacts), 1, "Admin filtering for inactive should see 1. Found: ".count($inactiveContacts));
        if(count($inactiveContacts)==1) $this->assertEqual($inactiveContacts[0]->first_name, "Charlie");
    }
}
?>
