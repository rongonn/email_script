<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];

$type = $_POST['type'] ?? 'all';
$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';

$dateSQL = "";
$params = [];

if ($type == "today") {
    $dateSQL = " AND DATE(created_at)=CURDATE() ";

} elseif ($type == "7days") {
    $dateSQL = " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ";

} elseif ($type == "28days") {
    $dateSQL = " AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY) ";

} elseif ($type == "custom" && $start && $end) {
    $dateSQL = " AND DATE(created_at) BETWEEN ? AND ? ";
    $params = [$start, $end];
}

/* SAFE COUNTS */
function countData($pdo,$sql,$user_id,$dateSQL,$params){
    $stmt = $pdo->prepare($sql.$dateSQL);
    $stmt->execute(array_merge([$user_id],$params));
    return $stmt->fetchColumn();
}

echo json_encode([
    "contacts"  => countData($pdo,"SELECT COUNT(*) FROM contacts WHERE user_id=?",$user_id,$dateSQL,$params),
    "campaigns" => countData($pdo,"SELECT COUNT(*) FROM campaigns WHERE user_id=?",$user_id,$dateSQL,$params),
    "templates" => countData($pdo,"SELECT COUNT(*) FROM email_templates WHERE user_id=?",$user_id,$dateSQL,$params),
    "sent"      => countData($pdo,"SELECT COUNT(*) FROM email_logs WHERE user_id=?",$user_id,$dateSQL,$params)
]);