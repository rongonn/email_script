<?php
require_once 'config/database.php';
session_start();
if (!isset($_SESSION['user_id'])) exit('Unauthorized');

$contact_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Fetch custom fields and values
$stmt = $pdo->prepare("
    SELECT cf.field_label, cfv.field_value 
    FROM contact_field_values cfv
    JOIN custom_fields cf ON cfv.field_id = cf.id
    WHERE cfv.contact_id = ?
");
$stmt->execute([$contact_id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$details) {
    echo '<p class="text-gray-500">No additional details found for this contact.</p>';
} else {
    foreach ($details as $row) {
        echo '<div class="flex justify-between border-b border-gray-50 py-1">';
        echo '<span class="font-bold text-gray-500 capitalize">' . htmlspecialchars($row['field_label']) . ':</span>';
        echo '<span class="text-slate-800">' . htmlspecialchars($row['field_value']) . '</span>';
        echo '</div>';
    }
}
?>