<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];

/* =========================================================
   AJAX API HANDLER (NO PAGE RELOAD)
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {

    $range = $_GET['range'] ?? 'all';
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    $date_condition = "";
    $params = [$user_id];

    if ($range === 'today') {
        $date_condition = " AND DATE(created_at) = CURDATE() ";
    } elseif ($range === '7days') {
        $date_condition = " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ";
    } elseif ($range === '28days') {
        $date_condition = " AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY) ";
    } elseif ($range === 'custom' && $start_date && $end_date) {
        $date_condition = " AND DATE(created_at) BETWEEN ? AND ? ";
        array_push($params, $start_date, $end_date);
    }

    function countData($pdo, $sql, $params){
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    $contacts = countData($pdo, "SELECT COUNT(*) FROM contacts WHERE user_id = ? $date_condition", $params);
    $campaigns = countData($pdo, "SELECT COUNT(*) FROM campaigns WHERE user_id = ?", [$user_id]);
    $templates = countData($pdo, "SELECT COUNT(*) FROM email_templates WHERE user_id = ?", [$user_id]);
    $sent = countData($pdo, "SELECT COUNT(*) FROM email_logs WHERE user_id = ?", [$user_id]);

    $recent = $pdo->prepare("
        SELECT c.email, camp.campaign_name, c.sending_status
        FROM contacts c
        JOIN campaigns camp ON c.campaign_id = camp.id
        WHERE c.user_id = ?
        ORDER BY c.id DESC LIMIT 5
    ");
    $recent->execute([$user_id]);

    echo json_encode([
        "contacts" => $contacts,
        "campaigns" => $campaigns,
        "templates" => $templates,
        "sent" => $sent,
        "recent" => $recent->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Dashboard</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>

</head>

<body class="bg-slate-50">

<div class="flex">

<?php require_once 'components/sidebar.php'; ?>

<div class="flex-1">

<?php require_once 'components/header.php'; ?>

<div class="p-6 max-w-7xl mx-auto space-y-6">

<!-- ================= FILTER ================= -->
<div class="bg-white p-4 rounded-xl border flex flex-wrap gap-3 items-center">

<select id="range" class="border rounded px-3 py-2 text-sm">
    <option value="all">All</option>
    <option value="today">Today</option>
    <option value="7days">7 Days</option>
    <option value="28days">28 Days</option>
    <option value="custom">Custom</option>
</select>

<input type="date" id="start_date" class="border rounded px-2 py-2 text-sm">
<input type="date" id="end_date" class="border rounded px-2 py-2 text-sm">

<button onclick="loadData()" class="bg-indigo-600 text-white px-4 py-2 rounded text-sm">
Filter
</button>

</div>

<!-- ================= STATS ================= -->
<div class="grid grid-cols-4 gap-4">

<div class="bg-white p-4 rounded-xl border">
<p class="text-xs">Contacts</p>
<h2 id="contacts" class="text-3xl font-bold">0</h2>
</div>

<div class="bg-white p-4 rounded-xl border">
<p class="text-xs">Campaigns</p>
<h2 id="campaigns" class="text-3xl font-bold">0</h2>
</div>

<div class="bg-white p-4 rounded-xl border">
<p class="text-xs">Templates</p>
<h2 id="templates" class="text-3xl font-bold">0</h2>
</div>

<div class="bg-white p-4 rounded-xl border">
<p class="text-xs">Sent</p>
<h2 id="sent" class="text-3xl font-bold">0</h2>
</div>

</div>

<!-- ================= CHART ================= -->
<div class="bg-white p-5 border rounded-xl">
<canvas id="chart"></canvas>
</div>

<!-- ================= TABLE ================= -->
<div class="bg-white border rounded-xl">
<div class="p-4 border-b font-bold">Latest Contacts</div>

<table class="w-full text-sm">
<thead class="bg-slate-50 text-xs">
<tr>
<th class="p-3">Email</th>
<th class="p-3">Campaign</th>
<th class="p-3">Status</th>
</tr>
</thead>
<tbody id="tableBody"></tbody>
</table>
</div>

</div>
</div>

</div>

<script>
let chart;

function animate(id, value){
    let el = document.getElementById(id);
    let start = 0;
    let step = Math.ceil(value / 50);

    function run(){
        start += step;
        if(start >= value){
            el.innerText = value;
        } else {
            el.innerText = start;
            requestAnimationFrame(run);
        }
    }
    run();
}

function loadData(){

    let range = document.getElementById('range').value;
    let start = document.getElementById('start_date').value;
    let end = document.getElementById('end_date').value;

    fetch(`?ajax=1&range=${range}&start_date=${start}&end_date=${end}`)
    .then(res => res.json())
    .then(data => {

        animate('contacts', data.contacts);
        animate('campaigns', data.campaigns);
        animate('templates', data.templates);
        animate('sent', data.sent);

        let tbody = '';
        data.recent.forEach(r => {
            tbody += `
                <tr class="border-t">
                    <td class="p-3">${r.email}</td>
                    <td class="p-3">${r.campaign_name}</td>
                    <td class="p-3">${r.sending_status}</td>
                </tr>
            `;
        });
        document.getElementById('tableBody').innerHTML = tbody;

        updateChart(data);
    });
}

function updateChart(data){

    if(chart) chart.destroy();

    chart = new Chart(document.getElementById('chart'), {
        type: 'bar',
        data: {
            labels: ['Contacts','Campaigns','Templates','Sent'],
            datasets: [{
                data: [
                    data.contacts,
                    data.campaigns,
                    data.templates,
                    data.sent
                ],
                backgroundColor: ['#3b82f6','#6366f1','#a855f7','#10b981']
            }]
        }
    });
}

loadData();
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