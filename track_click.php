<?php
require_once 'config/database.php';

$log_id = intval($_GET['log_id'] ?? 0);
$target_url = $_GET['url'] ?? 'https://google.com';

if ($log_id > 0) {
    // Update the database to reflect that this specific log was clicked
    $stmt = $pdo->prepare("UPDATE email_logs SET is_clicked = 1 WHERE id = ?");
    $stmt->execute([$log_id]);
}

// Redirect the user to the destination
header("Location: " . $target_url);
exit;
?>