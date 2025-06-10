<?php
require_once __DIR__ . '/lib/simpletest/autorun.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Organisation.php';
require_once __DIR__ . '/../../classes/Contact.php';
require_once __DIR__ . '/../../classes/Lead.php';
require_once __DIR__ . '/../../classes/LeadStatusHistory.php';
// Deal.php is conditionally required in Lead class for convertToDeal
// For tests not involving convertToDeal, it's not strictly needed here.
// For convertToDeal test, we'll ensure it's loaded or skip/fail test.
if (file_exists(__DIR__ . '/../../classes/Deal.php')) {
    require_once __DIR__ . '/../../classes/Deal.php';
}
require_once __DIR__ . '/config.test.php';

class LeadTest extends UnitTestCase {
    private $pdo;
    private $test_user_id;
    private $test_org_id;
    private $test_contact_id;

    function setUp() {
        reset_test_database();
        $this->pdo = get_test_pdo();
        $this->test_user_id = 1; // Admin user from reset_test_database

        $org = new Organisation($this->pdo);
        $org->name = "Lead Test Organisation";
        // Fill required Organisation fields for it to be creatable
        $org->phone = "0000000000"; $org->address_street = "Org St"; $org->address_city="Org City";
        $org->address_state = "OS"; $org->address_zip = "00000"; $org->address_country = "OrgLand";
        $org->industry = "Testing"; $org->annual_revenue = 10000;
        $this->assertTrue($org->create($this->test_user_id), "Setup: Failed to create test organisation for lead tests.");
        $this->test_org_id = $org->id;

        $contact = new Contact($this->pdo);
        $contact->first_name = "Lead"; $contact->last_name = "Contact";
        $contact->email = "lead.contact@example.com";
        $contact->organisation_id = $this->test_org_id;
        $this->assertTrue($contact->create($this->test_user_id), "Setup: Failed to create test contact for lead tests.");
        $this->test_contact_id = $contact->id;

        if (session_status() == PHP_SESSION_NONE) {
            // @session_start();
        }
        $_SESSION = ['user_id' => $this->test_user_id, 'is_admin' => true];
        $_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost/crm_app/public';
    }

    function tearDown() { $this->pdo = null; $_SESSION = []; }

    function testLeadCreateAndRead() {
        $lead = new Lead($this->pdo);
        $lead->name = "New Big Lead";
        $lead->source = "Website";
        $lead->value = 5000.00;
        $lead->organisation_id = $this->test_org_id;
        $lead->contact_id = $this->test_contact_id;
        $lead->assigned_user_id = $this->test_user_id;
        // status, temperature, is_active, version are defaulted by constructor

        $this->assertTrue($lead->create($this->test_user_id), "Lead creation failed. Error: " . ($this->pdo->errorInfo()[2] ?? 'N/A'));
        $this->assertNotNull($lead->id, "Lead ID not set after creation.");

        $readLead = new Lead($this->pdo);
        $this->assertTrue($readLead->read($lead->id), "Failed to read created lead.");
        $this->assertEqual($readLead->name, "New Big Lead");
        $this->assertEqual($readLead->status, "New"); // Default
        $this->assertEqual($readLead->temperature, "Cold"); // Default
        $this->assertEqual($readLead->version, 1, "Version should be 1. Got: {$readLead->version}");
        $this->assertTrue($readLead->is_active, "Lead should be active by default.");

        $history = LeadStatusHistory::readAllByLeadId($this->pdo, $lead->id);
        $this->assertEqual(count($history), 1, "Expected 1 history entry on creation.");
        if(count($history) == 1){
            $this->assertEqual($history[0]->new_status, "New");
            $this->assertEqual($history[0]->new_temperature, "Cold");
        }
    }

    function testLeadUpdateAndStatusHistory() {
        $lead = new Lead($this->pdo);
        $lead->name = "Updateable Lead";
        $lead->organisation_id = $this->test_org_id; // Required for deal conversion, good to have
        $lead->value = 100.00;
        $this->assertTrue($lead->create($this->test_user_id), "Initial lead creation failed.");
        $id = $lead->id;
        $v1 = $lead->version; // Should be 1

        $l1 = new Lead($this->pdo);
        $this->assertTrue($l1->read($id), "Failed to read lead for update.");
        $l1->status = "Qualified";
        $l1->temperature = "Hot";
        $this->assertTrue($l1->update($this->test_user_id), "Lead update failed.");
        $this->assertEqual($l1->version, $v1 + 1, "Version not incremented correctly.");

        $history = LeadStatusHistory::readAllByLeadId($this->pdo, $id);
        $this->assertEqual(count($history), 2, "Expected 2 history entries (initial + update). Found: ".count($history));
        // History is ordered DESC, so first element is the latest change
        if(count($history) == 2){
            $this->assertEqual($history[0]->new_status, "Qualified");
            $this->assertEqual($history[0]->old_status, "New");
            $this->assertEqual($history[0]->new_temperature, "Hot");
            $this->assertEqual($history[0]->old_temperature, "Cold");
        }
    }

