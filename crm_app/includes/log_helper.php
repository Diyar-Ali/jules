<?php
// crm_app/includes/log_helper.php

define('LOG_FILE', __DIR__ . '/../logs/application.log');

function log_message($level, $message, $user_id = null) {
    $timestamp = date('Y-m-d H:i:s');
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $log_entry = "[{$timestamp}] [{$level}] [IP: {$ip_address}]";

    if ($user_id !== null) {
        $log_entry .= " [User: {$user_id}]";
    }

    $log_entry .= ": {$message}" . PHP_EOL;

    // Ensure logs directory exists (basic check, might need more robust error handling)
    if (!file_exists(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0755, true);
    }

    // Append to the log file
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
}

// Example usage:
// log_message('INFO', 'User logged in successfully.', $_SESSION['user_id'] ?? null);
// log_message('ERROR', 'Failed to process payment.', $_SESSION['user_id'] ?? null);

?>
