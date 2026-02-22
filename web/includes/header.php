<?php
// Shared HTML header + nav
// Usage: include 'includes/header.php';
// Set $page_title before including.

$page_title = $page_title ?? 'Misconduct Tracker';

// Active nav detection
$_nav_page = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$_nav_view = $_GET['view'] ?? '';
function nav_active(string $page, string $view = ''): string {
    global $_nav_page, $_nav_view;
    if ($page !== $_nav_page) return '';
    if ($view !== '' && $view !== $_nav_view) return '';
    if ($view === '' && in_array($_nav_view, ['teams', 'divisions', 'discrepancies'])) return '';
    return 'text-accent font-semibold';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary:  '#2d6a4f',
                        accent:   '#52b788',
                        warning:  '#d97706',
                        danger:   '#dc2626',
                    }
                }
            }
        }
    </script>
    <style>
        /* Card status colour helpers */
        .status-green { @apply bg-green-50 text-green-800; }
        .status-amber { @apply bg-amber-50 text-amber-800; }
        .status-red   { @apply bg-red-50   text-red-800;   }

        tr.status-green td { background-color: #f0fdf4; }
        tr.status-amber td { background-color: #fffbeb; }
        tr.status-red   td { background-color: #fef2f2; }

        .badge-guest { @apply ml-1 text-xs bg-blue-100 text-blue-700 px-1 rounded; }

        /* Mobile card layout for #player-table */
        @media (max-width: 639px) {
            #player-table thead { display: none; }
            #player-table tbody tr {
                display: block;
                border: 1px solid #e5e7eb;
                border-radius: 0.5rem;
                margin-bottom: 0.5rem;
                overflow: hidden;
            }
            #player-table tbody tr.status-amber { background-color: #fffbeb; }
            #player-table tbody tr.status-red   { background-color: #fef2f2; }
            #player-table tbody tr.status-green { background-color: #f0fdf4; }
            #player-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.3rem 0.75rem;
                border-top: 1px solid rgba(0,0,0,0.05);
                background-color: transparent !important;
                font-size: 0.875rem;
            }
            #player-table tbody td:first-child {
                display: block;
                border-top: none;
                padding: 0.6rem 0.75rem;
                font-size: 0.9375rem;
                background-color: rgba(0,0,0,0.025) !important;
            }
            #player-table tbody td[data-label]::before {
                content: attr(data-label);
                color: #9ca3af;
                font-size: 0.7rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                flex-shrink: 0;
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans">

<nav class="bg-primary text-white shadow-md">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-2 font-bold text-lg tracking-tight">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                <circle cx="10" cy="10" r="9" stroke="white" stroke-width="1.5" fill="none"/>
                <path d="M10 2 L10 18 M2 10 L18 10" stroke="white" stroke-width="1"/>
            </svg>
            Misconduct Tracker
        </a>
        <!-- Hamburger button (mobile only) -->
        <button id="nav-toggle" class="md:hidden p-1 rounded hover:bg-white/10 transition-colors" aria-label="Toggle navigation">
            <svg id="nav-icon-menu" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <svg id="nav-icon-close" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        <!-- Desktop nav links -->
        <div class="hidden md:flex gap-6 text-sm">
            <a href="index.php" class="hover:text-accent transition-colors <?= nav_active('index.php') ?>">Dashboard</a>
            <a href="team.php" class="hover:text-accent transition-colors <?= nav_active('team.php') ?>">Teams</a>
            <a href="division.php" class="hover:text-accent transition-colors <?= nav_active('division.php') ?>">Divisions</a>
            <a href="index.php?view=teams" class="hover:text-accent transition-colors <?= nav_active('index.php', 'teams') ?>">Discipline Rankings</a>
            <a href="index.php?view=discrepancies" class="hover:text-accent transition-colors <?= nav_active('index.php', 'discrepancies') ?>">Discrepancies</a>
        </div>
    </div>
    <!-- Mobile nav drawer -->
    <div id="nav-drawer" class="hidden border-t border-white/20 md:hidden">
        <div class="max-w-7xl mx-auto px-4 py-2 flex flex-col text-sm">
            <a href="index.php" class="py-2.5 border-b border-white/10 hover:text-accent transition-colors <?= nav_active('index.php') ?>">Dashboard</a>
            <a href="team.php" class="py-2.5 border-b border-white/10 hover:text-accent transition-colors <?= nav_active('team.php') ?>">Teams</a>
            <a href="division.php" class="py-2.5 border-b border-white/10 hover:text-accent transition-colors <?= nav_active('division.php') ?>">Divisions</a>
            <a href="index.php?view=teams" class="py-2.5 border-b border-white/10 hover:text-accent transition-colors <?= nav_active('index.php', 'teams') ?>">Discipline Rankings</a>
            <a href="index.php?view=discrepancies" class="py-2.5 hover:text-accent transition-colors <?= nav_active('index.php', 'discrepancies') ?>">Discrepancies</a>
        </div>
    </div>
</nav>
<script>
document.getElementById('nav-toggle').addEventListener('click', function() {
    document.getElementById('nav-drawer').classList.toggle('hidden');
    document.getElementById('nav-icon-menu').classList.toggle('hidden');
    document.getElementById('nav-icon-close').classList.toggle('hidden');
});
</script>

<main class="max-w-7xl mx-auto px-4 py-6">
