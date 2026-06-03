<?php
require_once 'config/database.php';
check_auth();

$error = '';
$success = '';

// --- ACTIONS: CREATE, DELETE ---
// Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $success = "User account removed.";
}

// Create Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$name, $email, $password]);
    $success = "User created successfully.";
}

// Fetch All Users
$users = $pdo->query("SELECT id, name, email, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management | FireMailing AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-700">
<div class="flex min-h-screen">
    <?php require_once 'components/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <?php require_once 'components/header.php'; ?>
        <main class="p-8 max-w-7xl mx-auto w-full space-y-6">
            <h1 class="text-2xl font-bold text-slate-900">User Directory</h1>

            <?php if ($error): ?><div class="bg-rose-50 text-rose-600 p-4 rounded-xl text-sm border border-rose-100"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($success): ?><div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl text-sm border border-emerald-100"><?php echo $success; ?></div><?php endif; ?>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <h2 class="text-xs font-bold uppercase tracking-widest text-slate-400 mb-4">Add New User</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <input type="text" name="name" placeholder="Full Name" class="border border-slate-200 px-4 py-2.5 rounded-xl text-sm" required>
                    <input type="email" name="email" placeholder="Email Address" class="border border-slate-200 px-4 py-2.5 rounded-xl text-sm" required>
                    <input type="password" name="password" placeholder="Password" class="border border-slate-200 px-4 py-2.5 rounded-xl text-sm" required>
                    <button type="submit" name="create_user" class="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-bold shadow-sm transition">
                        <i class="fa-solid fa-user-plus mr-2"></i> Create Account
                    </button>
                </form>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-400 text-[10px] uppercase font-bold">
                        <tr>
                            <th class="px-6 py-4 text-left">Name</th>
                            <th class="px-6 py-4 text-left">Email</th>
                            <th class="px-6 py-4 text-left">Joined</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="px-6 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars($user['name']); ?></td>
                            <td class="px-6 py-4 text-slate-500"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-6 py-4 text-slate-400"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td class="px-6 py-4 text-right space-x-3">
                                <a href="?action=delete&id=<?php echo $user['id']; ?>" onclick="return confirm('Are you sure?')" class="text-rose-500 hover:text-rose-700"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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