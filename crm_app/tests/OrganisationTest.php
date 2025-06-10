<?php
require_once __DIR__ . '/lib/simpletest/autorun.php';
require_once __DIR__ . '/../../classes/User.php';        // For creating test users
require_once __DIR__ . '/../../classes/Organisation.php';
require_once __DIR__ . '/../../classes/Deal.php';        // For testing hard delete constraint
require_once __DIR__ . '/config.test.php';      // For test DB connection and reset

class OrganisationTest extends UnitTestCase {
    private $pdo;
    private $test_user_id; // This will be the ID of 'testadmin' seeded by reset_test_database()

    function setUp() {
        reset_test_database();
        $this->pdo = get_test_pdo();

        // Use the seeded 'testadmin' user (ID 1)
        $this->test_user_id = 1;

        // Mock session for tests that might use it (e.g. log_helper, or classes using $_SESSION directly)
        if (session_status() == PHP_SESSION_NONE) {
            // Suppress errors if headers already sent (common in CLI test environments if not careful)
            // For pure CLI, session_start() might not be strictly necessary unless classes use $_SESSION.
            // If classes like Activity.php or others use $_SESSION['user_id'] directly, then it's needed.
            // @session_start();
        }
        $_SESSION = [];
        $_SESSION['user_id'] = $this->test_user_id;
        $_SESSION['is_admin'] = true; // Assume admin for most setup convenience, test specific roles if needed

        // Ensure ENV VARS are available if classes directly use them
        $_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost/crm_app/public';

    }

    function tearDown() {
        $this->pdo = null;
        $_SESSION = [];
    }

    function testOrganisationCreateAndRead() {
        $org = new Organisation($this->pdo);
        $org->name = "Test Corp";
        $org->website = "http://testcorp.com";
        $org->phone = "123-456-7890";
        $org->industry = "Tech";
        $org->address_street = "123 Main St";
        $org->address_city = "Testville";
        $org->is_active = true; // Explicitly set, though true is default from constructor
        $org->version = 1;     // Explicitly set, though 1 is default from constructor
        $org->annual_revenue = 50000.00;


        $this->assertTrue($org->create($this->test_user_id), "Org creation failed. PDO Error: " . ($this->pdo->errorInfo()[2] ?? 'N/A'));
        $this->assertNotNull($org->id, "Org ID not set after creation");

        $readOrg = new Organisation($this->pdo);
        $this->assertTrue($readOrg->read($org->id), "Failed to read created org");
        $this->assertEqual($readOrg->name, "Test Corp", "Name mismatch");
        $this->assertEqual($readOrg->website, "http://testcorp.com", "Website mismatch");
        $this->assertEqual($readOrg->created_by_user_id, $this->test_user_id, "Creator ID mismatch");
        $this->assertEqual($readOrg->version, 1, "Version should be 1 on create. Got: ".$readOrg->version);
        $this->assertTrue($readOrg->is_active, "Org should be active");
        $this->assertEqual($readOrg->annual_revenue, 50000.00);
    }

    function testOrganisationUpdateAndOptimisticLock() {
        $org = new Organisation($this->pdo);
        $org->name = "Initial Org Name";
        $org->industry = "Finance";
        $org->create($this->test_user_id);
        $original_id = $org->id;
        $original_version = $org->version; // Should be 1

        // First update
        $orgToUpdate1 = new Organisation($this->pdo);
        $this->assertTrue($orgToUpdate1->read($original_id), "Read for first update failed");
        $orgToUpdate1->phone = "555-000-1111";
        $this->assertTrue($orgToUpdate1->update(), "First update failed");
        $this->assertEqual($orgToUpdate1->version, $original_version + 1, "Version not incremented on first update");
        $this->assertEqual($orgToUpdate1->phone, "555-000-1111");

        // Attempt to update with stale object
        $staleOrg = new Organisation($this->pdo);
        $this->assertTrue($staleOrg->read($original_id), "Read for stale object setup failed"); // $staleOrg now has version $original_version + 1
        $staleOrg->version = $original_version; // Manually set version back to simulate staleness
        $staleOrg->website = "http://staleupdate.com";

        $this->expectException(new Exception("Update failed. The organisation record was modified by someone else (version mismatch: object had {$staleOrg->version}, DB has {$orgToUpdate1->version}). Please refresh and try again."));
        $staleOrg->update();

        // Verify original data was not changed by stale update
        $freshOrg = new Organisation($this->pdo);
        $freshOrg->read($original_id);
        $this->assertNotEqual($freshOrg->website, "http://staleupdate.com");
        $this->assertEqual($freshOrg->version, $original_version + 1, "Version should be from the first successful update");
    }

    function testOrganisationSetActiveStatus() {
        $org = new Organisation($this->pdo);
        $org->name = "Status Test Org";
        $org->create($this->test_user_id);
        $orgId = $org->id;
        $initial_version = $org->version;

        // Deactivate
        $orgToDeactivate = new Organisation($this->pdo);
        $this->assertTrue($orgToDeactivate->read($orgId));
        $this->assertTrue($orgToDeactivate->setActiveStatus(false), "Failed to deactivate org");

        $reReadOrg = new Organisation($this->pdo);
        $this->assertTrue($reReadOrg->read($orgId));
        $this->assertFalse($reReadOrg->is_active, "Org should be inactive after deactivation");
        $this->assertEqual($reReadOrg->version, $initial_version + 1, "Version should increment after deactivation");

        // Activate
        $orgToActivate = new Organisation($this->pdo);
        $this->assertTrue($orgToActivate->read($orgId));
        $this->assertTrue($orgToActivate->setActiveStatus(true), "Failed to activate org");

        $reReadOrg2 = new Organisation($this->pdo);
        $this->assertTrue($reReadOrg2->read($orgId));
        $this->assertTrue($reReadOrg2->is_active, "Org should be active after reactivation");
        $this->assertEqual($reReadOrg2->version, $initial_version + 2, "Version should increment again after activation");
    }