    function testLeadConversion() {
        if (!class_exists('Deal')) {
            $this->reporter->paintSkip("Deal class not found, skipping lead conversion test.");
            return;
        }
        $lead = new Lead($this->pdo);
        $lead->name = "Convert Me Please";
        $lead->value = 12000.50;
        $lead->organisation_id = $this->test_org_id;
        $lead->contact_id = $this->test_contact_id;
        $lead->status = "Qualified"; // Usually leads are qualified before conversion
        $lead->temperature = "Hot";
        $this->assertTrue($lead->create($this->test_user_id), "Failed to create lead for conversion test.");
        $leadId = $lead->id;

        $deal_name = "Deal from " . $lead->name;
        $initial_stage = Deal::getStageOptions()[1]; // e.g., 'Qualification' or second stage

        $new_deal_id = $lead->convertToDeal($this->test_user_id, $deal_name, $initial_stage);
        $this->assertTrue($new_deal_id !== false, "convertToDeal returned false.");
        $this->assertIsA($new_deal_id, 'integer', "New Deal ID is not an integer.");

        $convertedLead = new Lead($this->pdo);
        $this->assertTrue($convertedLead->read($leadId), "Failed to re-read converted lead.");
        $this->assertEqual($convertedLead->status, "Converted", "Lead status not 'Converted'.");
        $this->assertTrue($convertedLead->isConverted(), "isConverted() returned false.");
        $this->assertEqual($convertedLead->converted_to_deal_id, $new_deal_id, "converted_to_deal_id mismatch.");

        $deal = new Deal($this->pdo);
        $this->assertTrue($deal->read($new_deal_id), "Failed to read newly created deal.");
        $this->assertEqual($deal->name, $deal_name);
        $this->assertEqual((float)$deal->amount, 12000.50); // Ensure float comparison
        $this->assertEqual($deal->organisation_id, $this->test_org_id);
        $this->assertEqual($deal->contact_id, $this->test_contact_id);
        $this->assertEqual($deal->stage, $initial_stage);

        $history = LeadStatusHistory::readAllByLeadId($this->pdo, $leadId);
        $this->assertTrue(count($history) >= 2, "Not enough history entries after conversion.");
        $foundConvertedStatus = false;
        foreach($history as $h) {
            if ($h->new_status === 'Converted') {
                $foundConvertedStatus = true;
                break;
            }
        }
        $this->assertTrue($foundConvertedStatus, "Conversion status change not logged correctly in history.");
    }

    function testPreventEditOrDeleteConvertedLead() {
        if (!class_exists('Deal')) {
            $this->reporter->paintSkip("Deal class not found, skipping converted lead constraint tests.");
            return;
        }
        $lead = new Lead($this->pdo);
        $lead->name = "Already Converted Lead";
        $lead->organisation_id = $this->test_org_id;
        $lead->value = 100.00;
        $this->assertTrue($lead->create($this->test_user_id));
        $leadId = $lead->id;

        $new_deal_id = $lead->convertToDeal($this->test_user_id, "Deal from Converted Lead", Deal::getStageOptions()[0]);
        $this->assertTrue($new_deal_id !== false, "Pre-test conversion failed.");

        $convertedLead = new Lead($this->pdo);
        $this->assertTrue($convertedLead->read($leadId)); // Load its current state (status=Converted, version incremented)

        $convertedLead->description = "Trying to update converted lead's description";
        $this->expectException(new Exception("Cannot update a lead that is already converted, unless setting its status to 'Converted'."));
        $convertedLead->update($this->test_user_id);

        $this->expectException(new Exception("Cannot permanently delete a lead (ID: {$leadId}) that has been converted to a deal (Deal ID: {$new_deal_id})."));
        Lead::hardDelete($this->pdo, $leadId);
    }
}
?>
