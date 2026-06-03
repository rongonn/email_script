<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// --- 1. ACTION ROUTINE: RECORD DELETION PIPELINE ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del_id = intval($_GET['id']);
    $stmt = $pdo->prepare("DELETE FROM email_templates WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$del_id, $user_id])) {
        $success = "Sequence automation template record pulled out successfully.";
    } else {
        $error = "Failed to drop chosen template node configuration rules.";
    }
}

// --- 2. FETCH TEMPLATE LIST ---
$list_stmt = $pdo->prepare("
    SELECT et.*, camp.campaign_name
    FROM email_templates et
    JOIN campaigns camp ON et.campaign_id = camp.id
    WHERE et.user_id = ?
    ORDER BY et.created_at DESC
");
$list_stmt->execute([$user_id]);
$registered_templates = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates Ledger | FireMailing AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>

<body class="bg-slate-50 text-slate-700 antialiased">

<div class="flex min-h-screen">
    <?php require_once 'components/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <?php require_once 'components/header.php'; ?>

        <main class="p-6 md:p-8 space-y-8 max-w-7xl mx-auto w-full">

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Email Sequence Templates</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage, deploy, and organize your automated messaging strategies.</p>
                </div>
                <a href="create-template.php" class="inline-flex items-center px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-indigo-200 transition-all">
                    <i class="fa-solid fa-plus mr-2"></i> Create Template
                </a>
            </div>

            <?php if ($error): ?>
                <div class="bg-rose-50 text-rose-600 text-xs px-4 py-3 rounded-lg border border-rose-100 flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="bg-emerald-50 text-emerald-600 text-xs px-4 py-3 rounded-lg border border-emerald-100 flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-check mr-2"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-900">Active Sequence Matrix</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                                <th class="py-4 px-6">Template Name</th>
                                <th class="py-4 px-6">Campaign</th>
                                <th class="py-4 px-6 text-center">Timing</th>
                                <th class="py-4 px-6 text-center">Format</th>
                                <th class="py-4 px-6">Created</th>
                                <th class="py-4 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($registered_templates)): ?>
                                <tr><td colspan="6" class="py-12 text-center text-slate-400">No templates found.</td></tr>
                            <?php else: foreach ($registered_templates as $row): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors group">
                                    <td class="py-4 px-6">
                                        <button onclick="openTemplateModal(<?php echo $row['id']; ?>)" class="block font-semibold text-slate-900 hover:text-indigo-600">
                                            <?php echo htmlspecialchars($row['template_name']); ?>
                                        </button>
                                        <p class="text-[11px] text-slate-400 truncate max-w-[200px]">Subj: <?php echo htmlspecialchars($row['subject']); ?></p>
                                    </td>
                                    <td class="py-4 px-6">
                                        <span class="px-2.5 py-1 rounded-md bg-indigo-50 text-indigo-700 text-[10px] font-bold border border-indigo-100">
                                            <?php echo htmlspecialchars($row['campaign_name']); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="text-[11px] font-medium <?php echo $row['delay_days'] == 0 ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                            <?php echo $row['delay_days'] == 0 ? 'Instant' : 'Day ' . $row['delay_days']; ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded <?php echo $row['is_html'] ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'; ?>">
                                            <?php echo $row['is_html'] ? 'HTML' : 'TXT'; ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-[11px] text-slate-500">
                                        <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                    </td>
                                    <td class="py-4 px-6 text-right space-x-2">
                                        <a href="create-template.php?action=edit&id=<?php echo $row['id']; ?>" class="text-slate-400 hover:text-indigo-600 transition">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="email-templates.php?action=delete&id=<?php echo $row['id']; ?>" onclick="return confirm('Permanently delete this template?');" class="text-slate-400 hover:text-rose-600 transition">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<div id="templateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-6 relative max-h-[80vh] overflow-y-auto">
        <button onclick="document.getElementById('templateModal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div id="modalContent">Loading history...</div>
    </div>
</div>

<script>
function openTemplateModal(templateId, page = 1) {
    const modal = document.getElementById('templateModal');
    const content = document.getElementById('modalContent');
    modal.classList.remove('hidden');
    content.innerHTML = `<div class="py-10 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading...</div>`;
    fetch(`fetch-template-details.php?id=${templateId}&p=${page}`)
        .then(res => res.text())
        .then(html => content.innerHTML = html)
        .catch(() => content.innerHTML = `<div class="py-10 text-center text-rose-500">Failed to load content.</div>`);
}
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