    function testHardDeleteOrganisationConstraintWithActiveDeals() {
        $org = new Organisation($this->pdo);
        $org->name = "Org With Deals";
        $org->create($this->test_user_id);
        $orgId = $org->id;

        if (!class_exists('Deal')) {
            $this->fail("Deal class not found, cannot test hard delete constraint properly.");
            return;
        }
        $deal = new Deal($this->pdo);
        $deal->name = "Active Deal for Org";
        $deal->organisation_id = $orgId;
        $deal->amount = 1000;
        $deal->stage = 'Prospecting';
        $deal->is_active = true;
        $this->assertTrue($deal->create($this->test_user_id), "Failed to create test deal.");

        $this->expectException(new Exception("Cannot delete organisation: There are 1 active (non-Won/Lost) deals associated. Please close or reassign these deals."));
        Organisation::hardDelete($this->pdo, $orgId);

        $checkOrg = new Organisation($this->pdo);
        $this->assertTrue($checkOrg->read($orgId), "Organisation should still exist after failed delete attempt.");
    }

    function testHardDeleteOrganisationSuccessWithoutActiveDeals() {
        $org = new Organisation($this->pdo);
        $org->name = "Org No Active Deals";
        $org->create($this->test_user_id);
        $orgId = $org->id;

        if (class_exists('Deal')) {
            $closedDeal = new Deal($this->pdo);
            $closedDeal->name = "Closed Deal for Org";
            $closedDeal->organisation_id = $orgId;
            $closedDeal->amount = 500;
            $closedDeal->stage = 'Won';
            $closedDeal->is_active = true;
            $this->assertTrue($closedDeal->create($this->test_user_id), "Failed to create closed test deal.");
        }

        $this->assertTrue(Organisation::hardDelete($this->pdo, $orgId), "Hard delete failed for org without active deals.");

        $checkOrg = new Organisation($this->pdo);
        $this->assertFalse($checkOrg->read($orgId), "Organisation should not exist after successful hard delete.");
    }

     function testReadAllOrganisations() {
        $org1 = new Organisation($this->pdo); $org1->name = "Alpha Corp"; $org1->industry = "Tech"; $org1->create($this->test_user_id);
        $org2 = new Organisation($this->pdo); $org2->name = "Beta Services"; $org2->industry = "Consulting"; $org2->create($this->test_user_id);

        $org3_obj = new Organisation($this->pdo);
        $org3_obj->name = "Gamma Goods";
        $org3_obj->industry = "Retail";
        $org3_obj->is_active = false; // Set before create
        $org3_obj->create($this->test_user_id);
        // After create, $org3->is_active is true because create() calls read() which resets is_active from DB default
        // The setActiveStatus must be used after creation to make it inactive.
        $org3_obj->read($org3_obj->id); // re-read to get correct version
        $org3_obj->setActiveStatus(false); // This will use update and correctly set is_active to false & inc version


        $_SESSION['is_admin'] = true;
        $allOrgs = Organisation::readAll($this->pdo, ['view' => 'all']);
        $this->assertEqual(count($allOrgs), 3, "ReadAll with view=all should fetch 3 orgs. Found: " . count($allOrgs));

        $_SESSION['is_admin'] = false;
        $activeOrgs = Organisation::readAll($this->pdo);
        $this->assertEqual(count($activeOrgs), 2, "ReadAll (non-admin) should fetch 2 active orgs. Found: " . count($activeOrgs));
        foreach($activeOrgs as $o) { $this->assertTrue($o->is_active); }

        $_SESSION['is_admin'] = true;
        $adminActiveOrgs = Organisation::readAll($this->pdo, ['is_active' => true]);
        $this->assertEqual(count($adminActiveOrgs), 2, "ReadAll (admin, is_active=true) should fetch 2. Found: " . count($adminActiveOrgs));

        $adminInactiveOrgs = Organisation::readAll($this->pdo, ['is_active' => false, 'view' => 'all']); // Admin viewing only inactive
        $this->assertEqual(count($adminInactiveOrgs), 1, "ReadAll (admin, is_active=false) should fetch 1. Found: ". count($adminInactiveOrgs));
        if(count($adminInactiveOrgs) == 1) $this->assertFalse($adminInactiveOrgs[0]->is_active);


        $techOrgs = Organisation::readAll($this->pdo, ['search_term' => 'Alpha', 'view' => 'all']);
        $this->assertEqual(count($techOrgs), 1, "Search term 'Alpha' should find 1. Found: ". count($techOrgs));
        if(count($techOrgs) == 1) $this->assertEqual($techOrgs[0]->name, "Alpha Corp");

        $consultingOrgs = Organisation::readAll($this->pdo, ['industry' => 'Consulting', 'view' => 'all']);
        $this->assertEqual(count($consultingOrgs), 1, "Industry 'Consulting' should find 1. Found: ". count($consultingOrgs));
        if(count($consultingOrgs) == 1) $this->assertEqual($consultingOrgs[0]->name, "Beta Services");
    }
}
?>
