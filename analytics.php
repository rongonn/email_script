<?php
require_once 'config/database.php';

// Security Check
check_auth();

$user_id = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| FILTER SYSTEM
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| FILTER SYSTEM & CHART LOGIC (Existing code remains same)
|--------------------------------------------------------------------------
*/
// ... [Keep your existing filter and chart data fetching logic here] ...

/*
|--------------------------------------------------------------------------
| RECENT EMAIL LOGS (PAGINATED)
|--------------------------------------------------------------------------
*/
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // 1. Fetch total logs for pagination count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE user_id = ?");
    $count_stmt->execute([$user_id]);
    $total_logs = $count_stmt->fetchColumn();
    $total_pages = ceil($total_logs / $limit);

    // 2. Fetch logs with join to contacts
    $logs_stmt = $pdo->prepare("
        SELECT el.*, c.email 
        FROM email_logs el
        LEFT JOIN contacts c ON el.contact_id = c.id
        WHERE el.user_id = ?
        ORDER BY el.sent_at DESC
        LIMIT ? OFFSET ?
    ");
    $logs_stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $logs_stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $logs_stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $logs_stmt->execute();
    $email_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $email_logs = [];
    $total_pages = 0;
}

$filter = $_GET['filter'] ?? '7days';

$start_date = null;
$end_date   = null;
$days_count = 7;

switch ($filter) {

    case 'today':

        $start_date = date('Y-m-d 00:00:00');
        $end_date   = date('Y-m-d 23:59:59');
        $days_count = 1;

        break;

    case '28days':

        $start_date = date('Y-m-d 00:00:00', strtotime('-27 days'));
        $end_date   = date('Y-m-d 23:59:59');
        $days_count = 28;

        break;

    case 'custom':

        $custom_start = $_GET['start_date'] ?? '';
        $custom_end   = $_GET['end_date'] ?? '';

        if (!empty($custom_start) && !empty($custom_end)) {

            $start_date = date('Y-m-d 00:00:00', strtotime($custom_start));
            $end_date   = date('Y-m-d 23:59:59', strtotime($custom_end));

            $diff = (strtotime($custom_end) - strtotime($custom_start)) / 86400;
            $days_count = max(1, $diff + 1);

        } else {

            $start_date = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $end_date   = date('Y-m-d 23:59:59');
            $days_count = 7;
        }

        break;

    default:

        $start_date = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $end_date   = date('Y-m-d 23:59:59');
        $days_count = 7;

        break;
}

/*
|--------------------------------------------------------------------------
| TOTAL STATS
|--------------------------------------------------------------------------
*/

try {

    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(id) as total_sent,
            SUM(CASE WHEN is_opened = 1 THEN 1 ELSE 0 END) as total_opened,
            SUM(CASE WHEN is_clicked = 1 THEN 1 ELSE 0 END) as total_clicked
        FROM email_logs
        WHERE user_id = ?
        AND sent_at BETWEEN ? AND ?
    ");

    $stats_stmt->execute([
        $user_id,
        $start_date,
        $end_date
    ]);

    $totals = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $total_sent    = intval($totals['total_sent'] ?? 0);
    $total_opened  = intval($totals['total_opened'] ?? 0);
    $total_clicked = intval($totals['total_clicked'] ?? 0);

    $open_rate = $total_sent > 0
        ? round(($total_opened / $total_sent) * 100, 1)
        : 0;

    $click_rate = $total_sent > 0
        ? round(($total_clicked / $total_sent) * 100, 1)
        : 0;

} catch (PDOException $e) {

    $total_sent = 0;
    $total_opened = 0;
    $total_clicked = 0;
    $open_rate = 0;
    $click_rate = 0;
}

/*
|--------------------------------------------------------------------------
| CHART DATA
|--------------------------------------------------------------------------
*/

$chart_days = [];

for ($i = $days_count - 1; $i >= 0; $i--) {

    $date_string = date('Y-m-d', strtotime("-$i days", strtotime($end_date)));

    $chart_days[$date_string] = [
        'label'   => date('M d', strtotime($date_string)),
        'sent'    => 0,
        'opened'  => 0,
        'clicked' => 0
    ];
}

