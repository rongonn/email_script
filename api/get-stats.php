<?php
require_once '../config/database.php';
session_start();

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) exit(json_encode(['error' => 'Unauthorized']));

$range = $_GET['range'] ?? '7d';
// Calculate start/end dates
if ($range === 'today') {
    $start = date('Y-m-d 00:00:00');
} elseif ($range === '28d') {
    $start = date('Y-m-d 00:00:00', strtotime('-28 days'));
} else {
    $start = date('Y-m-d 00:00:00', strtotime('-6 days'));
}
$end = date('Y-m-d 23:59:59');

// 1. Get Totals
$stmt = $pdo->prepare("SELECT COUNT(id) as ts, SUM(is_opened) as to_p, SUM(is_clicked) as tc FROM email_logs WHERE user_id = ? AND sent_at BETWEEN ? AND ?");
$stmt->execute([$user_id, $start, $end]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Get Daily Stats for Chart
$chart_stmt = $pdo->prepare("
    SELECT DATE(sent_at) as d, 
           COUNT(id) as sent, 
           SUM(is_opened) as opened, 
           SUM(is_clicked) as clicked 
    FROM email_logs 
    WHERE user_id = ? AND sent_at BETWEEN ? AND ? 
    GROUP BY d ORDER BY d ASC
");
$chart_stmt->execute([$user_id, $start, $end]);
$chart = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total_sent' => (int)$stats['ts'],
    'total_opened' => (int)$stats['to_p'],
    'total_clicked' => (int)$stats['tc'],
    'open_rate' => $stats['ts'] ? round(($stats['to_p']/$stats['ts'])*100, 1) : 0,
    'click_rate' => $stats['ts'] ? round(($stats['tc']/$stats['ts'])*100, 1) : 0,
    'chart' => $chart
]);