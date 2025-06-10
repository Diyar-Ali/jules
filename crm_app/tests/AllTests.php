<?php
// Ensure SimpleTest library is accessible.
// Adjust path if necessary, or use Composer autoloading for SimpleTest if installed that way.
// This line assumes simpletest's autorun.php can be found relative to this script,
// or that it's already in PHP's include_path.
// If running individual test files directly, they also have this.
// If running this AllTests.php suite, this single require might be enough.
@include_once __DIR__ . '/lib/simpletest/autorun.php'; // @ to suppress if already included

// If autorun.php is not found or if using a different test runner,
// you might need to include specific SimpleTest files manually:
if (!class_exists('TestSuite')) {
    // Fallback or error if SimpleTest is not loaded
    // For this environment, we assume autorun.php handles it or it's pre-configured.
    // echo "SimpleTest library (TestSuite class) not found. Please ensure it's in tests/lib/simpletest or include_path.\n";
    // One might need to manually include unit_tester.php, web_tester.php, mock_objects.php, reporter.php etc.
    // e.g., require_once(__DIR__ . '/lib/simpletest/unit_tester.php');
    // require_once(__DIR__ . '/lib/simpletest/reporter.php');
}


class AllTests extends TestSuite {
    function __construct() {
        parent::__construct('All CRM Application Tests');

        // Add top-level test files to the suite
        $this->addFile(__DIR__ . '/UserTest.php');
        $this->addFile(__DIR__ . '/OrganisationTest.php');
        $this->addFile(__DIR__ . '/ContactTest.php');
        $this->addFile(__DIR__ . '/LeadTest.php');
        $this->addFile(__DIR__ . '/ActivityTest.php');

        // Placeholder for future test files for other classes
        // $this->addFile(__DIR__ . '/DealTest.php');
        // $this->addFile(__DIR__ . '/LeadStatusHistoryTest.php');
        // $this->addFile(__DIR__ . '/DashboardTest.php');

        // If tests are organized into subdirectories, you can add them like:
        // $this->addFile(__DIR__ . '/model_tests/SomeModelTest.php');
        // $this->addFile(__DIR__ . '/controller_tests/SomeControllerTest.php');
    }
}

// To run these tests from the command line:
// php /path/to/your/crm_app/tests/AllTests.php
//
// To run with a web reporter, you might need to adjust autorun.php or manually use WebTestReporter:
// Example (manual web reporter - more complex setup usually):
// if (SimpleReporter::inCli()) {
//     exit (new AllTests())->run(new TextReporter()) ? 0 : 1;
// } else {
//    (new AllTests())->run(new HtmlReporter());
// }
// However, autorun.php usually handles CLI/web detection.
?>
