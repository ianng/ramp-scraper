<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

$pdo  = get_pdo();
$view = $_GET['view'] ?? 'players';

$last_scraped = $pdo->query("SELECT MAX(scraped_at) FROM games")->fetchColumn();

// Divisions list — used by filter dropdowns in multiple views
$divisions = $pdo->query("SELECT * FROM divisions ORDER BY type, name")->fetchAll();

// ── Page title ─────────────────────────────────────────────────────────────
// Divisions moved to division.php
if ($view === 'divisions') {
    header('Location: division.php');
    exit;
}

$page_title = match ($view) {
    'teams'         => 'Teams',
    'discrepancies' => 'Discrepancy Report',
    default         => 'Misconduct Tracker',
};

include __DIR__ . '/includes/header.php';
?>

<?php if ($view === 'teams'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     TEAM DISCIPLINE RANKINGS
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$f_type = $_GET['div_type'] ?? '';
$f_div  = (int)($_GET['division_id'] ?? 0);

$where_parts  = [];
$where_params = [];
if ($f_type !== '') {
    $where_parts[]  = "d.type = ?";
    $where_params[] = $f_type;
}
if ($f_div > 0) {
    $where_parts[]  = "d.division_id = ?";
    $where_params[] = $f_div;
}
$where_sql = $where_parts ? 'AND ' . implode(' AND ', $where_parts) : '';

$stmt = $pdo->prepare("
    SELECT m.team,
           GROUP_CONCAT(DISTINCT d.name) AS divisions,
           GROUP_CONCAT(DISTINCT d.type) AS types,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
           COUNT(*) AS total_cards
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    WHERE 1=1 $where_sql
    GROUP BY m.team
    ORDER BY total_cards DESC
");
$stmt->execute($where_params);
$teams = $stmt->fetchAll();

$type_badge = [
    'mens'   => 'bg-blue-100 text-blue-700',
    'womens' => 'bg-pink-100 text-pink-700',
    'coed'   => 'bg-purple-100 text-purple-700',
];
?>

<form method="get" class="bg-white rounded-lg shadow p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="view" value="teams">
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Division Type</label>
        <select name="div_type" class="border rounded px-3 py-1.5 text-sm">
            <option value="">All Types</option>
            <option value="mens"   <?= $f_type === 'mens'   ? 'selected' : '' ?>>Mens</option>
            <option value="womens" <?= $f_type === 'womens' ? 'selected' : '' ?>>Womens</option>
            <option value="coed"   <?= $f_type === 'coed'   ? 'selected' : '' ?>>Coed</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Division</label>
        <select name="division_id" class="border rounded px-3 py-1.5 text-sm">
            <option value="">All Divisions</option>
            <?php foreach ($divisions as $div): ?>
            <option value="<?= (int)$div['division_id'] ?>" <?= $f_div === (int)$div['division_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($div['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="bg-primary text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-accent transition-colors">
        Filter
    </button>
    <?php if ($f_type || $f_div): ?>
    <a href="?view=teams" class="text-sm text-gray-500 hover:underline self-end pb-1.5">Clear</a>
    <?php endif; ?>
</form>

<h2 class="text-xl font-bold mb-4">
    Team Discipline Rankings
    <?php if ($f_type || $f_div): ?>
    <span class="text-base font-normal text-gray-500">— filtered</span>
    <?php endif; ?>
</h2>
<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-primary text-white">
            <tr>
                <th class="px-4 py-3 text-left">#</th>
                <th class="px-4 py-3 text-left">Team</th>
                <th class="px-4 py-3 text-left">Division(s)</th>
                <th class="px-4 py-3 text-left">Type</th>
                <th class="px-4 py-3 text-center">Yellows</th>
                <th class="px-4 py-3 text-center">Reds</th>
                <th class="px-4 py-3 text-center">Total Cards</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($teams as $i => $t): ?>
            <?php
                $div_list  = array_filter(array_map('trim', explode(',', $t['divisions'] ?? '')));
                $type_list = array_unique(array_filter(array_map('trim', explode(',', $t['types'] ?? ''))));
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-400"><?= $i + 1 ?></td>
                <td class="px-4 py-2 font-medium">
                    <a href="team.php?name=<?= urlencode($t['team']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($t['team']) ?>
                    </a>
                </td>
                <td class="px-4 py-2 text-xs text-gray-600"><?= htmlspecialchars(implode(', ', $div_list)) ?></td>
                <td class="px-4 py-2">
                    <?php foreach ($type_list as $type): ?>
                    <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium mr-1 <?= $type_badge[$type] ?? 'bg-gray-100 text-gray-600' ?>">
                        <?= htmlspecialchars(ucfirst($type)) ?>
                    </span>
                    <?php endforeach; ?>
                </td>
                <td class="px-4 py-2 text-center text-amber-600 font-semibold"><?= $t['yellows'] ?></td>
                <td class="px-4 py-2 text-center text-red-600 font-semibold"><?= $t['reds'] ?></td>
                <td class="px-4 py-2 text-center font-bold"><?= $t['total_cards'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($teams)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No teams match the selected filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($view === 'discrepancies'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     DISCREPANCY REPORT
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$f_type = $_GET['div_type'] ?? '';
$f_div  = (int)($_GET['division_id'] ?? 0);

if ($f_div > 0) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT m.player_name FROM misconducts m
        JOIN games g ON m.game_id = g.id
        JOIN divisions d ON g.division_id = d.id
        WHERE d.division_id = ?
    ");
    $stmt->execute([$f_div]);
} elseif ($f_type !== '') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT m.player_name FROM misconducts m
        JOIN games g ON m.game_id = g.id
        JOIN divisions d ON g.division_id = d.id
        WHERE d.type = ?
    ");
    $stmt->execute([$f_type]);
} else {
    $stmt = $pdo->query("SELECT DISTINCT player_name FROM misconducts");
}
$all_players = $stmt->fetchAll(PDO::FETCH_COLUMN);

$discrepancies = [];
foreach ($all_players as $name) {
    $report = get_compliance_report($pdo, $name, 'combined');
    if ($report['unserved_count'] > 0) {
        $discrepancies[] = [
            'player'      => $name,
            'expected'    => $report['expected_count'],
            'served'      => $report['served_count'],
            'unserved'    => $report['unserved_count'],
            'suspensions' => $report['expected_suspensions'],
        ];
    }
}
usort($discrepancies, fn($a, $b) => $b['unserved'] <=> $a['unserved']);
?>

<form method="get" class="bg-white rounded-lg shadow p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="view" value="discrepancies">
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Division Type</label>
        <select name="div_type" class="border rounded px-3 py-1.5 text-sm">
            <option value="">All Types</option>
            <option value="mens"   <?= $f_type === 'mens'   ? 'selected' : '' ?>>Mens</option>
            <option value="womens" <?= $f_type === 'womens' ? 'selected' : '' ?>>Womens</option>
            <option value="coed"   <?= $f_type === 'coed'   ? 'selected' : '' ?>>Coed</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Division</label>
        <select name="division_id" class="border rounded px-3 py-1.5 text-sm">
            <option value="">All Divisions</option>
            <?php foreach ($divisions as $div): ?>
            <option value="<?= (int)$div['division_id'] ?>" <?= $f_div === (int)$div['division_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($div['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="bg-primary text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-accent transition-colors">
        Filter
    </button>
    <?php if ($f_type || $f_div): ?>
    <a href="?view=discrepancies" class="text-sm text-gray-500 hover:underline self-end pb-1.5">Clear</a>
    <?php endif; ?>
</form>

<h2 class="text-xl font-bold mb-4">
    Compliance Discrepancy Report
    <?php if ($f_type || $f_div): ?>
    <span class="text-base font-normal text-gray-500">— filtered</span>
    <?php endif; ?>
</h2>
<?php if (empty($discrepancies)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-green-800">
        No compliance discrepancies found<?= ($f_type || $f_div) ? ' for the selected filter' : '' ?>.
        All suspension obligations appear fulfilled.
    </div>
<?php else: ?>
<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-danger text-white">
            <tr>
                <th class="px-4 py-3 text-left">Player</th>
                <th class="px-4 py-3 text-center">Triggered</th>
                <th class="px-4 py-3 text-center">Served</th>
                <th class="px-4 py-3 text-center">Unserved</th>
                <th class="px-4 py-3 text-left">Triggers</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($discrepancies as $d): ?>
            <tr class="hover:bg-red-50">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=<?= urlencode($d['player']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($d['player']) ?>
                    </a>
                </td>
                <td class="px-4 py-2 text-center"><?= $d['expected'] ?></td>
                <td class="px-4 py-2 text-center"><?= $d['served'] ?></td>
                <td class="px-4 py-2 text-center font-bold text-red-600"><?= $d['unserved'] ?></td>
                <td class="px-4 py-2 text-xs text-gray-600">
                    <?php foreach ($d['suspensions'] as $s): ?>
                        <span class="inline-block bg-red-100 text-red-700 px-1.5 rounded mr-1 mb-0.5">
                            Rule <?= $s['rule'] ?> (Yellow #<?= $s['trigger_yellow_count'] ?>)
                        </span>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     PLAYER DASHBOARD (DEFAULT VIEW)
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
// Stats — computed only in the players view
$total_yellows  = (int)$pdo->query("SELECT COUNT(*) FROM misconducts WHERE card_type='Yellow'")->fetchColumn();
$total_reds     = (int)$pdo->query("SELECT COUNT(*) FROM misconducts WHERE card_type='Red'")->fetchColumn();
$total_games    = (int)$pdo->query("SELECT COUNT(*) FROM games WHERE scraped_at IS NOT NULL")->fetchColumn();
$total_divs     = (int)$pdo->query("SELECT COUNT(*) FROM divisions")->fetchColumn();
$total_teams    = (int)$pdo->query("SELECT COUNT(DISTINCT team) FROM misconducts")->fetchColumn();

$player_yellow_counts = $pdo->query("
    SELECT m.player_name,
           SUM(CASE WHEN m.card_type = 'Yellow'
                     AND NOT EXISTS (
                         SELECT 1 FROM misconducts m2
                         WHERE m2.game_id = m.game_id
                           AND m2.player_name = m.player_name
                           AND m2.card_type = 'Red'
                     ) THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type = 'Red' THEN 1 ELSE 0 END) AS reds
    FROM misconducts m
    GROUP BY m.player_name
")->fetchAll();

$suspension_due_count = 0;
foreach ($player_yellow_counts as $row) {
    $status = yellow_status((int)$row['yellows']);
    if ($status['class'] === 'status-red' || (int)$row['reds'] > 0) {
        $suspension_due_count++;
    }
}
?>

<!-- ── Stats Bar ──────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-amber-400">
        <div class="text-sm text-gray-500">Total Yellows</div>
        <div id="stat-yellows" class="text-2xl font-bold text-amber-600"><?= $total_yellows ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
        <div class="text-sm text-gray-500">Total Reds</div>
        <div id="stat-reds" class="text-2xl font-bold text-red-600"><?= $total_reds ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-primary">
        <div class="text-sm text-gray-500">Suspensions Due</div>
        <div id="stat-suspensions" class="text-2xl font-bold text-primary"><?= $suspension_due_count ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-400">
        <div class="text-sm text-gray-500">Games Scraped</div>
        <div id="stat-games" class="text-2xl font-bold text-blue-600"><?= $total_games ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-400">
        <div class="text-sm text-gray-500">Divisions</div>
        <div id="stat-divs" class="text-2xl font-bold text-purple-600"><?= $total_divs ?></div>
        <div id="stat-teams" class="text-xs text-gray-400 mt-0.5"><?= $total_teams ?> teams</div>
    </div>
</div>

<!-- ── Filters Bar ────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form id="filter-form" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Division Type</label>
            <select id="f-type" class="border rounded px-3 py-1.5 text-sm">
                <option value="">All</option>
                <option value="mens">Mens</option>
                <option value="womens">Womens</option>
                <option value="coed">Coed</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Division</label>
            <select id="f-division" class="border rounded px-3 py-1.5 text-sm">
                <option value="">All Divisions</option>
                <?php foreach ($divisions as $div): ?>
                <option value="<?= (int)$div['division_id'] ?>" data-type="<?= htmlspecialchars($div['type']) ?>">
                    <?= htmlspecialchars($div['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="relative" id="team-combo-wrapper">
            <label class="block text-xs font-medium text-gray-500 mb-1">Team</label>
            <input type="text" id="f-team-search" placeholder="All teams…" autocomplete="off"
                   class="border rounded px-3 py-1.5 text-sm w-48">
            <input type="hidden" id="f-team" value="">
            <div id="team-dropdown" class="hidden absolute z-10 left-0 bg-white border rounded shadow-lg mt-1 w-48 max-h-48 overflow-y-auto text-sm"></div>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">View Mode</label>
            <select id="f-mode" class="border rounded px-3 py-1.5 text-sm">
                <option value="combined">Combined</option>
                <option value="per_division">Per-Division</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Yellows Min</label>
            <input type="number" id="f-ymin" min="0" placeholder="0" class="border rounded px-3 py-1.5 text-sm w-20">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Yellows Max</label>
            <input type="number" id="f-ymax" min="0" placeholder="∞" class="border rounded px-3 py-1.5 text-sm w-20">
        </div>
        <button type="submit" class="bg-primary text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-accent transition-colors">
            Filter
        </button>
    </form>
</div>

<!-- ── Export + Table ──────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-3">
    <h2 class="text-xl font-bold">Player Card Counts</h2>
    <button id="btn-export" class="bg-gray-100 border border-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm hover:bg-gray-200 transition-colors">
        Export CSV
    </button>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
    <table id="player-table" class="w-full text-sm">
        <thead class="bg-primary text-white">
            <tr>
                <th class="px-4 py-3 text-left">Player</th>
                <th class="px-4 py-3 text-left">Team(s)</th>
                <th class="px-4 py-3 text-left">Division(s)</th>
                <th class="px-4 py-3 text-center">Yellows</th>
                <th class="px-4 py-3 text-center">Reds</th>
                <th class="px-4 py-3 text-left">Suspension Status</th>
                <th class="px-4 py-3 text-center">Next Threshold</th>
                <th class="px-4 py-3 text-left">Served</th>
            </tr>
        </thead>
        <tbody id="player-tbody" class="divide-y divide-gray-100">
<?php
$players = $pdo->query("
    SELECT m.player_name,
           GROUP_CONCAT(DISTINCT m.team) AS teams,
           GROUP_CONCAT(DISTINCT d.name) AS divisions,
           SUM(CASE WHEN m.card_type='Yellow'
                     AND NOT EXISTS (
                         SELECT 1 FROM misconducts m2
                         WHERE m2.game_id = m.game_id
                           AND m2.player_name = m.player_name
                           AND m2.card_type = 'Red'
                     ) THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    GROUP BY m.player_name
    ORDER BY yellows DESC, reds DESC, m.player_name ASC
")->fetchAll();

// Pre-compute compliance for players at or past a suspension threshold.
// Stored here so it can be reused by the table rows AND the alerts panel below.
$susp_map = [];
foreach ($players as $_p) {
    if ((int)$_p['yellows'] < 3 && (int)$_p['reds'] === 0) continue;
    $susp_map[$_p['player_name']] = get_compliance_report($pdo, $_p['player_name'], 'combined');
}

foreach ($players as $p):
    $yellows = (int)$p['yellows'];
    $reds    = (int)$p['reds'];
    $status  = yellow_status($yellows);
    $next    = yellows_until_next($yellows);
?>
            <tr class="<?= $status['class'] ?>">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=<?= urlencode($p['player_name']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                </td>
                <td class="px-4 py-2" data-label="Team"><?= htmlspecialchars($p['teams']) ?></td>
                <td class="px-4 py-2 text-xs" data-label="Division"><?= htmlspecialchars($p['divisions']) ?></td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600" data-label="Yellow"><?= $yellows ?></td>
                <td class="px-4 py-2 text-center font-semibold text-red-600" data-label="Red"><?= $reds ?></td>
                <td class="px-4 py-2" data-label="Status"><?= $status['label'] ?></td>
                <td class="px-4 py-2 text-center" data-label="Next"><?= $next !== null ? $next . ' away' : '—' ?></td>
                <?php $rpt = $susp_map[$p['player_name']] ?? null; ?>
                <td class="px-4 py-2" data-label="Served">
                    <?php if (!$rpt || $rpt['expected_count'] === 0): ?>
                        <span class="text-gray-400">—</span>
                    <?php elseif ($rpt['fully_compliant']): ?>
                        <span class="text-green-700 font-medium">&#10003; Served</span>
                    <?php else: ?>
                        <span class="text-red-600 font-semibold"><?= $rpt['unserved_count'] ?> unserved</span>
                    <?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Compliance Alert Panel ─────────────────────────────────────────────── -->
<?php
$alerts = [];
foreach ($players as $p) {
    $report = $susp_map[$p['player_name']] ?? null;
    if (!$report || $report['unserved_count'] <= 0) continue;
    $alerts[] = [
        'player'   => $p['player_name'],
        'unserved' => $report['unserved_count'],
        'expected' => $report['expected_count'],
        'served'   => $report['served_count'],
    ];
}
usort($alerts, fn($a, $b) => $b['unserved'] <=> $a['unserved']);
?>
<?php if (!empty($alerts)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3 text-red-700">Unserved Suspensions</h2>
    <p class="text-sm text-gray-500 mb-3">Suspensions triggered by yellow card accumulation that have not yet been recorded as served. Note: the league enforces these via a monthly report — they may be pending rather than definitively missed.</p>
    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($alerts as $a): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="font-semibold">
                <a href="player.php?name=<?= urlencode($a['player']) ?>" class="text-red-800 hover:underline">
                    <?= htmlspecialchars($a['player']) ?>
                </a>
            </div>
            <div class="text-sm text-red-600 mt-1">
                <?= $a['unserved'] ?> unserved suspension<?= $a['unserved'] > 1 ? 's' : '' ?>
                <span class="text-xs text-red-400">(<?= $a['served'] ?>/<?= $a['expected'] ?> served)</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Most Volatile Games ─────────────────────────────────────────────────── -->
<?php
$volatile_games = $pdo->query("
    SELECT g.game_id, g.game_date, g.home_team, g.away_team,
           d.name AS division_name, d.division_id, COUNT(m.id) AS cards
    FROM games g
    JOIN misconducts m ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    GROUP BY g.id
    ORDER BY cards DESC
    LIMIT 5
")->fetchAll();
?>
<?php if (!empty($volatile_games)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3">Most Volatile Games</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-3 text-left">Home</th>
                    <th class="px-4 py-3 text-left">Away</th>
                    <th class="px-4 py-3 text-left">Division</th>
                    <th class="px-4 py-3 text-center">Cards</th>
                    <th class="px-4 py-3 text-left">Gamesheet</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($volatile_games as $vg): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium"><?= htmlspecialchars($vg['home_team']) ?></td>
                    <td class="px-4 py-2 font-medium"><?= htmlspecialchars($vg['away_team']) ?></td>
                    <td class="px-4 py-2">
                        <a href="division.php?id=<?= (int)$vg['division_id'] ?>" class="text-primary hover:underline text-xs">
                            <?= htmlspecialchars($vg['division_name']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-center font-bold <?= (int)$vg['cards'] >= 5 ? 'text-red-600' : 'text-amber-600' ?>">
                        <?= $vg['cards'] ?>
                    </td>
                    <td class="px-4 py-2">
                        <a href="<?= gamesheet_url((int)$vg['division_id'], (int)$vg['game_id']) ?>" target="_blank" class="text-primary hover:underline text-xs">
                            View &rarr;
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Misconduct Reason Breakdown ─────────────────────────────────────────── -->
<?php
$reason_rows = $pdo->query("
    SELECT reason,
           COUNT(*) AS cnt,
           SUM(CASE WHEN card_type='Red' THEN 1 ELSE 0 END) AS red_cnt
    FROM misconducts
    GROUP BY reason
    ORDER BY cnt DESC
")->fetchAll();

$reason_total = array_sum(array_column($reason_rows, 'cnt'));
?>
<?php if (!empty($reason_rows)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3">Misconduct Reason Breakdown</h2>
    <div class="bg-white rounded-lg shadow p-4">
        <?php foreach ($reason_rows as $r):
            $pct        = $reason_total > 0 ? round($r['cnt'] / $reason_total * 100, 1) : 0;
            $is_red     = (int)$r['red_cnt'] > 0;
            $bar_color  = $is_red ? 'bg-red-500'  : 'bg-amber-400';
            $text_color = $is_red ? 'text-red-700' : 'text-amber-700';
        ?>
        <div class="mb-2">
            <div class="flex justify-between text-xs mb-0.5">
                <span class="font-medium <?= $text_color ?>"><?= htmlspecialchars($r['reason']) ?></span>
                <span class="text-gray-500"><?= $r['cnt'] ?> (<?= $pct ?>%)</span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-3">
                <div class="<?= $bar_color ?> h-3 rounded-full" style="width:<?= $pct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Recent Activity Feed ────────────────────────────────────────────────── -->
<?php
$recent_activity = $pdo->query("
    SELECT m.player_name, m.card_type, m.reason, m.team,
           g.game_date, g.game_id, g.id AS db_game_id,
           d.name AS division_name, d.division_id
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    ORDER BY g.id DESC, m.id DESC
    LIMIT 10
")->fetchAll();
?>
<?php if (!empty($recent_activity)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3">Recent Activity</h2>
    <div class="bg-white rounded-lg shadow divide-y divide-gray-100">
        <?php foreach ($recent_activity as $act):
            $is_red      = $act['card_type'] === 'Red';
            $badge_class = $is_red ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
        ?>
        <div class="px-4 py-3 flex items-center gap-3 hover:bg-gray-50">
            <span class="inline-block <?= $badge_class ?> text-xs font-bold px-2 py-0.5 rounded shrink-0">
                <?= $act['card_type'] ?>
            </span>
            <div class="min-w-0">
                <span class="font-medium">
                    <a href="player.php?name=<?= urlencode($act['player_name']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($act['player_name']) ?>
                    </a>
                </span>
                <span class="text-gray-400 mx-1">&middot;</span>
                <a href="team.php?name=<?= urlencode($act['team']) ?>" class="text-gray-600 hover:underline text-sm">
                    <?= htmlspecialchars($act['team']) ?>
                </a>
                <span class="text-gray-400 mx-1">&middot;</span>
                <a href="division.php?id=<?= (int)$act['division_id'] ?>" class="text-gray-500 hover:underline text-xs">
                    <?= htmlspecialchars($act['division_name']) ?>
                </a>
                <?php if (!empty($act['reason'])): ?>
                <span class="text-gray-400 mx-1">&middot;</span>
                <span class="text-xs text-gray-400 italic"><?= htmlspecialchars($act['reason']) ?></span>
                <?php endif; ?>
            </div>
            <span class="ml-auto text-xs text-gray-400 shrink-0"><?= htmlspecialchars($act['game_date']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── JavaScript: AJAX filtering + CSV export ────────────────────────────── -->
<?php
$all_teams = $pdo->query("SELECT DISTINCT team FROM misconducts ORDER BY team ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form       = document.getElementById('filter-form');
    const tbody      = document.getElementById('player-tbody');
    const typeEl     = document.getElementById('f-type');
    const divEl      = document.getElementById('f-division');
    const teamSearch = document.getElementById('f-team-search');
    const teamHidden = document.getElementById('f-team');
    const teamDrop   = document.getElementById('team-dropdown');
    const allTeams   = <?= json_encode($all_teams) ?>;

    // ── Team combobox ──────────────────────────────────────────────────────────
    function renderTeamOptions(q) {
        const lower    = q.toLowerCase();
        const filtered = q ? allTeams.filter(t => t.toLowerCase().includes(lower)) : allTeams;
        const clearOpt = '<div class="px-3 py-1.5 cursor-pointer hover:bg-gray-100 text-gray-400 italic" data-val="">All teams</div>';
        teamDrop.innerHTML = clearOpt + (filtered.length
            ? filtered.map(t => `<div class="px-3 py-1.5 cursor-pointer hover:bg-primary hover:text-white truncate" data-val="${esc(t)}">${esc(t)}</div>`).join('')
            : '<div class="px-3 py-2 text-gray-400 text-xs">No match</div>');
    }

    teamSearch.addEventListener('focus', () => {
        renderTeamOptions(teamSearch.value);
        teamDrop.classList.remove('hidden');
    });
    teamSearch.addEventListener('input', () => {
        teamHidden.value = '';
        renderTeamOptions(teamSearch.value);
        teamDrop.classList.remove('hidden');
    });
    teamDrop.addEventListener('mousedown', (e) => {
        const opt = e.target.closest('[data-val]');
        if (!opt) return;
        e.preventDefault();
        teamHidden.value = opt.dataset.val;
        teamSearch.value = opt.dataset.val;
        teamDrop.classList.add('hidden');
    });
    document.addEventListener('click', (e) => {
        if (!document.getElementById('team-combo-wrapper').contains(e.target)) {
            teamDrop.classList.add('hidden');
        }
    });

    // Filter division dropdown when type changes
    typeEl.addEventListener('change', () => {
        const sel = typeEl.value;
        for (const opt of divEl.options) {
            if (!opt.value) { opt.hidden = false; continue; }
            opt.hidden = sel && opt.dataset.type !== sel;
        }
        if (divEl.selectedOptions[0]?.hidden) divEl.value = '';
    });

    // AJAX filter
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const params = new URLSearchParams({ action: 'players' });
        const type = typeEl.value;          if (type) params.set('div_type', type);
        const div  = divEl.value;           if (div)  params.set('division_id', div);
        const team = document.getElementById('f-team').value.trim();
        if (team) params.set('team', team);
        const mode = document.getElementById('f-mode').value;
        params.set('mode', mode);
        const ymin = document.getElementById('f-ymin').value;
        const ymax = document.getElementById('f-ymax').value;
        if (ymin) params.set('min_yellows', ymin);
        if (ymax) params.set('max_yellows', ymax);

        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Loading…</td></tr>';

        try {
            const res  = await fetch('api.php?' + params.toString());
            const data = await res.json();
            renderRows(data.players ?? []);
            updateStats(data.stats);
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-red-500">Error loading data.</td></tr>';
        }
    });

    function renderRows(players) {
        if (!players.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No players match filters.</td></tr>';
            return;
        }
        tbody.innerHTML = players.map(p => {
            return `<tr class="${esc(p.status_class)}">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=${encodeURIComponent(p.name)}" class="text-primary hover:underline">${esc(p.name)}</a>
                </td>
                <td class="px-4 py-2" data-label="Team">${esc(p.teams.join(', '))}</td>
                <td class="px-4 py-2 text-xs" data-label="Division">${esc(p.divisions.join(', '))}</td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600" data-label="Yellow">${p.yellow_count}</td>
                <td class="px-4 py-2 text-center font-semibold text-red-600" data-label="Red">${p.red_count}</td>
                <td class="px-4 py-2" data-label="Status">${esc(p.status_label)}</td>
                <td class="px-4 py-2 text-center" data-label="Next">${p.next_threshold !== null ? p.next_threshold + ' away' : '—'}</td>
                <td class="px-4 py-2" data-label="Served"><span class="${esc(p.served_class)}">${esc(p.served_label)}</span></td>
            </tr>`;
        }).join('');
    }

    function updateStats(stats) {
        if (!stats) return;
        document.getElementById('stat-yellows').textContent     = stats.total_yellows;
        document.getElementById('stat-reds').textContent        = stats.total_reds;
        document.getElementById('stat-suspensions').textContent = stats.suspension_due;
        document.getElementById('stat-games').textContent       = stats.total_games;
        document.getElementById('stat-divs').textContent        = stats.total_divs;
        document.getElementById('stat-teams').textContent       = stats.total_teams + ' teams';
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    // CSV Export
    document.getElementById('btn-export').addEventListener('click', () => {
        const table = document.getElementById('player-table');
        const rows  = table.querySelectorAll('tr');
        const csv   = [];
        rows.forEach(row => {
            const cells = row.querySelectorAll('th, td');
            const line  = [];
            cells.forEach(c => {
                let txt = c.textContent.trim().replace(/"/g, '""');
                line.push('"' + txt + '"');
            });
            csv.push(line.join(','));
        });
        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = 'player_cards_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
    });

    // Pre-populate team filter from ?team= URL param
    const urlTeam = new URLSearchParams(location.search).get('team');
    if (urlTeam) {
        teamSearch.value = urlTeam;
        teamHidden.value = urlTeam;
        form.dispatchEvent(new Event('submit'));
    }
});
</script>

<?php endif; ?>

<footer class="mt-8 text-center text-xs text-gray-400">
    Last scraped: <?= $last_scraped ? htmlspecialchars($last_scraped) : 'Never' ?>
</footer>

</main>
</body>
</html>
