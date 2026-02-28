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

$w_sql = weight_sql('m.reason', 'm.card_type', 'm.player_name');
$stmt = $pdo->prepare("
    SELECT m.team,
           GROUP_CONCAT(DISTINCT d.name) AS divisions,
           GROUP_CONCAT(DISTINCT d.type) AS types,
           SUM(CASE WHEN m.card_type='Yellow' AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red'    AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS reds,
           SUM(CASE WHEN m.card_type='Yellow' AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_yellows,
           SUM(CASE WHEN m.card_type='Red'    AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_reds,
           COUNT(*) AS total_cards,
           SUM($w_sql) AS discipline_weight,
           (SELECT COUNT(*) FROM games g2 WHERE g2.home_team = m.team OR g2.away_team = m.team) AS games_played
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    WHERE 1=1 $where_sql
    GROUP BY m.team
");
$stmt->execute($where_params);
$teams_raw = $stmt->fetchAll();

foreach ($teams_raw as &$t) {
    $gp = max((int)$t['games_played'], 1);
    $t['discipline_score'] = round((float)$t['discipline_weight'] / $gp, 2);
}
unset($t);
usort($teams_raw, fn($a, $b) => $b['discipline_score'] <=> $a['discipline_score']);
$teams = $teams_raw;

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
                <th class="px-4 py-3 text-center">Bench</th>
                <th class="px-4 py-3 text-center">Total Cards</th>
                <th class="px-4 py-3 text-center">Score</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($teams as $i => $t): ?>
            <?php
                $div_list    = array_filter(array_map('trim', explode(',', $t['divisions'] ?? '')));
                $type_list   = array_unique(array_filter(array_map('trim', explode(',', $t['types'] ?? ''))));
                $bench_total = (int)$t['bench_yellows'] + (int)$t['bench_reds'];
                $bench_str   = $bench_total > 0
                    ? (int)$t['bench_yellows'] . 'Y / ' . (int)$t['bench_reds'] . 'R'
                    : '—';
                $bench_class = $bench_total > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400';
                $score       = $t['discipline_score'];
                $score_class = $score > 2.5 ? 'text-red-600 font-bold'
                             : ($score >= 1.0 ? 'text-amber-600 font-semibold'
                             : 'text-green-600 font-semibold');
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
                <td class="px-4 py-2 text-center <?= $bench_class ?>"><?= $bench_str ?></td>
                <td class="px-4 py-2 text-center font-bold"><?= $t['total_cards'] ?></td>
                <td class="px-4 py-2 text-center <?= $score_class ?>"><?= number_format($score, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($teams)): ?>
            <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">No teams match the selected filters.</td></tr>
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
     DASHBOARD (DEFAULT VIEW)
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
// Stats for the dashboard summary bar
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
    WHERE m.player_name != 'Bench Penalty'
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


<!-- ── Most Dangerous Players ──────────────────────────────────────────────── -->
<?php
$w_sql_idx = weight_sql('m.reason', 'm.card_type', 'm.player_name');
$danger_players = $pdo->query("
    SELECT m.player_name,
           GROUP_CONCAT(DISTINCT m.team) AS teams,
           SUM(CASE WHEN m.card_type='Yellow'
                     AND NOT EXISTS (
                         SELECT 1 FROM misconducts m2
                         WHERE m2.game_id = m.game_id
                           AND m2.player_name = m.player_name
                           AND m2.card_type = 'Red'
                     ) THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           SUM($w_sql_idx) AS danger_weight
    FROM misconducts m
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.player_name
    ORDER BY danger_weight DESC
    LIMIT 10
")->fetchAll();
$max_danger = !empty($danger_players) ? (float)$danger_players[0]['danger_weight'] : 1.0;
?>
<?php if (!empty($danger_players)): ?>
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-xl font-bold">Most Dangerous Players</h2>
        <a href="scoring.php" class="text-xs text-gray-400 hover:text-primary hover:underline">
            How is this calculated? &rarr;
        </a>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="space-y-2.5">
        <?php foreach ($danger_players as $i => $dp):
            $dw = (float)$dp['danger_weight'];
            $pct = $max_danger > 0 ? round($dw / $max_danger * 100) : 0;
            $dc = $dw > 7.0 ? 'bg-red-500' : ($dw >= 3.0 ? 'bg-amber-400' : 'bg-green-400');
            $tc = $dw > 7.0 ? 'text-red-600' : ($dw >= 3.0 ? 'text-amber-600' : 'text-green-600');
        ?>
        <div class="flex items-center gap-3">
            <div class="w-5 text-xs text-gray-400 text-right shrink-0"><?= $i + 1 ?></div>
            <div class="w-32 sm:w-44 shrink-0">
                <a href="player.php?name=<?= urlencode($dp['player_name']) ?>"
                   class="text-sm font-medium text-primary hover:underline truncate block">
                    <?= htmlspecialchars($dp['player_name']) ?>
                </a>
                <div class="text-xs text-gray-400 truncate"><?= htmlspecialchars($dp['teams']) ?></div>
            </div>
            <div class="flex-1 min-w-0">
                <div class="w-full bg-gray-100 rounded-full h-3">
                    <div class="<?= $dc ?> h-3 rounded-full transition-all" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <div class="w-24 text-right shrink-0">
                <span class="text-sm font-bold font-mono <?= $tc ?>"><?= number_format($dw, 1) ?></span>
                <span class="text-xs text-gray-400 ml-1">pts</span>
            </div>
            <div class="hidden sm:flex gap-2 shrink-0">
                <?php if ((int)$dp['yellows'] > 0): ?>
                <span class="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-mono"><?= $dp['yellows'] ?>Y</span>
                <?php endif; ?>
                <?php if ((int)$dp['reds'] > 0): ?>
                <span class="text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded font-mono"><?= $dp['reds'] ?>R</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
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
    LIMIT 10
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


<?php endif; ?>

<footer class="mt-8 text-center text-xs text-gray-400">
    Last scraped: <?= $last_scraped ? htmlspecialchars($last_scraped) : 'Never' ?>
</footer>

</main>
</body>
</html>
