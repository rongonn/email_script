<?php
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid login credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FireMailing AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link class="stylesheet" href="assets/style.css">
</head>

<body class="bg-gray-50 font-sans text-slate-700 antialiased flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-2xl border border-gray-100 shadow-xl max-w-md w-full space-y-6">
        <div class="text-center">
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Welcome Back</h2>
            <p class="text-sm text-gray-400 mt-1">Log in to your FireMailing AI account</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-50 text-rose-600 text-sm p-3.5 rounded-xl border border-rose-100"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Email Address</label>
                <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Password</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-indigo-500 text-sm transition-colors">

            </div>
            <button type="submit" class="w-full py-3 text-sm font-semibold text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 rounded-xl shadow-md transition-all">Log In</button>
        </form>
    </div>
</body>

</html>