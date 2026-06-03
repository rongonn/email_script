<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle Delete Request
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $campaign_id = intval($_GET['id']);
    
    // Security check: Ensure the campaign belongs to the logged-in user
    $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$campaign_id, $user_id])) {
        $success = "Campaign deleted successfully.";
    } else {
        $error = "Failed to delete the campaign.";
    }
}

// Handle Add / Edit Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campaign_name = trim($_POST['campaign_name']);
    $status = trim($_POST['status'] ?? 'Draft');
    $campaign_id = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : null;

    if (empty($campaign_name)) {
        $error = "Campaign name cannot be empty.";
    } else {
        if ($campaign_id) {
            // Update Existing Campaign (WithOwner Check)
            $stmt = $pdo->prepare("UPDATE campaigns SET campaign_name = ?, status = ? WHERE id = ? AND user_id = ?");
            if ($stmt->execute([$campaign_name, $status, $campaign_id, $user_id])) {
                $success = "Campaign updated successfully.";
            } else {
                $error = "Failed to update campaign.";
            }
        } else {
            // Create New Campaign
            $stmt = $pdo->prepare("INSERT INTO campaigns (user_id, campaign_name, status) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $campaign_name, $status])) {
                $success = "Campaign created successfully.";
            } else {
                $error = "Failed to create campaign.";
            }
        }
    }
}

// If Editing, Fetch Target Data
$edit_campaign = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $edit_campaign = $stmt->fetch();
}

// Fetch All Campaigns for the Table List View
$stmt = $pdo->prepare("SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$campaigns = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Campaigns - FireMailing AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-gray-50 font-sans text-slate-700 antialiased">

    <div class="flex min-h-screen overflow-hidden">
        
        <?php require_once 'components/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
            
            <?php require_once 'components/header.php'; ?>

            <main class="flex-grow p-6 md:p-8 space-y-6 max-w-7xl w-full mx-auto">
                
                <!-- Feedback Notifications -->
                <?php if ($error): ?>
                    <div class="bg-rose-50 text-rose-600 text-sm p-3.5 rounded-xl border border-rose-100"><i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-emerald-50 text-emerald-600 text-sm p-3.5 rounded-xl border border-emerald-100"><i class="fa-solid fa-circle-check mr-2"></i><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    
                    <!-- Left/Top Side: Dynamic Add/Edit Form Panel -->
                    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">
                        <div class="border-b border-gray-100 pb-4 mb-5">
                            <h2 class="text-lg font-bold text-slate-900 tracking-tight">
                                <?php echo $edit_campaign ? 'Modify Campaign' : 'Create New Campaign'; ?>
                            </h2>
                            <p class="text-xs text-gray-400 mt-0.5">Organize and label marketing broadcasts.</p>
                        </div>

                        <form action="campaigns.php" method="POST" class="space-y-4">
                            <?php if ($edit_campaign): ?>
                                <input type="hidden" name="campaign_id" value="<?php echo $edit_campaign['id']; ?>">
                            <?php endif; ?>

                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Campaign Name</label>
                                <input type="text" name="campaign_name" value="<?php echo htmlspecialchars($edit_campaign['campaign_name'] ?? ''); ?>" placeholder="e.g., Summer Product Launch 2026" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Status Initialization</label>
                                <select name="status" class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-white focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                                    <option value="Draft" <?php echo (($edit_campaign['status'] ?? '') === 'Draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="Scheduled" <?php echo (($edit_campaign['status'] ?? '') === 'Scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="Sent" <?php echo (($edit_campaign['status'] ?? '') === 'Sent') ? 'selected' : ''; ?>>Sent</option>
                                </select>
                            </div>

                            <div class="pt-2 flex gap-2">
                                <button type="submit" class="flex-1 py-3 text-sm font-semibold text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 rounded-xl shadow-md transition-all hover:-translate-y-0.5">
                                    <i class="fa-solid fa-square-check mr-2"></i><?php echo $edit_campaign ? 'Update' : 'Save Campaign'; ?>
                                </button>
                                <?php if ($edit_campaign): ?>
                                    <a href="campaigns.php" class="px-4 py-3 text-sm font-semibold text-slate-500 bg-gray-100 hover:bg-gray-200 rounded-xl transition-all flex items-center justify-center">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Right/Bottom Side: Table Listing View -->
                    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 lg:col-span-2 overflow-hidden">
                        <div class="border-b border-gray-100 pb-4 mb-5">
                            <h2 class="text-lg font-bold text-slate-900 tracking-tight">Active Campaigns Ledger</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Total Registered: <?php echo count($campaigns); ?></p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-slate-600">
                                <thead>
                                    <tr class="border-b border-gray-100 text-xs font-bold uppercase tracking-wider text-gray-400 bg-gray-50/50">
                                        <th class="py-3 px-4">Campaign Track</th>
                                        <th class="py-3 px-4">Status</th>
                                        <th class="py-3 px-4">Created Date</th>
                                        <th class="py-3 px-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100/70">
                                    <?php if (empty($campaigns)): ?>
                                        <tr>
                                            <td colspan="4" class="py-8 text-center text-gray-400 text-xs">No active email campaigns found. Use the configuration form to generate your first log workspace.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($campaigns as $row): ?>
                                            <tr class="hover:bg-gray-50/40 transition-colors">
                                                <td class="py-4 px-4 font-medium text-slate-900"><?php echo htmlspecialchars($row['campaign_name']); ?></td>
                                                <td class="py-4 px-4">
                                                    <?php 
                                                        $badgeColor = 'bg-gray-100 text-gray-600';
                                                        if ($row['status'] === 'Scheduled') $badgeColor = 'bg-amber-50 text-amber-600 border border-amber-100';
                                                        if ($row['status'] === 'Sent') $badgeColor = 'bg-emerald-50 text-emerald-600 border border-emerald-100';
                                                    ?>
                                                    <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?php echo $badgeColor; ?>">
                                                        <?php echo htmlspecialchars($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="py-4 px-4 text-xs text-gray-400"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                                <td class="py-4 px-4 text-right space-x-2">
                                                    <a href="campaigns.php?action=edit&id=<?php echo $row['id']; ?>" class="inline-flex w-8 h-8 rounded-lg bg-indigo-50 hover:bg-indigo-500 text-indigo-500 hover:text-white items-center justify-center text-xs transition-all" title="Edit"><i class="fa-solid fa-pen-to-square"></i></a>
                                                    <a href="campaigns.php?action=delete&id=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to permanently wipe this campaign? All logs will be deleted.');" class="inline-flex w-8 h-8 rounded-lg bg-rose-50 hover:bg-rose-500 text-rose-500 hover:text-white items-center justify-center text-xs transition-all" title="Delete"><i class="fa-solid fa-trash-can"></i></a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

            </main>
        </div>
    </div>

    <script>
        const mobileToggleBtn = document.getElementById('mobile-toggle');
        const sidebarEl = document.getElementById('sidebar');
        const overlayEl = document.getElementById('sidebar-overlay');

        function toggleSidebar() {
            sidebarEl.classList.toggle('-translate-x-full');
            overlayEl.classList.toggle('hidden');
        }

        mobileToggleBtn.addEventListener('click', toggleSidebar);
        overlayEl.addEventListener('click', toggleSidebar);
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