try {

    $chart_stmt = $pdo->prepare("
        SELECT 
            DATE(sent_at) as log_date,
            COUNT(id) as daily_sent,
            SUM(CASE WHEN is_opened = 1 THEN 1 ELSE 0 END) as daily_opened,
            SUM(CASE WHEN is_clicked = 1 THEN 1 ELSE 0 END) as daily_clicked
        FROM email_logs
        WHERE user_id = ?
        AND sent_at BETWEEN ? AND ?
        GROUP BY DATE(sent_at)
        ORDER BY log_date ASC
    ");

    $chart_stmt->execute([
        $user_id,
        $start_date,
        $end_date
    ]);

    $daily_metrics = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($daily_metrics as $row) {

        if (isset($chart_days[$row['log_date']])) {

            $chart_days[$row['log_date']]['sent'] = intval($row['daily_sent']);

            $chart_days[$row['log_date']]['opened'] = intval($row['daily_opened']);

            $chart_days[$row['log_date']]['clicked'] = intval($row['daily_clicked']);
        }
    }

} catch (PDOException $e) {
    //
}

/*
|--------------------------------------------------------------------------
| MAX CHART HEIGHT
|--------------------------------------------------------------------------
*/

$max_value = 1;

foreach ($chart_days as $day) {

    if ($day['sent'] > $max_value) {
        $max_value = $day['sent'];
    }
}

/*
|--------------------------------------------------------------------------
| RECENT CONTACTS
|--------------------------------------------------------------------------
*/

try {

    $contacts_stmt = $pdo->prepare("
        SELECT name, email, status, created_at
        FROM contacts
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");

    $contacts_stmt->execute([$user_id]);

    $recent_contacts = $contacts_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $recent_contacts = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Analytics - FireMailing AI</title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

</head>

<body class="bg-gray-50 font-[Inter] text-slate-700">

<div class="flex min-h-screen overflow-hidden">

    <?php require_once 'components/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-y-auto">

        <?php require_once 'components/header.php'; ?>

        <main class="p-6 md:p-8 space-y-6 max-w-7xl mx-auto w-full">

            <!-- FILTER -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">

                <form method="GET" class="flex flex-wrap gap-4 items-end">

                    <div>

                        <label class="text-xs font-semibold text-slate-500 block mb-1">
                            Filter
                        </label>

                        <select
                            name="filter"
                            id="filterSelect"
                            class="border border-gray-200 rounded-xl px-4 py-2 text-sm focus:outline-none"
                        >
                            <option value="today" <?php if($filter=='today') echo 'selected'; ?>>
                                Today
                            </option>

                            <option value="7days" <?php if($filter=='7days') echo 'selected'; ?>>
                                Last 7 Days
                            </option>

                            <option value="28days" <?php if($filter=='28days') echo 'selected'; ?>>
                                Last 28 Days
                            </option>

                            <option value="custom" <?php if($filter=='custom') echo 'selected'; ?>>
                                Custom Date
                            </option>

                        </select>

                    </div>

                    <div
                        id="customFields"
                        class="<?php echo ($filter == 'custom') ? 'flex' : 'hidden'; ?> gap-4"
                    >

                        <div>

                            <label class="text-xs font-semibold text-slate-500 block mb-1">
                                Start Date
                            </label>

                            <input
                                type="date"
                                name="start_date"
                                value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>"
                                class="border border-gray-200 rounded-xl px-4 py-2 text-sm"
                            >

                        </div>

                        <div>

                            <label class="text-xs font-semibold text-slate-500 block mb-1">
                                End Date
                            </label>

                            <input
                                type="date"
                                name="end_date"
                                value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>"
                                class="border border-gray-200 rounded-xl px-4 py-2 text-sm"
                            >

                        </div>

                    </div>

                    <button
                        type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-all"
                    >
                        Apply Filter
                    </button>

                </form>

            </div>

            <!-- STATS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold uppercase text-gray-400">
                        Total Sent
                    </p>

                    <h2 class="text-3xl font-bold mt-2">
                        <?php echo number_format($total_sent); ?>
                    </h2>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold uppercase text-gray-400">
                        Total Opened
                    </p>

                    <h2 class="text-3xl font-bold mt-2">
                        <?php echo number_format($total_opened); ?>
                    </h2>

                    <p class="text-sm text-purple-600 font-semibold mt-2">
                        <?php echo $open_rate; ?>% Open Rate
                    </p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold uppercase text-gray-400">
                        Total Clicked
                    </p>

                    <h2 class="text-3xl font-bold mt-2">
                        <?php echo number_format($total_clicked); ?>
                    </h2>

                    <p class="text-sm text-sky-600 font-semibold mt-2">
                        <?php echo $click_rate; ?>% Click Rate
                    </p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-semibold uppercase text-gray-400">
                        Open Efficiency
                    </p>

                    <h2 class="text-3xl font-bold mt-2">
                        <?php echo $open_rate; ?>%
                    </h2>
                </div>

            </div>

            <!-- CHART -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">

                    <div>

                        <h2 class="text-lg font-bold text-slate-900">
                            Email Campaign Analytics
                        </h2>

                        <p class="text-sm text-gray-400">
                            Hover over chart bars to see detailed analytics
                        </p>

                    </div>

                    <!-- LEGEND -->
                    <div class="flex items-center gap-5 text-xs font-semibold">

                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-indigo-500"></span>
                            <span>Sent Emails</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-purple-500"></span>
                            <span>Opened Emails</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-sky-500"></span>
                            <span>Clicked Emails</span>
                        </div>

                    </div>

                </div>

                <!-- CHART -->
                <div class="relative">

                    <div class="h-80 border-l border-b border-gray-200 flex items-end gap-3 px-4 pb-3">

                        <?php foreach($chart_days as $dayData): ?>

                            <?php

                                $height_sent = ($dayData['sent'] / $max_value) * 100;

                                $height_open = ($dayData['sent'] > 0)
                                    ? ($dayData['opened'] / $dayData['sent']) * 100
                                    : 0;

                                $height_click = ($dayData['opened'] > 0)
                                    ? ($dayData['clicked'] / $dayData['opened']) * 100
                                    : 0;
                            ?>

                            <div class="flex-1 h-full flex flex-col justify-end items-center group relative">

                                <!-- TOOLTIP -->
                                <div class="absolute bottom-full mb-4 opacity-0 group-hover:opacity-100 pointer-events-none transition-all duration-200 z-50">

                                    <div class="bg-slate-900 text-white rounded-xl shadow-lg p-3 min-w-[160px]">

                                        <div class="text-xs font-bold border-b border-slate-700 pb-2 mb-2 text-indigo-300">
                                            <?php echo $dayData['label']; ?>
                                        </div>

                                        <div class="space-y-1 text-xs">

                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-slate-300">
                                                    Sent
                                                </span>

                                                <span class="font-bold text-indigo-400">
                                                    <?php echo $dayData['sent']; ?>
                                                </span>
                                            </div>

                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-slate-300">
                                                    Opened
                                                </span>

                                                <span class="font-bold text-purple-400">
                                                    <?php echo $dayData['opened']; ?>
                                                </span>
                                            </div>

                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-slate-300">
                                                    Clicked
                                                </span>

                                                <span class="font-bold text-sky-400">
                                                    <?php echo $dayData['clicked']; ?>
                                                </span>
                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <!-- BAR -->
                                <div
                                    class="w-full bg-indigo-500 rounded-t-xl relative overflow-hidden hover:opacity-90 transition-all duration-300"
                                    style="height: <?php echo max($height_sent, 4); ?>%;"
                                >

                                    <!-- OPEN -->
                                    <div
                                        class="absolute bottom-0 left-0 right-0 bg-purple-500"
                                        style="height: <?php echo $height_open; ?>%;"
                                    ></div>

                                    <!-- CLICK -->
                                    <div
                                        class="absolute bottom-0 left-0 right-0 bg-sky-500"
                                        style="height: <?php echo $height_click; ?>%;"
                                    ></div>

                                </div>

                                <!-- LABEL -->
                                <span class="text-[11px] text-gray-500 font-semibold mt-2">
                                    <?php echo $dayData['label']; ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </div>

            <!-- EMAIL LOGS -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                    <h2 class="text-lg font-bold text-slate-900">Recent Email Logs</h2>
                    <span class="text-xs text-gray-400">Showing 100 per page</span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-400 uppercase text-[10px] font-bold">
                            <tr>
                                <th class="px-6 py-4 text-left">Contact Email</th>
                                <th class="px-6 py-4 text-center">Status</th>
                                <th class="px-6 py-4 text-center">Clicked</th>
                                <th class="px-6 py-4 text-right">Sent At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach($email_logs as $log): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($log['email'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2 py-1 rounded-full text-[10px] font-bold <?php echo $log['is_opened'] ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'; ?>">
                                        <?php echo $log['is_opened'] ? 'OPENED' : 'SENT'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php echo $log['is_clicked'] ? '<i class="fa-solid fa-check text-sky-600"></i>' : '<span class="text-gray-300">-</span>'; ?>
                                </td>
                                <td class="px-6 py-4 text-right text-gray-500"><?php echo date('M d, Y H:i', strtotime($log['sent_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if($total_pages > 1): ?>
                <div class="p-4 border-t border-gray-100 flex justify-center gap-2">
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?filter=<?php echo $filter; ?>&p=<?php echo $i; ?>" 
                           class="px-3 py-1 text-xs rounded border <?php echo ($i == $page) ? 'bg-indigo-600 text-white' : 'hover:bg-gray-100'; ?>">
                           <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>

        </main>

    </div>

</div>

<script>

const filterSelect = document.getElementById('filterSelect');

const customFields = document.getElementById('customFields');

filterSelect.addEventListener('change', function () {

    if (this.value === 'custom') {

        customFields.classList.remove('hidden');

    } else {

        customFields.classList.add('hidden');
    }

});

</script>

<script>
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebar-overlay');
const toggleBtn = document.getElementById('mobile-toggle');

function openSidebar() {
    sidebar.classList.remove('-translate-x-full');
    sidebar.classList.add('translate-x-0');

    overlay.classList.remove('hidden');
}

function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    sidebar.classList.remove('translate-x-0');

    overlay.classList.add('hidden');
}

// OPEN
toggleBtn.addEventListener('click', function () {
    openSidebar();
});

// CLOSE when clicking overlay
overlay.addEventListener('click', function () {
    closeSidebar();
});

// CLOSE when clicking a menu item (mobile UX improvement)
document.querySelectorAll('#sidebar a').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth < 768) {
            closeSidebar();
        }
    });
});
</script>
</body>
</html>