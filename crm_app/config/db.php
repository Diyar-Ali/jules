<?php
// crm_app/config/db.php

// Load environment variables - In a real setup, you'd use a library like Dotenv
// For simplicity, we'll use getenv() and assume they are set in the server environment.
$db_host = getenv('DB_HOST') ?: 'localhost'; // Default to 'localhost' if not set
$db_name = getenv('DB_NAME') ?: 'crm_database';   // Replace 'crm_database' with your actual DB name
$db_user = getenv('DB_USER') ?: 'crm_user';     // Replace 'crm_user' with your actual DB username
$db_pass = getenv('DB_PASS') ?: 'crm_password'; // Replace 'crm_password' with your actual DB password
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Important for error handling
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use native prepared statements
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // In a real application, log this error and show a user-friendly message
    // For now, we'll just die with the error.
    // Consider using the logger we'll create later.
    error_log("Database Connection Error: " . $e->getMessage()); // Log to PHP error log
    // In a production environment, you might want to show a generic error page
    // For development, this detailed error is fine.
    die("Database connection failed. Please check server logs. Error: " . $e->getMessage());
}

// The $pdo object is now available for use in other parts of the application
// e.g., require_once __DIR__ . '/config/db.php';
?>
