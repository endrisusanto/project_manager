<?php
// File ini mengambil data sesi yang sudah dimulai oleh file pemanggil (misal: index.php)
$user_details = $_SESSION['user_details'] ?? ['profile_picture' => 'default.png', 'email' => 'user@example.com'];
$username = $_SESSION['username'] ?? 'User';
?>
<header class="glass-container sticky top-0 z-20 shadow-sm flex-shrink-0">
    <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center space-x-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                <h1 class="text-xl font-bold text-header">Google Build Approval Task Manager</h1>
                <div class="hidden md:flex items-baseline space-x-4 ml-4">
                    <a href="index.php" class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'project_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">Kanban Board</a>
                    <a href="gba_dashboard.php" class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">GBA Dashboard</a>
                    <a href="gba_tasks.php" class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_tasks') ? 'nav-link-active' : 'nav-link'; ?>">GBA Tasks</a>
                    <a href="gba_tasks_summary.php" class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_tasks_summary') ? 'nav-link-active' : 'nav-link'; ?>">Summary</a>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <?php if (in_array($active_page, ['project_dashboard', 'gba_tasks', 'gba_tasks_summary'])): ?>
                    <div class="relative flex-grow">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"><svg class="h-5 w-5 text-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg></div>
                        <input type="search" id="search-input" placeholder="Cari..." class="themed-input block w-full rounded-lg py-2 pl-10 pr-3 focus:ring-2">
                    </div>
                <?php endif; ?>
                
                <?php if ($active_page === 'project_dashboard'): ?>
                    <button id="view-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5">
                        <svg id="view-toggle-full-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        <svg id="view-toggle-accordion-icon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                    </button>
                <?php endif; ?>
                
                <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5"><svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg><svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg></button>

                <?php if (is_admin()): ?>
                    <a href="bulk_add.php" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500">
                        <svg class="-ml-0.5 mr-1.5 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                           <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.125 1.125 0 010 2.25H5.625a1.125 1.125 0 010-2.25z" />
                        </svg>
                        Bulk Add
                    </a>
                    <button onclick="openAddModal()" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"><svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>Task Baru</button>
                <?php endif; ?>

                <div class="relative" id="profile-menu">
                    <button class="flex items-center space-x-2 focus:outline-none">
                        <img src="uploads/<?php echo htmlspecialchars($user_details['profile_picture']); ?>" alt="Avatar" class="w-9 h-9 rounded-full object-cover border-2 border-transparent hover:border-blue-500 transition">
                        <span class="text-sm font-medium hidden md:block text-header"><?php echo htmlspecialchars($username); ?></span>
                    </button>
                    <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg py-1 z-50 border border-gray-700">
                        <div class="px-4 py-3 border-b border-gray-700">
                            <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars($username); ?></p>
                            <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($user_details['email'] ?? ''); ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Profil Saya</a>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>