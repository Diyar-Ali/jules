<?php
// crm_app/tests/config.test.php
// Test database configuration

// Attempt to load .env variables if not already set (e.g., by a bootstrap file)
if (file_exists(__DIR__ . '/../../vendor/autoload.php') && file_exists(__DIR__ . '/../../.env')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..'); // Path to the root .env file
        $dotenv->load();
    }
}


define('TEST_DB_HOST', $_ENV['TEST_DB_HOST'] ?? '127.0.0.1');
define('TEST_DB_NAME', $_ENV['TEST_DB_NAME'] ?? 'connect_crm_test');
define('TEST_DB_USER', $_ENV['TEST_DB_USER'] ?? 'root');
define('TEST_DB_PASS', $_ENV['TEST_DB_PASS'] ?? ''); // Default to empty password if not set
define('TEST_DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4'); // Use main DB_CHARSET if TEST_DB_CHARSET not set

$test_pdo = null;

function get_test_pdo() {
    global $test_pdo;
    // Do not re-initialize if already connected in this request lifecycle (e.g. multiple tests in one run)
    // However, for true isolation, setUp might nullify $test_pdo to force fresh connection.
    // For now, simple singleton pattern.
    if ($test_pdo === null) {
        $dsn = "mysql:host=" . TEST_DB_HOST . ";charset=" . TEST_DB_CHARSET; // Connect without dbname first
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $temp_pdo = new PDO($dsn, TEST_DB_USER, TEST_DB_PASS, $options);
            $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . TEST_DB_NAME . "` CHARACTER SET " . TEST_DB_CHARSET . " COLLATE " . TEST_DB_CHARSET . "_unicode_ci;");
            $temp_pdo->exec("USE `" . TEST_DB_NAME . "`;");
            $test_pdo = $temp_pdo; // Assign to global after successful DB selection
        } catch (PDOException $e) {
            die("Test Database Setup/Connection Error: " . $e->getMessage() .
                "\nPlease ensure your MySQL server is running and credentials are correct.\n" .
                "Attempted to connect to host: " . TEST_DB_HOST . " with user: " . TEST_DB_USER . "\n" .
                "Tried to create/use database: '" . TEST_DB_NAME . "'.\n");
        }
    }
    return $test_pdo;
}

function reset_test_database() {
    $pdo = get_test_pdo(); // This will also ensure DB exists and is selected.
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`;");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        $schema_path = __DIR__ . '/../../db_setup/database_schema.sql';
        if (!file_exists($schema_path)) {
            die("Database schema file not found at: {$schema_path}\n");
        }
        $schema_sql = file_get_contents($schema_path);
        if (empty(trim($schema_sql))) {
            die("Database schema file is empty: {$schema_path}\n");
        }
        $pdo->exec($schema_sql);

        // Seed one default admin user (password: 'adminpass')
        // This is useful because many entities require a created_by_user_id.
        $admin_pass_hash = password_hash('adminpass', PASSWORD_DEFAULT);
        $seed_sql = "INSERT INTO users (id, username, password_hash, email, first_name, last_name, role, is_admin, is_active, version) VALUES
                     (1, 'testadmin', '{$admin_pass_hash}', 'admin@test.com', 'Test', 'Admin', 'manager', TRUE, TRUE, 1);";
        $pdo->exec($seed_sql);


    } catch (PDOException $e) {
        die("Test Database Reset Error: " . $e->getMessage() . "\nSQL Schema path: " . realpath($schema_path) . "\n");
    }
}

// Helper to ensure .env is loaded for tests if this file is included directly by PHPUnit or other runners
// that might not run a central bootstrap which loads .env.
function ensure_env_loaded_for_tests() {
    // The .env loading is already at the top of this file.
    // This function can be a placeholder or used to add more test-specific env setup if needed.
}

ensure_env_loaded_for_tests();

?>
