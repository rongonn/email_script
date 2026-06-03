<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $new_password = $_POST['password'];

    try {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $stmt->execute([$name, $email, $user_id]);
        
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
        }

        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $success = "Profile updated successfully!";
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | FireMailing AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-700">

<div class="flex min-h-screen">
    <?php require_once 'components/sidebar.php'; ?>

    <div class="flex-1 flex flex-col">
        <?php require_once 'components/header.php'; ?>
        
        <main class="p-8 max-w-2xl mx-auto w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900">Profile Settings</h1>
                <p class="text-sm text-slate-500">Manage your account information and security.</p>
            </div>
            
            <?php if ($success): ?>
                <div class="bg-emerald-50 text-emerald-600 px-4 py-3 rounded-xl mb-6 text-sm border border-emerald-100 flex items-center">
                    <i class="fa-solid fa-check-circle mr-2"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-rose-50 text-rose-600 px-4 py-3 rounded-xl mb-6 text-sm border border-rose-100 flex items-center">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="bg-white p-8 rounded-2xl border border-slate-200 shadow-sm">
                <form method="POST" class="space-y-6">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Full Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" class="w-full border border-slate-200 px-4 py-3 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="w-full border border-slate-200 px-4 py-3 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition" required>
                    </div>
                    <div class="border-t pt-6">
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">New Password (optional)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full border border-slate-200 px-4 py-3 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition">
                        <p class="text-[10px] text-slate-400 mt-2">Leave blank if you don't want to change your password.</p>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold text-sm hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">
                        <i class="fa-solid fa-save mr-2"></i> Save Changes
                    </button>
                </form>
            </div>
        </main>
    </div>
</div>

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