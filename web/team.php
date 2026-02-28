<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

function fmt_date(string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('D, M j, Y', $ts) : htmlspecialchars($iso);
}

$team_name = trim($_GET['name'] ?? '');
$pdo       = get_pdo();

$type_badge = [
    'mens'   => 'bg-blue-100 text-blue-700',
    'womens' => 'bg-pink-100 text-pink-700',
    'coed'   => 'bg-purple-100 text-purple-700',
];
$type_border = [
    'mens'   => 'border-blue-400',
    'womens' => 'border-pink-400',
    'coed'   => 'border-purple-400',
];
$type_order = ['mens', 'womens', 'coed'];
$type_label = ['mens' => 'Mens', 'womens' => 'Womens', 'coed' => 'Coed'];

// ── Sidebar data: teams grouped by division, ordered by type ───────────────
$sidebar_rows = $pdo->query("
    SELECT m.team, d.name AS division_name, d.division_id, d.type
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    GROUP BY m.team, d.division_id
    ORDER BY CASE d.type WHEN 'mens' THEN 1 WHEN 'womens' THEN 2 WHEN 'coed' THEN 3 ELSE 4 END,
             d.name, m.team
")->fetchAll();

$sidebar = []; // [type][division_name] => ['division_id' => X, 'teams' => [...]]
foreach ($sidebar_rows as $row) {
    $type = $row['type'];
    $div  = $row['division_name'];
    if (!isset($sidebar[$type][$div])) {
        $sidebar[$type][$div] = ['division_id' => $row['division_id'], 'teams' => []];
    }
    $sidebar[$type][$div]['teams'][] = $row['team'];
}

// ── Detail data (only when a team is selected) ─────────────────────────────
$team_info      = null;
$team_divisions = [];
$type_list      = [];
$games_played   = 0;
$cards_per_game = 0;
$top_players    = [];
$matchups       = [];
$card_history   = [];

if ($team_name !== '') {
    $stmt = $pdo->prepare("
        SELECT m.team,
               SUM(CASE WHEN m.card_type='Yellow' AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS ind_yellows,
               SUM(CASE WHEN m.card_type='Red'    AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS ind_reds,
               SUM(CASE WHEN m.card_type='Yellow' AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_yellows,
               SUM(CASE WHEN m.card_type='Red'    AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_reds,
               COUNT(*) AS total_cards
        FROM misconducts m WHERE m.team = ?
    ");
    $stmt->execute([$team_name]);
    $team_info = $stmt->fetch();

    if ($team_info && $team_info['team'] !== null) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id AS division_db_id, d.name, d.division_id, d.type
            FROM misconducts m
            JOIN games g ON m.game_id = g.id
            JOIN divisions d ON g.division_id = d.id
            WHERE m.team = ? ORDER BY d.name
        ");
        $stmt->execute([$team_name]);
        $team_divisions = $stmt->fetchAll();
        $type_list = array_unique(array_column($team_divisions, 'type'));

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE home_team = ? OR away_team = ?");
        $stmt->execute([$team_name, $team_name]);
        $games_played = (int)$stmt->fetchColumn();
        $cards_per_game = $games_played > 0
            ? round((int)$team_info['total_cards'] / $games_played, 2) : 0;

        // Derived discipline values (counts for display in stats bar)
        $ind_yellows   = (int)$team_info['ind_yellows'];
        $ind_reds      = (int)$team_info['ind_reds'];
        $bench_yellows = (int)$team_info['bench_yellows'];
        $bench_reds    = (int)$team_info['bench_reds'];
        $bench_total   = $bench_yellows + $bench_reds;

        // Division average discipline score (computed via weight_sql)
        $div_db_ids = array_column($team_divisions, 'division_db_id');
        $div_avg = null;
        if (!empty($div_db_ids)) {
            $ph    = implode(',', array_fill(0, count($div_db_ids), '?'));
            $w_sql = weight_sql();
            $div_stmt = $pdo->prepare("
                SELECT m.team,
                       SUM($w_sql) AS disc_weight,
                       (SELECT COUNT(*) FROM games g2
                        WHERE (g2.home_team = m.team OR g2.away_team = m.team)
                          AND g2.division_id IN ($ph)) AS gp
                FROM misconducts m
                JOIN games g ON m.game_id = g.id
                WHERE g.division_id IN ($ph)
                GROUP BY m.team
            ");
            $div_stmt->execute(array_merge($div_db_ids, $div_db_ids));
            $div_teams = $div_stmt->fetchAll();
            if (!empty($div_teams)) {
                $total_div_score = 0;
                foreach ($div_teams as $dt) {
                    $total_div_score += (float)$dt['disc_weight'] / max((int)$dt['gp'], 1);
                }
                $div_avg = round($total_div_score / count($div_teams), 2);
            }
        }

        // Bench penalty records and weighted score breakdown (computed after card_history loads below)
        // — see post-card_history block —

        $stmt = $pdo->prepare("
            SELECT m.player_name,
                   SUM(CASE WHEN m.card_type='Yellow'
                             AND NOT EXISTS (
                                 SELECT 1 FROM misconducts m2
                                 WHERE m2.game_id = m.game_id
                                   AND m2.player_name = m.player_name
                                   AND m2.card_type = 'Red'
                             ) THEN 1 ELSE 0 END) AS yellows,
                   SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
                   COUNT(*) AS total_cards
            FROM misconducts m WHERE m.team = ? AND m.player_name != 'Bench Penalty'
            GROUP BY m.player_name ORDER BY total_cards DESC
        ");
        $stmt->execute([$team_name]);
        $top_players = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT CASE WHEN g.home_team = ? THEN g.away_team ELSE g.home_team END AS opponent,
                   COUNT(m.id) AS total_cards
            FROM games g JOIN misconducts m ON m.game_id = g.id
            WHERE (g.home_team = ? OR g.away_team = ?)
            GROUP BY opponent ORDER BY total_cards DESC LIMIT 5
        ");
        $stmt->execute([$team_name, $team_name, $team_name]);
        $matchups = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT m.*, g.game_date, g.game_id, d.name AS division_name, d.division_id
            FROM misconducts m
            JOIN games g ON m.game_id = g.id
            JOIN divisions d ON g.division_id = d.id
            WHERE m.team = ? ORDER BY g.game_date ASC, m.id ASC
        ");
        $stmt->execute([$team_name]);
        $card_history = $stmt->fetchAll();
        $bench_cards  = array_values(array_filter($card_history, fn($c) => $c['player_name'] === 'Bench Penalty'));

        // Weighted discipline score (Canadian Soccer Disciplinary Code)
        $breakdown = [
            'proc_y'  => ['count' => 0, 'weight' => 0.0, 'label' => 'Procedural Yellow (Cat. E)', 'color' => 'text-yellow-600',  'chart_color' => '#f59e0b'],
            'beh_y'   => ['count' => 0, 'weight' => 0.0, 'label' => 'Behavioural Yellow (Cat. E)', 'color' => 'text-amber-600',  'chart_color' => '#d97706'],
            'soft_r'  => ['count' => 0, 'weight' => 0.0, 'label' => 'Two-Yellow Ejection',         'color' => 'text-orange-500', 'chart_color' => '#f97316'],
            'hard_r'  => ['count' => 0, 'weight' => 0.0, 'label' => 'Direct Red (Cat. A/B/C/D)',   'color' => 'text-red-600',    'chart_color' => '#dc2626'],
            'bench_y' => ['count' => 0, 'weight' => 0.0, 'label' => 'Bench Yellow (×1.5)',         'color' => 'text-orange-600', 'chart_color' => '#ea580c'],
            'bench_r' => ['count' => 0, 'weight' => 0.0, 'label' => 'Bench Red (×1.5)',            'color' => 'text-red-700',    'chart_color' => '#991b1b'],
        ];
        $weighted_sum = 0.0;
        $team_timeline = [];
        foreach ($card_history as $c) {
            $base_w   = card_weight($c['reason'] ?? '', $c['card_type']);
            $is_bench = $c['player_name'] === 'Bench Penalty';
            $actual_w = $base_w * ($is_bench ? 1.5 : 1.0);
            $weighted_sum += $actual_w;
            if ($is_bench) {
                $key = $c['card_type'] === 'Yellow' ? 'bench_y' : 'bench_r';
            } elseif ($c['card_type'] === 'Yellow') {
                $key = yellow_weight($c['reason'] ?? '') <= 1.0 ? 'proc_y' : 'beh_y';
            } else {
                $key = red_weight($c['reason'] ?? '') <= 3.0 ? 'soft_r' : 'hard_r';
            }
            $breakdown[$key]['count']++;
            $breakdown[$key]['weight'] += $actual_w;
            // Timeline grouping by month
            $month_key = date('M Y', strtotime($c['game_date'] ?: 'now'));
            if (!isset($team_timeline[$month_key])) {
                $team_timeline[$month_key] = ['yellows' => 0, 'reds' => 0, 'sort' => $c['game_date'] ?: ''];
            }
            if ($c['card_type'] === 'Yellow') $team_timeline[$month_key]['yellows']++;
            else $team_timeline[$month_key]['reds']++;
        }
        uasort($team_timeline, fn($a, $b) => strcmp($a['sort'], $b['sort']));
        $discipline_score = $games_played > 0 ? round($weighted_sum / $games_played, 2) : 0.0;
        $disc_color   = discipline_color($discipline_score);
        $disc_label   = discipline_label($discipline_score);
        $score_border = match($disc_color) { 'red' => 'border-red-500', 'amber' => 'border-amber-400', default => 'border-green-400' };
        $score_text   = match($disc_color) { 'red' => 'text-red-600',   'amber' => 'text-amber-600',   default => 'text-green-600' };
    }
}

$page_title = $team_name ? htmlspecialchars($team_name) . ' — Teams' : 'Teams';
require_once __DIR__ . '/includes/header.php';
$sidebar_open = ($team_name === '');
?>

<!-- Sidebar toggle (mobile only) -->
<button id="sidebar-toggle" class="md:hidden w-full flex items-center justify-between bg-white rounded-lg shadow px-3 py-2.5 mb-3 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
    <span class="flex items-center gap-2">
        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        Browse Teams
    </span>
    <svg id="sidebar-chevron" class="w-4 h-4 text-gray-400 transition-transform <?= $sidebar_open ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
    </svg>
</button>

<div class="flex flex-col md:flex-row gap-6">

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<aside id="team-sidebar" class="<?= $sidebar_open ? '' : 'hidden' ?> md:block md:w-56 shrink-0">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-primary text-white px-3 py-2 text-xs font-semibold uppercase tracking-wide">
            All Teams
        </div>
        <!-- Search -->
        <div class="p-2 border-b border-gray-100">
            <input type="text" id="team-sidebar-search" placeholder="Search teams…" autocomplete="off"
                   class="w-full border rounded px-2 py-1.5 text-sm">
        </div>
        <!-- List -->
        <div class="overflow-y-auto" style="max-height: calc(100vh - 160px)">
            <?php foreach ($type_order as $type):
                if (empty($sidebar[$type])) continue;
            ?>
            <div class="type-group border-t border-gray-100" data-type="<?= $type ?>">
                <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide bg-gray-50">
                    <?= $type_label[$type] ?>
                </div>
                <?php foreach ($sidebar[$type] as $div_name => $div_data): ?>
                <div class="division-group">
                    <div class="px-3 py-1 text-xs font-medium text-gray-500 bg-gray-50/60 border-t border-gray-100">
                        <?= htmlspecialchars($div_name) ?>
                    </div>
                    <?php foreach ($div_data['teams'] as $t):
                        $is_active = $t === $team_name;
                    ?>
                    <a href="team.php?name=<?= urlencode($t) ?>"
                       data-name="<?= htmlspecialchars(strtolower($t)) ?>"
                       class="team-item block px-4 py-2 text-sm border-t border-gray-50 transition-colors <?= $is_active ? 'bg-primary/10 text-primary font-semibold border-l-4 ' . $type_border[$type] : 'text-gray-700 hover:bg-gray-50 border-l-4 border-transparent' ?>">
                        <?= htmlspecialchars($t) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</aside>

<!-- ── Main panel ──────────────────────────────────────────────────────────── -->
<div class="flex-1 min-w-0">

<?php if ($team_name === ''): ?>
<!-- Empty state -->
<div class="bg-white rounded-lg shadow p-16 text-center text-gray-300">
    <div class="hidden md:block text-5xl mb-4 font-thin">&larr;</div>
    <div class="md:hidden text-3xl mb-4">&uarr;</div>
    <p class="text-lg font-medium text-gray-400">Select a team</p>
    <p class="text-sm mt-1 hidden md:block">Top players · Volatile matchups · Card history</p>
    <p class="text-sm mt-1 md:hidden">Tap "Browse Teams" above</p>
</div>

<?php elseif (!$team_info || $team_info['team'] === null): ?>
<!-- Not found -->
<div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-6">
    <h2 class="text-xl font-bold mb-1">Team not found</h2>
    <p>No records found for: <strong><?= htmlspecialchars($team_name) ?></strong></p>
</div>

<?php else: ?>

<!-- ── Team header ───────────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-start gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-primary"><?= htmlspecialchars($team_name) ?></h1>
        <div class="flex flex-wrap gap-2 mt-1.5">
            <?php foreach ($type_list as $type): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $type_badge[$type] ?? 'bg-gray-100 text-gray-600' ?>">
                <?= htmlspecialchars(ucfirst($type)) ?>
            </span>
            <?php endforeach; ?>
            <?php foreach ($team_divisions as $div): ?>
            <a href="division.php?id=<?= (int)$div['division_id'] ?>"
               class="bg-accent/20 text-green-900 text-xs font-medium px-2.5 py-0.5 rounded hover:bg-accent/40 transition-colors">
                <?= htmlspecialchars($div['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Stats bar ────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-blue-400">
        <div class="text-xs text-gray-500">Games Played</div>
        <div class="text-xl font-bold text-blue-600"><?= $games_played ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-400">
        <div class="text-xs text-gray-500">Ind. Yellows</div>
        <div class="text-xl font-bold text-amber-600"><?= $ind_yellows ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-red-500">
        <div class="text-xs text-gray-500">Ind. Reds</div>
        <div class="text-xl font-bold text-red-600"><?= $ind_reds ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-orange-400">
        <div class="text-xs text-gray-500">Bench Penalties</div>
        <div class="text-xl font-bold text-orange-600"><?= $bench_total ?></div>
        <?php if ($bench_total > 0): ?>
        <div class="text-xs text-gray-400 mt-0.5"><?= $bench_yellows ?>Y / <?= $bench_reds ?>R</div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-primary">
        <div class="text-xs text-gray-500">Cards / Game</div>
        <div class="text-xl font-bold text-primary"><?= $cards_per_game ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 <?= $score_border ?>">
        <div class="text-xs text-gray-500 flex items-center justify-between">
            <span>Discipline Score</span>
            <button id="disc-modal-btn" class="text-gray-300 hover:text-primary transition-colors" title="View breakdown">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </button>
        </div>
        <div class="text-xl font-bold <?= $score_text ?>"><?= number_format($discipline_score, 2) ?></div>
        <div class="text-xs <?= $score_text ?> mt-0.5 font-medium"><?= $disc_label ?></div>
        <?php if ($div_avg !== null): ?>
        <?php $vs = $discipline_score - $div_avg; ?>
        <div class="text-xs text-gray-400 mt-0.5">
            vs avg:
            <span class="<?= $vs > 0 ? 'text-red-500' : 'text-green-600' ?> font-semibold">
                <?= $vs > 0 ? '+' : '' ?><?= number_format($vs, 2) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Charts ───────────────────────────────────────────────────────────── -->
<?php if (!empty($card_history)): ?>
<?php
$donut_labels  = [];
$donut_data    = [];
$donut_colors  = [];
foreach ($breakdown as $tier) {
    if ($tier['count'] === 0) continue;
    $donut_labels[]  = $tier['label'];
    $donut_data[]    = round($tier['weight'], 2);
    $donut_colors[]  = $tier['chart_color'];
}
$tl_labels  = json_encode(array_keys($team_timeline));
$tl_yellows = json_encode(array_values(array_column($team_timeline, 'yellows')));
$tl_reds    = json_encode(array_values(array_column($team_timeline, 'reds')));
?>
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">

    <!-- Severity Composition Donut -->
    <div class="md:col-span-2 bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-600 mb-3">Severity Composition</h3>
        <div class="flex items-center gap-4">
            <div class="relative" style="width:110px;height:110px;flex-shrink:0">
                <canvas id="chart-team-donut"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-lg font-bold <?= $score_text ?>"><?= number_format($discipline_score, 1) ?></span>
                    <span class="text-xs text-gray-400">pts/game</span>
                </div>
            </div>
            <div class="flex-1 space-y-1 min-w-0">
                <?php foreach ($breakdown as $tier): if ($tier['count'] === 0) continue; ?>
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background:<?= $tier['chart_color'] ?>"></span>
                    <span class="text-gray-600 truncate flex-1"><?= $tier['label'] ?></span>
                    <span class="font-mono text-gray-500 shrink-0"><?= number_format($tier['weight'], 1) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Cards Per Month Timeline -->
    <div class="md:col-span-3 bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-600 mb-3">Cards by Month</h3>
        <div style="height:120px">
            <canvas id="chart-team-timeline"></canvas>
        </div>
    </div>

</div>
<?php endif; ?>

<!-- ── Bench Penalties ───────────────────────────────────────────────────── -->
<?php if ($bench_total > 0): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2 text-orange-700">Bench Penalties</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-orange-500 text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Date</th>
                    <th class="px-4 py-2.5 text-left">Division</th>
                    <th class="px-4 py-2.5 text-left">Game</th>
                    <th class="px-4 py-2.5 text-left">Reason</th>
                    <th class="px-4 py-2.5 text-left">Card</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-orange-100">
                <?php foreach ($bench_cards as $bc): ?>
                <tr class="bg-orange-50">
                    <td class="px-4 py-2"><?= fmt_date($bc['game_date']) ?></td>
                    <td class="px-4 py-2">
                        <a href="division.php?id=<?= (int)$bc['division_id'] ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($bc['division_name']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2">
                        <a href="<?= gamesheet_url((int)$bc['division_id'], (int)$bc['game_id']) ?>" target="_blank" class="text-primary hover:underline">
                            <?= (int)$bc['game_id'] ?>
                        </a>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($bc['reason'] ?? '—') ?></td>
                    <td class="px-4 py-2 font-bold">
                        <?php if ($bc['card_type'] === 'Red'): ?>
                            <span class="text-red-700">Red</span>
                        <?php else: ?>
                            <span class="text-amber-700">Yellow</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ── Top players ───────────────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Top Players</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Player</th>
                    <th class="px-4 py-2.5 text-center">Y</th>
                    <th class="px-4 py-2.5 text-center">R</th>
                    <th class="px-4 py-2.5 text-center">Total</th>
                    <th class="px-4 py-2.5 text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($top_players as $p):
                    $status = yellow_status((int)$p['yellows']);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium">
                        <a href="player.php?name=<?= urlencode($p['player_name']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($p['player_name']) ?>
                        </a>
                        <?php if ((int)$p['yellows'] >= 3): ?>
                        <span class="ml-1 text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded font-medium">Repeat</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-center text-amber-600 font-semibold"><?= $p['yellows'] ?></td>
                    <td class="px-4 py-2 text-center text-red-600 font-semibold"><?= $p['reds'] ?></td>
                    <td class="px-4 py-2 text-center font-bold"><?= $p['total_cards'] ?></td>
                    <td class="px-4 py-2">
                        <span class="text-xs <?= $status['class'] ?> px-1.5 py-0.5 rounded"><?= $status['label'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ── Volatile matchups ─────────────────────────────────────────────────── -->
<?php if (!empty($matchups)): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Most Volatile Matchups</h2>
    <p class="text-sm text-gray-500 mb-2">Total cards issued across all head-to-head games (both teams combined).</p>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Opponent</th>
                    <th class="px-4 py-2.5 text-center">Total Cards</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($matchups as $m): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium">
                        <a href="team.php?name=<?= urlencode($m['opponent']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($m['opponent']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-center font-bold <?= (int)$m['total_cards'] >= 5 ? 'text-red-600' : 'text-amber-600' ?>">
                        <?= $m['total_cards'] ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ── Card history ──────────────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Card History</h2>
    <?php if (empty($card_history)): ?>
        <p class="text-gray-500 italic">No cards recorded for this team.</p>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Date</th>
                    <th class="px-4 py-2.5 text-left">Division</th>
                    <th class="px-4 py-2.5 text-left">Game</th>
                    <th class="px-4 py-2.5 text-left">Player</th>
                    <th class="px-4 py-2.5 text-left">Reason</th>
                    <th class="px-4 py-2.5 text-left">Card</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($card_history as $c):
                    $is_bench  = $c['player_name'] === 'Bench Penalty';
                    $row_class = $is_bench ? 'bg-orange-50' : ($c['card_type'] === 'Red' ? 'status-red' : 'status-amber');
                ?>
                <tr class="<?= $row_class ?>">
                    <td class="px-4 py-2"><?= fmt_date($c['game_date']) ?></td>
                    <td class="px-4 py-2">
                        <a href="division.php?id=<?= (int)$c['division_id'] ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($c['division_name']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2">
                        <a href="<?= gamesheet_url((int)$c['division_id'], (int)$c['game_id']) ?>" target="_blank" class="text-primary hover:underline">
                            <?= (int)$c['game_id'] ?>
                        </a>
                    </td>
                    <td class="px-4 py-2 font-medium">
                        <?php if ($is_bench): ?>
                            <span class="inline-block bg-orange-100 text-orange-700 text-xs px-1.5 py-0.5 rounded font-semibold mr-1">Bench</span>
                        <?php else: ?>
                        <a href="player.php?name=<?= urlencode($c['player_name']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($c['player_name']) ?>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($c['reason'] ?? '—') ?></td>
                    <td class="px-4 py-2 font-bold">
                        <?php if ($c['card_type'] === 'Red'): ?>
                            <span class="text-red-700">Red</span>
                        <?php else: ?>
                            <span class="text-amber-700">Yellow</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php endif; ?>
</div><!-- /main panel -->
</div><!-- /flex layout -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<?php if ($team_name !== '' && $team_info && $team_info['team'] !== null): ?>
<!-- Discipline Breakdown Modal -->
<div id="disc-modal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="font-bold text-gray-800">Discipline Index Breakdown</h2>
            <button id="disc-modal-close" class="text-gray-400 hover:text-gray-700 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="px-5 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">CSDC Severity Breakdown</h3>
            <div class="space-y-1.5 mb-4">
                <?php foreach ($breakdown as $tier): if ($tier['count'] === 0) continue; ?>
                <div class="flex items-center gap-2 text-sm py-1.5 border-b border-gray-50">
                    <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background:<?= $tier['chart_color'] ?>"></span>
                    <span class="<?= $tier['color'] ?> font-medium flex-1"><?= $tier['label'] ?></span>
                    <span class="text-gray-400 font-mono text-xs">
                        <?= $tier['count'] ?> card<?= $tier['count'] !== 1 ? 's' : '' ?>
                    </span>
                    <span class="text-gray-600 font-mono text-xs font-semibold w-14 text-right">
                        <?= number_format($tier['weight'], 1) ?> pts
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="bg-gray-50 rounded p-3 text-sm font-mono">
                <div class="flex justify-between text-gray-600">
                    <span>Total weight</span>
                    <span><?= number_format($weighted_sum, 1) ?> pts</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Games played</span>
                    <span><?= $games_played ?></span>
                </div>
                <div class="flex justify-between font-bold <?= $score_text ?> border-t border-gray-200 mt-2 pt-2">
                    <span>Discipline Index</span>
                    <span><?= number_format($discipline_score, 2) ?> / game</span>
                </div>
            </div>
            <?php if ($div_avg !== null): ?>
            <?php $vs = $discipline_score - $div_avg; ?>
            <div class="mt-3 text-sm text-gray-600">
                Division average: <strong><?= number_format($div_avg, 2) ?></strong>
                &nbsp;
                <span class="font-semibold <?= $vs > 0 ? 'text-red-600' : 'text-green-600' ?>">
                    (<?= $vs > 0 ? '+' : '' ?><?= number_format($vs, 2) ?> vs avg)
                </span>
            </div>
            <?php endif; ?>
            <div class="mt-4 pt-3 border-t border-gray-100 text-xs text-gray-400 flex justify-between items-center">
                <span>Based on the Canadian Soccer Disciplinary Code</span>
                <a href="scoring.php" class="text-primary hover:underline">Scoring Guide &rarr;</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('sidebar-toggle').addEventListener('click', function() {
    const sidebar = document.getElementById('team-sidebar');
    const chevron = document.getElementById('sidebar-chevron');
    sidebar.classList.toggle('hidden');
    chevron.classList.toggle('rotate-180');
});

const searchInput = document.getElementById('team-sidebar-search');
searchInput.addEventListener('input', () => {
    const q = searchInput.value.toLowerCase().trim();
    document.querySelectorAll('.division-group').forEach(divGroup => {
        let visible = 0;
        divGroup.querySelectorAll('.team-item').forEach(item => {
            const match = !q || item.dataset.name.includes(q);
            item.hidden = !match;
            if (match) visible++;
        });
        divGroup.hidden = visible === 0;
    });
    document.querySelectorAll('.type-group').forEach(typeGroup => {
        const allHidden = [...typeGroup.querySelectorAll('.division-group')].every(g => g.hidden);
        typeGroup.hidden = allHidden;
    });
});
<?php if ($team_name === ''): ?>
searchInput.focus();
<?php endif; ?>

<?php if ($team_name !== '' && $team_info && $team_info['team'] !== null): ?>
// Discipline modal
const discModal    = document.getElementById('disc-modal');
const discModalBtn = document.getElementById('disc-modal-btn');
const discModalClose = document.getElementById('disc-modal-close');
if (discModalBtn) {
    discModalBtn.addEventListener('click', () => discModal.classList.remove('hidden'));
    discModalClose.addEventListener('click', () => discModal.classList.add('hidden'));
    discModal.addEventListener('click', (e) => { if (e.target === discModal) discModal.classList.add('hidden'); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') discModal.classList.add('hidden'); });
}

<?php if (!empty($card_history)): ?>
// Charts
(function() {
    const donutCtx = document.getElementById('chart-team-donut');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($donut_labels) ?>,
                datasets: [{
                    data:            <?= json_encode($donut_data) ?>,
                    backgroundColor: <?= json_encode($donut_colors) ?>,
                    borderWidth: 1,
                    borderColor: '#fff',
                }]
            },
            options: {
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ${ctx.parsed.toFixed(1)} pts`
                        }
                    }
                }
            }
        });
    }

    const tlCtx = document.getElementById('chart-team-timeline');
    if (tlCtx) {
        new Chart(tlCtx, {
            type: 'bar',
            data: {
                labels: <?= $tl_labels ?>,
                datasets: [
                    {
                        label: 'Yellows',
                        data: <?= $tl_yellows ?>,
                        backgroundColor: '#f59e0b',
                        stack: 'cards',
                    },
                    {
                        label: 'Reds',
                        data: <?= $tl_reds ?>,
                        backgroundColor: '#dc2626',
                        stack: 'cards',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 10, padding: 8, font: { size: 10 } }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 9 } } },
                    y: { stacked: true, ticks: { stepSize: 1, font: { size: 9 } }, beginAtZero: true }
                }
            }
        });
    }
})();
<?php endif; ?>
<?php endif; ?>
</script>

</main>
</body>
</html>
