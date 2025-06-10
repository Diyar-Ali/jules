<?php
// Ensure SimpleTest library is accessible.
// Adjust path if necessary, or use Composer autoloading for SimpleTest if installed that way.
@include_once __DIR__ . '/lib/simpletest/autorun.php'; // @ to suppress if already included by AllTests

// If autorun.php is not found or if using a different test runner,
// you might need to include specific SimpleTest files manually:
if (!class_exists('UnitTestCase')) {
    // Fallback or error if SimpleTest is not loaded
    // For this environment, we assume autorun.php handles it or it's pre-configured.
    // echo "SimpleTest library not found. Please ensure it's in tests/lib/simpletest\n";
}

require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Organisation.php'; // For testing FK constraints, if any
require_once __DIR__ . '/../../classes/Contact.php';    // For testing FK constraints, if any
require_once __DIR__ . '/../../classes/Lead.php';       // For testing FK constraints, if any
require_once __DIR__ . '/../../classes/Deal.php';       // For testing FK constraints, if any
require_once __DIR__ . '/../../classes/Activity.php';   // For testing FK constraints, if any
require_once __DIR__ . '/config.test.php';

class UserTest extends UnitTestCase {
    private $pdo;
    private static $db_reset_done = false; // Ensure DB is reset only once per test suite run if preferred

    // Called once before any tests in this class run (if using a test runner that supports it)
    // For SimpleTest's autorun, setUp() is per-test method.
    // We can use a static flag to achieve once-per-class setup for DB reset.
    public static function setUpBeforeClass() {
        // This is a PHPUnit style, SimpleTest doesn't have this directly in autorun.
        // DB reset is handled in setUp() for now.
    }

    function setUp() {
        // Reset database before each test method for isolation
        reset_test_database();
        $this->pdo = get_test_pdo();

        if (session_status() == PHP_SESSION_NONE) {
             // @session_start(); // Avoids "headers already sent" if run via web and something else started session.
                               // CLI typically doesn't have this issue.
                               // For robust CLI testing, ensure no output before session_start or use ob_start/ob_end_clean.
                               // Given SimpleTest's autorun.php might output, this can be tricky.
                               // Let's assume CLI or carefully managed output for now.
                               // If session_config.php is included by classes, it might try to start session.
        }
        $_SESSION = [];
        // Ensure necessary ENV vars are set for the classes if they rely on them directly
        $_ENV['APP_URL'] = $_ENV['APP_URL'] ?? 'http://localhost/crm_app/public';
    }

    function tearDown() {
        $this->pdo = null;
        $_SESSION = [];
    }

    function testUserCreateAndRead() {
        $user = new User($this->pdo);
        $user->username = 'testuser';
        $user->setPassword('password123');
        $user->email = 'test@example.com';
        $user->first_name = 'Test';
        $user->last_name = 'User';
        $user->role = 'sales'; // Valid role from User::getRoleOptions()
        $user->is_admin = false;
        $user->is_active = true;

        $this->assertTrue($user->create(), "User creation failed. PDO Error: " . ($this->pdo->errorInfo()[2] ?? 'N/A'));
        $this->assertNotNull($user->id, "User ID not set after creation");

        $readUser = new User($this->pdo);
        $this->assertTrue($readUser->read($user->id), "Failed to read created user");
        $this->assertEqual($readUser->username, 'testuser', "Username mismatch");
        $this->assertEqual($readUser->email, 'test@example.com', "Email mismatch");
        $this->assertTrue($readUser->verifyPassword('password123'), "Password verification failed");
        $this->assertEqual($readUser->version, 1, "Version should be 1 on create. Got: " . $readUser->version);
        $this->assertEqual($readUser->role, 'sales', "Role mismatch");
    }

    function testPreventDuplicateUsernameOnCreate() {
        $user1 = new User($this->pdo);
        $user1->username = 'duplicateuser';
        $user1->setPassword('pass1');
        $user1->email = 'email1@example.com';
        $user1->role = 'viewer';
        $this->assertTrue($user1->create(), "Setup user1 creation failed.");

        $user2 = new User($this->pdo);
        $user2->username = 'duplicateuser';
        $user2->setPassword('pass2');
        $user2->email = 'email2@example.com';
        $user2->role = 'viewer';

        $this->expectException(new Exception("Username already exists."));
        $user2->create();
    }

    function testPreventDuplicateEmailOnCreate() {
        // Note: The User class's create method checks for email uniqueness
        // AND the database schema has a UNIQUE constraint on the email column.
        // This test checks the application-level exception.
        $user1 = new User($this->pdo);
        $user1->username = 'userWithEmail1';
        $user1->setPassword('pass1');
        $user1->email = 'duplicate@example.com';
        $user1->role = 'viewer';
        $this->assertTrue($user1->create(), "Setup user1 creation failed for email test.");

        $user2 = new User($this->pdo);
        $user2->username = 'userWithEmail2';
        $user2->setPassword('pass2');
        $user2->email = 'duplicate@example.com';
        $user2->role = 'viewer';

        // User class should throw "Email already exists."
        // If that check is bypassed or fails, DB will throw PDOException (code 23000)
        $this->expectException(new Exception("Email already exists."));
        try {
            $user2->create();
        } catch (PDOException $e) {
            // This catch block is for debugging the test itself if the DB constraint fires instead of app exception
            if ($e->getCode() == '23000') {
                $this->fail("Expected app-level 'Email already exists' exception, but DB unique constraint fired first. Message: " . $e->getMessage());
            } else {
                throw $e; // Re-throw other PDO exceptions
            }
        }
    }


