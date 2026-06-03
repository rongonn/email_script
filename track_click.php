<?php
require_once 'config/database.php';

$log_id = intval($_GET['log_id'] ?? 0);
$target_url = $_GET['url'] ?? 'https://google.com';

if ($log_id > 0) {
    // লিঙ্কে ক্লিক করলে আমরা ধরে নেব ইমেইল ওপেন হয়েছে, তাই দুটি কলামই আপডেট করছি
    $stmt = $pdo->prepare("UPDATE email_logs SET is_clicked = 1, is_opened = 1 WHERE id = ?");
    $stmt->execute([$log_id]);
}

// Redirect the user to the destination
header("Location: " . $target_url);
exit;
?>