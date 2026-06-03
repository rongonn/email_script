<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Form controller processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['smtp_host']);
    $port = intval($_POST['smtp_port']);
    $username = trim($_POST['smtp_username']);
    $password = trim($_POST['smtp_password']);
    $encryption = trim($_POST['smtp_encryption']);
    $from_email = trim($_POST['from_email']);
    $from_name = trim($_POST['from_name']);

    if (empty($host) || empty($port) || empty($username) || empty($password) || empty($from_email) || empty($from_name)) {
        $error = "All fields except encryption method details are required.";
    } elseif (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid sender email address format.";
    } else {
        // Upsert configuration log statement block
        $stmt = $pdo->prepare("SELECT id FROM smtp_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $sql = "UPDATE smtp_settings SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, smtp_encryption = ?, from_email = ?, from_name = ? WHERE user_id = ?";
            $params = [$host, $port, $username, $password, $encryption, $from_email, $from_name, $user_id];
        } else {
            $sql = "INSERT INTO smtp_settings (smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [$host, $port, $username, $password, $encryption, $from_email, $from_name, $user_id];
        }

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            $success = "SMTP server structural parameters configuration verified and saved successfully.";
        } else {
            $error = "Internal file updates structural error occurred. Please contact network team.";
        }
    }
}

// Fetch active profile criteria
$stmt = $pdo->prepare("SELECT * FROM smtp_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$smtp = $stmt->fetch() ?: [
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'from_email' => '',
    'from_name' => ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Configuration - FireMailing AI</title>
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

            <main class="flex-grow p-6 md:p-8 space-y-6 max-w-4xl w-full mx-auto">
                
                <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
                    <div class="border-b border-gray-100 pb-5 mb-6">
                        <h2 class="text-xl font-bold text-slate-900 tracking-tight">SMTP Delivery Server Settings</h2>
                        <p class="text-xs text-gray-400 mt-1">Configure your outgoing custom mailing engine pathways to transmit secure communications.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="mb-5 bg-rose-50 text-rose-600 text-sm p-3.5 rounded-xl border border-rose-100"><i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="mb-5 bg-emerald-50 text-emerald-600 text-sm p-3.5 rounded-xl border border-emerald-100"><i class="fa-solid fa-circle-check mr-2"></i><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <form action="smtp-settings.php" method="POST" class="space-y-6">
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtp['smtp_host']); ?>" placeholder="smtp.mailgun.org" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">SMTP Port</label>
                                <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtp['smtp_port']); ?>" placeholder="587" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">SMTP Username</label>
                                <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($smtp['smtp_username']); ?>" placeholder="postmaster@yourdomain.com" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">SMTP Password</label>
                                <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($smtp['smtp_password']); ?>" placeholder="••••••••••••" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Encryption Type</label>
                                <select name="smtp_encryption" class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-white focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                                    <option value="tls" <?php echo ($smtp['smtp_encryption'] == 'tls') ? 'selected' : ''; ?>>TLS (Recommended)</option>
                                    <option value="ssl" <?php echo ($smtp['smtp_encryption'] == 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                    <option value="none" <?php echo ($smtp['smtp_encryption'] == 'none') ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">From Email Address</label>
                                <input type="email" name="from_email" value="<?php echo htmlspecialchars($smtp['from_email']); ?>" placeholder="hello@yourdomain.com" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">From Name</label>
                                <input type="text" name="from_name" value="<?php echo htmlspecialchars($smtp['from_name']); ?>" placeholder="FireMailing Marketing Team" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex justify-end">
                            <button type="submit" class="px-6 py-3 text-sm font-semibold text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 rounded-xl shadow-md transition-all hover:-translate-y-0.5">
                                <i class="fa-solid fa-floppy-disk mr-2"></i> Save SMTP Configuration
                            </button>
                        </div>
                    </form>
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