    function testUserUpdateAndOptimisticLock() {
        $user = new User($this->pdo);
        $user->username = 'updateuser';
        $user->setPassword('initialPass');
        $user->email = 'update@example.com';
        $user->role = 'viewer';
        $user->create();
        $original_id = $user->id;
        $original_version = $user->version; // Should be 1

        // First update
        $userToUpdate1 = new User($this->pdo);
        $this->assertTrue($userToUpdate1->read($original_id), "Read failed for userToUpdate1");
        $userToUpdate1->first_name = "UpdatedName";
        // $userToUpdate1->version is now $original_version (e.g. 1)
        $this->assertTrue($userToUpdate1->update(), "First update failed. Error: " . ($this->pdo->errorInfo()[2] ?? 'N/A'));
        $this->assertEqual($userToUpdate1->version, $original_version + 1, "Version not incremented on first update");
        $this->assertEqual($userToUpdate1->first_name, "UpdatedName");

        // Attempt to update with stale object (original version)
        $staleUser = new User($this->pdo);
        $this->assertTrue($staleUser->read($original_id), "Read failed for staleUser setup"); // Reads latest state (version is now original_version + 1)
        $staleUser->version = $original_version; // Manually set version to stale value
        $staleUser->last_name = "AttemptWithStale";

        $this->expectException(new Exception("Update failed. The record has been modified by someone else. Please refresh and try again."));
        $staleUser->update();

        // Verify original data was not changed by stale update attempt
        $freshUser = new User($this->pdo);
        $freshUser->read($original_id);
        $this->assertNotEqual($freshUser->last_name, "AttemptWithStale");
        $this->assertEqual($freshUser->version, $original_version + 1, "Version should remain from first successful update");
    }

    function testPasswordChange() {
        $user = new User($this->pdo);
        $user->username = 'passchangeuser';
        $user->setPassword('oldPassword');
        $user->email = 'passchange@example.com';
        $user->role = 'viewer';
        $user->create();
        $userId = $user->id;

        $userToChangePass = new User($this->pdo);
        $this->assertTrue($userToChangePass->read($userId), "Read user for password change failed");
        $userToChangePass->setPassword('newStrongPassword');
        $this->assertTrue($userToChangePass->update(), "Password change update failed");

        $reReadUser = new User($this->pdo);
        $this->assertTrue($reReadUser->read($userId), "Re-read user after password change failed");
        $this->assertFalse($reReadUser->verifyPassword('oldPassword'), "Old password should not work");
        $this->assertTrue($reReadUser->verifyPassword('newStrongPassword'), "New password should work");
    }


    function testSetAndVerifyPassword() {
        $user = new User($this->pdo);
        $user->setPassword('securePass123!');
        $this->assertTrue($user->verifyPassword('securePass123!'), "Password verification failed for correct password.");
        $this->assertFalse($user->verifyPassword('wrongPass'), "Password verification succeeded for incorrect password.");
    }

    function testFindByUsername() {
        $user = new User($this->pdo);
        $user->username = 'findme';
        $user->setPassword('pass');
        $user->email = 'findme@example.com';
        $user->is_active = true;
        $user->role = 'viewer';
        $user->create();

        $foundUser = User::findByUsername($this->pdo, 'findme');
        $this->assertNotNull($foundUser, "User 'findme' not found.");
        if ($foundUser) {
            $this->assertEqual($foundUser->id, $user->id);
        }

        $inactiveUser = new User($this->pdo);
        $inactiveUser->username = 'findmeinactive';
        $inactiveUser->setPassword('pass');
        $inactiveUser->email = 'findmeinactive@example.com';
        $inactiveUser->is_active = false;
        $inactiveUser->role = 'viewer';
        $inactiveUser->create();

        $this->assertFalse(User::findByUsername($this->pdo, 'findmeinactive', true), "Inactive user found when only_active=true.");
        $this->assertNotNull(User::findByUsername($this->pdo, 'findmeinactive', false), "Inactive user not found when only_active=false.");
        $this->assertFalse(User::findByUsername($this->pdo, 'nosuchuser'), "Found non-existent user.");
    }

    function testAuthenticationSimulation() {
        $testUser = new User($this->pdo);
        $testUser->username = 'auth_user';
        $testUser->setPassword('auth_pass');
        $testUser->email = 'auth@test.com';
        $testUser->is_active = true;
        $testUser->role = 'viewer';
        $testUser->create();

        $found = User::findByUsername($this->pdo, 'auth_user', true);
        $this->assertTrue($found && $found->verifyPassword('auth_pass'), "Simulated successful login failed.");
        if ($found && $found->verifyPassword('auth_pass')) {
            // Simulate session setting by application
            $_SESSION['user_id'] = $found->id;
            $_SESSION['username'] = $found->username;
        }
        $this->assertEqual($_SESSION['user_id'], $found->id, "Session user_id not set correctly.");

        $this->assertFalse($found && $found->verifyPassword('wrong_auth_pass'), "Simulated wrong password login succeeded.");

        $notFound = User::findByUsername($this->pdo, 'wrong_auth_user', true);
        $this->assertFalse($notFound, "Simulated wrong username login found a user.");
    }
}
?>
