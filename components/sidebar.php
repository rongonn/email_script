<!-- components/sidebar.php -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-gray-400 transform -translate-x-full md:translate-x-0 transition-transform duration-300 flex flex-col justify-between border-r border-slate-800 shadow-xl">    <div>
        <!-- Brand Logo -->
        <div class="h-20 flex items-center px-6 border-b border-slate-800 gap-3">
            <div class="w-9 h-9 bg-gradient-to-tr from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center text-white text-lg font-bold shadow-md">
                <i class="fa-solid fa-fire-burner text-sm"></i>
            </div>
            <span class="text-white text-xl font-bold tracking-tight">FireMailing<span class="text-indigo-500">.</span></span>
        </div>

        <!-- User Profile Summary -->
        <div class="p-5 border-b border-slate-800 flex items-center gap-3">
            <div class="relative">
                <div class="w-11 h-11 rounded-full bg-slate-700 flex items-center justify-center text-white border-2 border-indigo-500 font-semibold shadow-inner">
                    <?php
                    $words = explode(" ", $_SESSION['user_name']);
                    $initials = (isset($words[0][0]) ? $words[0][0] : '') . (isset($words[1][0]) ? $words[1][0] : '');
                    echo htmlspecialchars(strtoupper($initials));
                    ?>
                </div>
                <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-slate-900 rounded-full"></span>
            </div>
            <div class="min-w-0 flex-1">
                <h4 class="text-white font-medium text-sm tracking-wide truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                <span class="text-xs text-slate-500 font-light">Marketer</span>
            </div>
        </div>

        <!-- Navigation Links -->
        <nav class="p-4 space-y-1.5">
            <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

            <a href="index.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl transition-all group <?php echo ($current_page == 'index.php') ? 'text-white bg-indigo-600/10 border-l-4 border-indigo-500' : 'hover:bg-slate-800 hover:text-white border-l-4 border-transparent'; ?>">
                <i class="fa-solid fa-chart-pie w-5 text-center transition-colors <?php echo ($current_page == 'index.php') ? 'text-indigo-400' : 'group-hover:text-indigo-400'; ?>"></i>
                <span>Dashboard</span>
            </a>
            <a href="analytics.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl transition-all group <?php echo ($current_page == 'analytics.php') ? 'text-white bg-indigo-600/10 border-l-4 border-indigo-500' : 'hover:bg-slate-800 hover:text-white border-l-4 border-transparent'; ?>">
        <i class="fa-solid fa-chart-line w-5 text-center transition-colors <?php echo ($current_page == 'analytics.php') ? 'text-indigo-400' : 'group-hover:text-indigo-400'; ?>"></i>
        <span>Analytics</span>
    </a>
            <a href="campaigns.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl transition-all group <?php echo ($current_page == 'campaigns.php') ? 'text-white bg-indigo-600/10 border-l-4 border-indigo-500' : 'hover:bg-slate-800 hover:text-white border-l-4 border-transparent'; ?>">
                <i class="fa-solid fa-bullhorn w-5 text-center transition-colors <?php echo ($current_page == 'campaigns.php') ? 'text-indigo-400' : 'group-hover:text-indigo-400'; ?>"></i>
                <span>Campaigns</span>
            </a>
            <a href="add-contact.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-user-plus w-5 text-center group-hover:text-indigo-400"></i>
                <span>Add Contacts</span>
            </a>
            <a href="all-contacts.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-user-group w-5 text-center group-hover:text-indigo-400"></i>
                <span>All Contacts</span>
            </a>
            <a href="email-templates.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-envelope-open-text w-5 text-center group-hover:text-indigo-400"></i>
                <span>Set Templates</span>
            </a>
            <a href="template-library.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-folder-open w-5 text-center group-hover:text-indigo-400"></i>
                <span>Template Library</span>
            </a>
            <a href="manage-users.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-users w-5 text-center group-hover:text-indigo-400"></i>
                <span>Manage Users</span>
            </a>
            <a href="update-account.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-slate-800 hover:text-white border-l-4 border-transparent transition-all group">
                <i class="fa-regular fa-user w-5 text-center group-hover:text-indigo-400"></i>
                <span>Update Account</span>
            </a>
            <!-- New SMTP Setting Menu Item -->
            <a href="smtp-settings.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl transition-all group <?php echo ($current_page == 'smtp-settings.php') ? 'text-white bg-indigo-600/10 border-l-4 border-indigo-500' : 'hover:bg-slate-800 hover:text-white border-l-4 border-transparent'; ?>">
                <i class="fa-solid fa-server w-5 text-center transition-colors <?php echo ($current_page == 'smtp-settings.php') ? 'text-indigo-400' : 'group-hover:text-indigo-400'; ?>"></i>
                <span>SMTP Setting</span>
            </a>
            <a href="logout.php" class="flex items-center gap-3.5 px-4 py-3 text-sm font-medium rounded-xl hover:bg-rose-950/30 hover:text-rose-400 border-l-4 border-transparent transition-all group">
                <i class="fa-solid fa-arrow-right-from-bracket w-5 text-center text-rose-500/70 group-hover:text-rose-400"></i>
                <span>Logout</span>
            </a>
        </nav>
    </div>

    <div class="p-4 border-t border-slate-800 text-xs text-center text-slate-600 font-light">
        &copy; 2026 FireMailing AI
    </div>
</aside>
<div id="sidebar-overlay" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden md:hidden"></div>