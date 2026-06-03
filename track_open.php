<?php
require_once 'config/database.php';

// Get the log_id from the pixel URL
$log_id = intval($_GET['log_id'] ?? 0);

if ($log_id > 0) {
    // Update the database to mark as opened
    $stmt = $pdo->prepare("UPDATE email_logs SET is_opened = 1 WHERE id = ?");
    $stmt->execute([$log_id]);
}

// Return a tiny transparent 1x1 GIF so the email client doesn't show a "broken image" icon
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICRAEAOw==');
exit;
?>