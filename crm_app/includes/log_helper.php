<?php
// Ensure the logs directory exists and is writable
// This basic check can be expanded or handled by deployment scripts
$log_file_path = __DIR__ . '/../logs/app.log';
if (!file_exists(dirname($log_file_path))) {
    mkdir(dirname($log_file_path), 0755, true);
}

/**
 * Logs a message to the application log file.
 * @param string $level Log level (e.g., INFO, WARNING, ERROR, DEBUG).
 * @param string $message The message to log.
 * @param array $context Optional context data to include in the log.
 */
function log_message($level, $message, $context = []) {
    global $log_file_path; // Use the global variable defined above

    $timestamp = date('Y-m-d H:i:s');
    $level = strtoupper($level);
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'N/A';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    $log_entry = "[{$timestamp}] [{$level}] [User: {$user_id}] [IP: {$ip_address}] {$message}";

    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }

    $log_entry .= PHP_EOL;

    // Use file_put_contents with FILE_APPEND flag to append to the log file
    // LOCK_EX flag prevents concurrent writes from corrupting the file
    if (file_put_contents($log_file_path, $log_entry, FILE_APPEND | LOCK_EX) === false) {
        // Fallback error handling if logging fails (e.g., permissions issue)
        error_log("Failed to write to application log: {$log_file_path}");
    }
}
?>
