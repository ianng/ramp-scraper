<?php
// Shared HTML header + nav
// Usage: include 'includes/header.php';
// Set $page_title before including.

$page_title = $page_title ?? 'Misconduct Tracker';
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
        <div class="flex gap-6 text-sm">
            <a href="index.php" class="hover:text-accent transition-colors">Dashboard</a>
            <a href="index.php?view=discrepancies" class="hover:text-accent transition-colors">Discrepancies</a>
            <a href="index.php?view=teams" class="hover:text-accent transition-colors">Team Rankings</a>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 py-6">
