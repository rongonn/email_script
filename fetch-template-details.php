<?php
require_once 'config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) exit('Unauthorized');

$template_id = intval($_GET['id'] ?? 0);
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;

$limit = 10;
$offset = ($page - 1) * $limit;

// 1. Get Total Count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE template_id = ?");
$count_stmt->execute([$template_id]);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Fetch Paginated Logs
$log_stmt = $pdo->prepare("
    SELECT el.*, c.email 
    FROM email_logs el 
    JOIN contacts c ON el.contact_id = c.id 
    WHERE el.template_id = ? 
    ORDER BY el.sent_at DESC 
    LIMIT ? OFFSET ?
");
$log_stmt->bindValue(1, $template_id, PDO::PARAM_INT);
$log_stmt->bindValue(2, $limit, PDO::PARAM_INT);
$log_stmt->bindValue(3, $offset, PDO::PARAM_INT);
$log_stmt->execute();
$logs = $log_stmt->fetchAll();

// 3. Stats
$stats_stmt = $pdo->prepare("SELECT SUM(is_opened) as opened, SUM(is_clicked) as clicked FROM email_logs WHERE template_id = ?");
$stats_stmt->execute([$template_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="grid grid-cols-3 gap-3 mb-6">
    <div class="bg-gray-50 p-3 rounded-lg border border-gray-100 text-center">
        <p class="text-[10px] uppercase font-bold text-gray-400">Total Sent</p>
        <p class="text-lg font-bold text-slate-900"><?php echo $total_rows; ?></p>
    </div>
    <div class="bg-emerald-50 p-3 rounded-lg border border-emerald-100 text-center">
        <p class="text-[10px] uppercase font-bold text-emerald-600">Opened</p>
        <p class="text-lg font-bold text-slate-900"><?php echo intval($stats['opened']); ?></p>
    </div>
    <div class="bg-indigo-50 p-3 rounded-lg border border-indigo-100 text-center">
        <p class="text-[10px] uppercase font-bold text-indigo-600">Clicked</p>
        <p class="text-lg font-bold text-slate-900"><?php echo intval($stats['clicked']); ?></p>
    </div>
</div>

<h2 class="text-sm font-bold text-slate-900 mb-4">Email Delivery History</h2>
<div class="overflow-x-auto">
    <table class="w-full text-left text-xs text-slate-600">
        <thead>
            <tr class="border-b border-gray-100 font-bold uppercase text-gray-400">
                <th class="py-2 px-2">Contact Email</th>
                <th class="py-2 px-2">Sent At</th>
                <th class="py-2 px-2 text-center">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach($logs as $log): ?>
            <tr>
                <td class="py-2 px-2"><?php echo htmlspecialchars($log['email']); ?></td>
                <td class="py-2 px-2"><?php echo htmlspecialchars($log['sent_at']); ?></td>
                <td class="py-2 px-2 text-center">
                    <?php echo $log['is_opened'] ? '<span class="text-emerald-600 font-bold">Opened</span>' : '<span class="text-gray-400">Sent</span>'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="mt-6 flex justify-center items-center gap-1">
    <?php 
    // Show Previous Button
    if ($page > 1): ?>
        <button onclick="openTemplateModal(<?php echo $template_id; ?>, <?php echo $page - 1; ?>)" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-xs font-bold text-slate-600 mr-2"><</button>
    <?php endif; 

    // Logic for 4-button range window
    $start_page = max(1, $page - 1);
    $end_page = min($total_pages, $start_page + 3);
    
    // Adjust if we are near the end
    if ($end_page - $start_page < 3 && $total_pages >= 4) {
        $start_page = max(1, $total_pages - 3);
        $end_page = $total_pages;
    }

    for ($i = $start_page; $i <= $end_page; $i++): ?>
        <button onclick="openTemplateModal(<?php echo $template_id; ?>, <?php echo $i; ?>)" 
            class="px-3 py-1 rounded text-xs font-bold <?php echo ($i == $page) ? 'bg-indigo-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-slate-600'; ?>">
            <?php echo $i; ?>
        </button>
    <?php endfor; 

    // Show Next Button
    if ($page < $total_pages): ?>
        <button onclick="openTemplateModal(<?php echo $template_id; ?>, <?php echo $page + 1; ?>)" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-xs font-bold text-slate-600 ml-2">></button>
    <?php endif; ?>
</div>
<?php endif; ?>