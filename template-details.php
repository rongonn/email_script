<?php
// template-details.php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$template_id = intval($_GET['id'] ?? 0);

// Fetch Template Info
$stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ? AND user_id = ?");
$stmt->execute([$template_id, $user_id]);
$template = $stmt->fetch();

if (!$template) {
    die("Template not found or access denied.");
}

// Fetch Logs for this template, joining with contacts to get the email address
$log_stmt = $pdo->prepare("
    SELECT el.*, c.email 
    FROM email_logs el 
    JOIN contacts c ON el.contact_id = c.id 
    WHERE el.template_id = ? 
    ORDER BY el.sent_at DESC
");
$log_stmt->execute([$template_id]);
$logs = $log_stmt->fetchAll();

// Calculate Stats
$total_sent = count($logs);
$total_opened = 0;
$total_clicked = 0;
foreach($logs as $l) {
    if(intval($l['is_opened']) === 1) $total_opened++;
    if(intval($l['is_clicked']) === 1) $total_clicked++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Template History - <?php echo htmlspecialchars($template['template_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-5xl mx-auto">
        <a href="email-templates.php" class="text-indigo-600 hover:underline mb-4 inline-block">&larr; Back to Templates</a>
        
        <h1 class="text-2xl font-bold text-gray-900 mb-6">History: <?php echo htmlspecialchars($template['template_name']); ?></h1>
        
        <div class="grid grid-cols-3 gap-6 mb-8">
            <div class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm">
                <p class="text-gray-400 text-xs uppercase font-bold">Total Sent</p>
                <p class="text-2xl font-bold text-slate-900"><?php echo $total_sent; ?></p>
            </div>
            <div class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm">
                <p class="text-gray-400 text-xs uppercase font-bold">Total Opened</p>
                <p class="text-2xl font-bold text-slate-900"><?php echo $total_opened; ?></p>
            </div>
            <div class="p-6 bg-white border border-gray-100 rounded-2xl shadow-sm">
                <p class="text-gray-400 text-xs uppercase font-bold">Total Clicked</p>
                <p class="text-2xl font-bold text-slate-900"><?php echo $total_clicked; ?></p>
            </div>
        </div>

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="p-4 font-bold text-gray-400 uppercase">Contact Email</th>
                        <th class="p-4 font-bold text-gray-400 uppercase">Sent At</th>
                        <th class="p-4 font-bold text-gray-400 uppercase text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="3" class="p-8 text-center text-gray-400">No logs found for this template.</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="p-4"><?php echo htmlspecialchars($log['email']); ?></td>
                            <td class="p-4"><?php echo htmlspecialchars($log['sent_at']); ?></td>
                            <td class="p-4 text-center">
                                <?php if($log['is_opened']): ?>
                                    <span class="text-emerald-600 bg-emerald-50 px-2 py-1 rounded">Opened</span>
                                <?php else: ?>
                                    <span class="text-gray-400 bg-gray-100 px-2 py-1 rounded">Sent</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>