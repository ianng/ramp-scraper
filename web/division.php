<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

function fmt_date(string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('D, M j, Y', $ts) : htmlspecialchars($iso);
}

$div_id = (int)($_GET['id'] ?? 0);
$pdo    = get_pdo();

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

// ── All divisions with aggregate stats (for sidebar) ───────────────────────
$all_divs = $pdo->query("
    SELECT d.*,
           COUNT(DISTINCT g.id)                                        AS total_games,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END)       AS yellows,
           SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END)       AS reds,
           COUNT(m.id)                                                 AS total_cards
    FROM divisions d
    LEFT JOIN games g ON g.division_id = d.id
    LEFT JOIN misconducts m ON m.game_id = g.id
    GROUP BY d.id
    ORDER BY d.type, d.name
")->fetchAll();

$divs_by_type = [];
foreach ($all_divs as $d) {
    $divs_by_type[$d['type']][] = $d;
}

// ── Selected division detail ───────────────────────────────────────────────
$div_info      = null;
$div_pk        = null;
$total_games   = 0;
$card_stats    = ['yellows' => 0, 'reds' => 0, 'total_cards' => 0];
$team_standings = [];
$games_per_team = [];
$top_players   = [];
$volatile_games = [];

if ($div_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM divisions WHERE division_id = ?");
    $stmt->execute([$div_id]);
    $div_info = $stmt->fetch();

    if ($div_info) {
        $div_pk = (int)$div_info['id'];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE division_id = ?");
        $stmt->execute([$div_pk]);
        $total_games = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
                   SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
                   COUNT(*) AS total_cards
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE g.division_id = ?
        ");
        $stmt->execute([$div_pk]);
        $card_stats = $stmt->fetch();

        $stmt = $pdo->prepare("
            SELECT m.team,
                   SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
                   SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
                   COUNT(*) AS total_cards
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE g.division_id = ?
            GROUP BY m.team ORDER BY total_cards DESC
        ");
        $stmt->execute([$div_pk]);
        $team_standings = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT home_team AS team, COUNT(*) AS n FROM games WHERE division_id = ? GROUP BY home_team
            UNION ALL
            SELECT away_team, COUNT(*) FROM games WHERE division_id = ? GROUP BY away_team
        ");
        $stmt->execute([$div_pk, $div_pk]);
        foreach ($stmt->fetchAll() as $row) {
            $games_per_team[$row['team']] = ($games_per_team[$row['team']] ?? 0) + (int)$row['n'];
        }

        $stmt = $pdo->prepare("
            SELECT m.player_name, m.team,
                   SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
                   SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
                   COUNT(*) AS total_cards
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE g.division_id = ?
            GROUP BY m.player_name ORDER BY total_cards DESC LIMIT 10
        ");
        $stmt->execute([$div_pk]);
        $top_players = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT g.game_id, g.game_date, g.home_team, g.away_team, COUNT(m.id) AS cards
            FROM games g JOIN misconducts m ON m.game_id = g.id
            WHERE g.division_id = ?
            GROUP BY g.id ORDER BY cards DESC LIMIT 5
        ");
        $stmt->execute([$div_pk]);
        $volatile_games = $stmt->fetchAll();

        // ── Chart data ──────────────────────────────────────────────────────
        // Per-team discipline scores + severity-tier breakdown for charts
        $w_sql = weight_sql('m.reason', 'm.card_type', 'm.player_name');
        $stmt = $pdo->prepare("
            SELECT m.team, m.reason, m.card_type, m.player_name, COUNT(*) AS cnt
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE g.division_id = ?
            GROUP BY m.team, m.reason, m.card_type, m.player_name
        ");
        $stmt->execute([$div_pk]);
        $chart_raw = $stmt->fetchAll();

        // Build per-team tier sums
        $chart_teams_data = []; // team => [proc_y, beh_y, soft_r, hard_r, bench, gp, score]
        foreach ($chart_raw as $row) {
            $team     = $row['team'];
            $is_bench = $row['player_name'] === 'Bench Penalty';
            $base_w   = card_weight($row['reason'] ?? '', $row['card_type']);
            $w        = $base_w * ($is_bench ? 1.5 : 1.0) * (int)$row['cnt'];
            if (!isset($chart_teams_data[$team])) {
                $chart_teams_data[$team] = ['proc_y' => 0.0, 'beh_y' => 0.0, 'soft_r' => 0.0, 'hard_r' => 0.0, 'bench' => 0.0, 'total' => 0.0];
            }
            if ($is_bench) {
                $chart_teams_data[$team]['bench'] += $w;
            } elseif ($row['card_type'] === 'Yellow') {
                $key = yellow_weight($row['reason'] ?? '') <= 1.0 ? 'proc_y' : 'beh_y';
                $chart_teams_data[$team][$key] += $w;
            } else {
                $key = red_weight($row['reason'] ?? '') <= 3.0 ? 'soft_r' : 'hard_r';
                $chart_teams_data[$team][$key] += $w;
            }
            $chart_teams_data[$team]['total'] += $w;
        }
        // Attach games_played and compute discipline score
        foreach ($chart_teams_data as $team => &$d) {
            $d['gp']    = $games_per_team[$team] ?? 1;
            $d['score'] = $d['gp'] > 0 ? round($d['total'] / $d['gp'], 2) : 0.0;
        }
        unset($d);
        // Sort by discipline score descending
        uasort($chart_teams_data, fn($a, $b) => $b['score'] <=> $a['score']);

        // Reason-breakdown donut: group by Canadian code category
        $donut_groups = [
            'Procedural Yellow'   => ['weight' => 0.0, 'count' => 0, 'color' => '#fde68a'],
            'Behavioural Yellow'  => ['weight' => 0.0, 'count' => 0, 'color' => '#f59e0b'],
            'Dissent'             => ['weight' => 0.0, 'count' => 0, 'color' => '#d97706'],
            'Two-Yellow Ejection' => ['weight' => 0.0, 'count' => 0, 'color' => '#fb923c'],
            'DOGSO (Cat. C)'      => ['weight' => 0.0, 'count' => 0, 'color' => '#f97316'],
            'Serious Foul (Cat. B)' => ['weight' => 0.0, 'count' => 0, 'color' => '#ef4444'],
            'Spitting'            => ['weight' => 0.0, 'count' => 0, 'color' => '#dc2626'],
            'Abuse of Official (Cat. D)' => ['weight' => 0.0, 'count' => 0, 'color' => '#b91c1c'],
            'Violent Conduct (Cat. A)'   => ['weight' => 0.0, 'count' => 0, 'color' => '#7f1d1d'],
            'Bench Penalty'       => ['weight' => 0.0, 'count' => 0, 'color' => '#ea580c'],
        ];
        foreach ($chart_raw as $row) {
            $is_bench = $row['player_name'] === 'Bench Penalty';
            $base_w   = card_weight($row['reason'] ?? '', $row['card_type']);
            $actual_w = $base_w * ($is_bench ? 1.5 : 1.0) * (int)$row['cnt'];
            $r        = $row['reason'] ?? '';
            $ct       = $row['card_type'];
            if ($is_bench) {
                $key = 'Bench Penalty';
            } elseif ($ct === 'Yellow') {
                if (str_contains($r, 'Dissent'))                 $key = 'Dissent';
                elseif (str_contains($r, 'Unsporting') || str_contains($r, 'Persistent')) $key = 'Behavioural Yellow';
                else $key = 'Procedural Yellow';
            } else {
                if (str_contains($r, 'Category A') || str_contains($r, 'Violent Conduct')) $key = 'Violent Conduct (Cat. A)';
                elseif (str_contains($r, 'Category D') || str_contains($r, 'Foul and Abusive') || str_contains($r, 'Abuse of an Official')) $key = 'Abuse of Official (Cat. D)';
                elseif (str_contains($r, 'Serious Foul'))       $key = 'Serious Foul (Cat. B)';
                elseif (str_contains($r, 'Spitting'))           $key = 'Spitting';
                elseif (str_contains($r, 'Denying Obvious'))    $key = 'DOGSO (Cat. C)';
                elseif (str_contains($r, 'Second Caution'))     $key = 'Two-Yellow Ejection';
                else $key = 'Serious Foul (Cat. B)';
            }
            if (isset($donut_groups[$key])) {
                $donut_groups[$key]['weight'] += $actual_w;
                $donut_groups[$key]['count']  += (int)$row['cnt'];
            }
        }
        $donut_groups = array_filter($donut_groups, fn($g) => $g['count'] > 0);

        // Cards over time: weekly card counts for trend line
        $stmt = $pdo->prepare("
            SELECT strftime('%Y-%W', g.game_date) AS week,
                   COUNT(m.id) AS cards
            FROM games g JOIN misconducts m ON m.game_id = g.id
            WHERE g.division_id = ?
            GROUP BY week ORDER BY week ASC
        ");
        $stmt->execute([$div_pk]);
        $weekly_cards = $stmt->fetchAll();
    }
}

$cards_per_game = ($total_games > 0 && $card_stats['total_cards'])
    ? round($card_stats['total_cards'] / $total_games, 2) : 0;

$page_title = $div_info
    ? htmlspecialchars($div_info['name']) . ' — Divisions'
    : 'Divisions';
require_once __DIR__ . '/includes/header.php';
$sidebar_open = ($div_id === 0);
?>

<!-- Sidebar toggle (mobile only) -->
<button id="sidebar-toggle" class="md:hidden w-full flex items-center justify-between bg-white rounded-lg shadow px-3 py-2.5 mb-3 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
    <span class="flex items-center gap-2">
        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        Browse Divisions
    </span>
    <svg id="sidebar-chevron" class="w-4 h-4 text-gray-400 transition-transform <?= $sidebar_open ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
    </svg>
</button>

<div class="flex flex-col md:flex-row gap-6">

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<aside id="div-sidebar" class="<?= $sidebar_open ? '' : 'hidden' ?> md:block md:w-56 shrink-0">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-primary text-white px-3 py-2 text-xs font-semibold uppercase tracking-wide">
            All Divisions
        </div>
        <?php foreach ($type_order as $type):
            if (empty($divs_by_type[$type])) continue;
        ?>
        <div class="border-t border-gray-100">
            <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide bg-gray-50">
                <?= $type_label[$type] ?>
            </div>
            <?php foreach ($divs_by_type[$type] as $d):
                $is_active = (int)$d['division_id'] === $div_id;
            ?>
            <a href="division.php?id=<?= (int)$d['division_id'] ?>"
               class="block px-3 py-2 border-t border-gray-50 transition-colors <?= $is_active ? 'bg-primary/10 border-l-4 ' . $type_border[$type] : 'hover:bg-gray-50 border-l-4 border-transparent' ?>">
                <div class="text-sm font-medium <?= $is_active ? 'text-primary' : 'text-gray-700' ?> leading-tight">
                    <?= htmlspecialchars($d['name']) ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</aside>

<!-- ── Main panel ──────────────────────────────────────────────────────────── -->
<div class="flex-1 min-w-0">

<?php if (!$div_info): ?>
<!-- Empty state -->
<div class="bg-white rounded-lg shadow p-16 text-center text-gray-300">
    <div class="hidden md:block text-5xl mb-4 font-thin">&larr;</div>
    <div class="md:hidden text-3xl mb-4">&uarr;</div>
    <p class="text-lg font-medium text-gray-400">Select a division</p>
    <p class="text-sm mt-1 hidden md:block">Team standings · Top players · Volatile games</p>
    <p class="text-sm mt-1 md:hidden">Tap "Browse Divisions" above</p>
</div>

<?php elseif ($div_id > 0 && !$div_info): ?>
<!-- Not found -->
<div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-6">
    <h2 class="text-xl font-bold mb-1">Division not found</h2>
    <p>No division found with ID <strong><?= $div_id ?></strong>.</p>
</div>

<?php else: ?>

<!-- ── Division header ──────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-start justify-between gap-3 mb-5">
    <div>
        <h1 class="text-2xl font-bold text-primary"><?= htmlspecialchars($div_info['name']) ?></h1>
        <div class="flex flex-wrap gap-2 mt-1.5">
            <?php if (!empty($div_info['type'])): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $type_badge[$div_info['type']] ?? 'bg-gray-100 text-gray-600' ?>">
                <?= htmlspecialchars(ucfirst($div_info['type'])) ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($div_info['level'])): ?>
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                Level <?= htmlspecialchars($div_info['level']) ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="text-right">
        <div class="text-2xl font-bold text-primary"><?= $cards_per_game ?></div>
        <div class="text-xs text-gray-500">cards / game</div>
    </div>
</div>

<!-- ── Stats bar ────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-blue-400">
        <div class="text-xs text-gray-500">Games</div>
        <div class="text-xl font-bold text-blue-600"><?= $total_games ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-400">
        <div class="text-xs text-gray-500">Yellows</div>
        <div class="text-xl font-bold text-amber-600"><?= $card_stats['yellows'] ?? 0 ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-red-500">
        <div class="text-xs text-gray-500">Reds</div>
        <div class="text-xl font-bold text-red-600"><?= $card_stats['reds'] ?? 0 ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-primary">
        <div class="text-xs text-gray-500">Total Cards</div>
        <div class="text-xl font-bold text-primary"><?= $card_stats['total_cards'] ?? 0 ?></div>
    </div>
</div>

<!-- ── Charts ─────────────────────────────────────────────────────────────── -->
<?php if (!empty($chart_teams_data)): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-3">Discipline Analytics</h2>

    <!-- Chart row: discipline bars + donut -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

        <!-- Team Discipline Index (horizontal bar, spans 2/3) -->
        <div class="lg:col-span-2 bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-1">
                Team Discipline Index
                <span class="text-xs font-normal text-gray-400 ml-1">— weighted score per game (CSDC)</span>
            </h3>
            <p class="text-xs text-gray-400 mb-3">
                Cat. E yellows 1.0–2.5 · Two-yellow ejection 3.0 · DOGSO 4.5 · Cat. B 6.0 · Cat. D 7.0 · Cat. A 9.0 · Bench ×1.5
            </p>
            <div style="position:relative; height:<?= max(120, count($chart_teams_data) * 38) ?>px">
                <canvas id="chart-discipline"></canvas>
            </div>
        </div>

        <!-- Reason Breakdown Donut (1/3) -->
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-1">Misconduct Breakdown</h3>
            <p class="text-xs text-gray-400 mb-3">Weighted points by category</p>
            <div style="position:relative; height:220px">
                <canvas id="chart-donut"></canvas>
            </div>
            <div class="mt-2 space-y-0.5">
                <?php foreach ($donut_groups as $label => $g): ?>
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                    <span class="inline-block w-2.5 h-2.5 rounded-sm shrink-0" style="background:<?= $g['color'] ?>"></span>
                    <?= htmlspecialchars($label) ?>
                    <span class="text-gray-400 ml-auto"><?= $g['count'] ?> card<?= $g['count'] !== 1 ? 's' : '' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Severity Composition stacked bar (full width) -->
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-1">
            Severity Composition per Team
            <span class="text-xs font-normal text-gray-400 ml-1">— stacked weighted points</span>
        </h3>
        <div class="flex flex-wrap gap-3 text-xs text-gray-500 mb-3">
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#fde68a"></span>Procedural Yellow</span>
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#f59e0b"></span>Behavioural Yellow</span>
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#fb923c"></span>Two-Yellow / Soft Red</span>
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#ef4444"></span>Hard Red (Cat. A/B/D)</span>
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#ea580c"></span>Bench Penalty</span>
        </div>
        <div style="position:relative; height:<?= max(100, count($chart_teams_data) * 36) ?>px">
            <canvas id="chart-stacked"></canvas>
        </div>
    </div>

    <?php if (!empty($weekly_cards) && count($weekly_cards) > 1): ?>
    <!-- Weekly card trend -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-1">Cards Per Week</h3>
        <div style="position:relative; height:140px">
            <canvas id="chart-trend"></canvas>
        </div>
    </div>
    <?php endif; ?>

</section>
<?php endif; ?>

<!-- ── Team standings ────────────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Team Standings by Cards</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">#</th>
                    <th class="px-4 py-2.5 text-left">Team</th>
                    <th class="px-4 py-2.5 text-center">GP</th>
                    <th class="px-4 py-2.5 text-center">Y</th>
                    <th class="px-4 py-2.5 text-center">R</th>
                    <th class="px-4 py-2.5 text-center">Total</th>
                    <th class="px-4 py-2.5 text-center">Disc. Score</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php
                // Sort team_standings by discipline score to match chart order
                usort($team_standings, function($a, $b) use ($chart_teams_data) {
                    $sa = $chart_teams_data[$a['team']]['score'] ?? 0;
                    $sb = $chart_teams_data[$b['team']]['score'] ?? 0;
                    return $sb <=> $sa;
                });
                foreach ($team_standings as $i => $t):
                    $gp    = $games_per_team[$t['team']] ?? 0;
                    $score = $chart_teams_data[$t['team']]['score'] ?? 0;
                    $sc    = discipline_color($score);
                    $score_cls = match($sc) { 'red' => 'text-red-600 font-bold', 'amber' => 'text-amber-600 font-semibold', default => 'text-green-600 font-semibold' };
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-400"><?= $i + 1 ?></td>
                    <td class="px-4 py-2 font-medium">
                        <a href="team.php?name=<?= urlencode($t['team']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($t['team']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-center text-gray-500"><?= $gp ?></td>
                    <td class="px-4 py-2 text-center text-amber-600 font-semibold"><?= $t['yellows'] ?></td>
                    <td class="px-4 py-2 text-center text-red-600 font-semibold"><?= $t['reds'] ?></td>
                    <td class="px-4 py-2 text-center font-bold"><?= $t['total_cards'] ?></td>
                    <td class="px-4 py-2 text-center <?= $score_cls ?>"><?= number_format($score, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ── Top players ───────────────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Top Players</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Player</th>
                    <th class="px-4 py-2.5 text-left">Team</th>
                    <th class="px-4 py-2.5 text-center">Y</th>
                    <th class="px-4 py-2.5 text-center">R</th>
                    <th class="px-4 py-2.5 text-center">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($top_players as $p): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium">
                        <a href="player.php?name=<?= urlencode($p['player_name']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($p['player_name']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2">
                        <a href="team.php?name=<?= urlencode($p['team']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($p['team']) ?>
                        </a>
                    </td>
                    <td class="px-4 py-2 text-center text-amber-600 font-semibold"><?= $p['yellows'] ?></td>
                    <td class="px-4 py-2 text-center text-red-600 font-semibold"><?= $p['reds'] ?></td>
                    <td class="px-4 py-2 text-center font-bold"><?= $p['total_cards'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ── Most volatile games ───────────────────────────────────────────────── -->
<?php if (!empty($volatile_games)): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Most Volatile Games</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Date</th>
                    <th class="px-4 py-2.5 text-left">Home</th>
                    <th class="px-4 py-2.5 text-left">Away</th>
                    <th class="px-4 py-2.5 text-center">Cards</th>
                    <th class="px-4 py-2.5 text-left">Sheet</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($volatile_games as $g): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-500"><?= fmt_date($g['game_date']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($g['home_team']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($g['away_team']) ?></td>
                    <td class="px-4 py-2 text-center font-bold <?= (int)$g['cards'] >= 5 ? 'text-red-600' : 'text-amber-600' ?>">
                        <?= $g['cards'] ?>
                    </td>
                    <td class="px-4 py-2">
                        <a href="<?= gamesheet_url((int)$div_id, (int)$g['game_id']) ?>" target="_blank"
                           class="text-primary hover:underline text-xs">View &rarr;</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>
</div><!-- /main panel -->
</div><!-- /flex layout -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.getElementById('sidebar-toggle').addEventListener('click', function() {
    const sidebar = document.getElementById('div-sidebar');
    const chevron = document.getElementById('sidebar-chevron');
    sidebar.classList.toggle('hidden');
    chevron.classList.toggle('rotate-180');
});

<?php if (!empty($chart_teams_data)): ?>
(function() {
    // ── Chart data from PHP ──────────────────────────────────────────────────
    const teams = <?= json_encode(array_keys($chart_teams_data)) ?>;
    const scores = <?= json_encode(array_values(array_map(fn($d) => round($d['score'], 2), $chart_teams_data))) ?>;

    // Severity tier sums (absolute, not per-game — shows raw composition)
    const procY  = <?= json_encode(array_values(array_map(fn($d) => round($d['proc_y'],  2), $chart_teams_data))) ?>;
    const behY   = <?= json_encode(array_values(array_map(fn($d) => round($d['beh_y'],   2), $chart_teams_data))) ?>;
    const softR  = <?= json_encode(array_values(array_map(fn($d) => round($d['soft_r'],  2), $chart_teams_data))) ?>;
    const hardR  = <?= json_encode(array_values(array_map(fn($d) => round($d['hard_r'],  2), $chart_teams_data))) ?>;
    const bench  = <?= json_encode(array_values(array_map(fn($d) => round($d['bench'],   2), $chart_teams_data))) ?>;
    const gps    = <?= json_encode(array_values(array_map(fn($d) => $d['gp'],               $chart_teams_data))) ?>;

    // Division average score
    const divAvg = <?= count($chart_teams_data) > 0
        ? round(array_sum(array_column($chart_teams_data, 'score')) / count($chart_teams_data), 2)
        : 0 ?>;

    // Colour each bar by discipline threshold
    const barColors = scores.map(s =>
        s > 2.5 ? '#ef4444' : s >= 1.0 ? '#f59e0b' : '#22c55e'
    );

    const defaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
    };

    // ── Chart 1: Team Discipline Index (horizontal bar) ──────────────────────
    new Chart(document.getElementById('chart-discipline'), {
        type: 'bar',
        data: {
            labels: teams,
            datasets: [{
                data: scores,
                backgroundColor: barColors,
                borderRadius: 3,
                barThickness: 22,
            }]
        },
        options: {
            ...defaults,
            indexAxis: 'y',
            plugins: {
                ...defaults.plugins,
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const i = ctx.dataIndex;
                            const lbl = ctx.parsed.x >= 1.5 ? 'High Risk' : ctx.parsed.x >= 0.5 ? 'Elevated' : 'Clean';
                            return [
                                ` Score: ${ctx.parsed.x.toFixed(2)} / game  [${lbl}]`,
                                ` Games played: ${gps[i]}`,
                            ];
                        }
                    }
                },
                annotation: undefined, // no plugin needed; draw avg line via plugin below
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: { font: { size: 11 } },
                },
                y: {
                    ticks: { font: { size: 11 }, maxRotation: 0 },
                    grid: { display: false },
                },
            },
        },
        plugins: [{
            // Draw a vertical reference line for division average
            id: 'avgLine',
            afterDraw(chart) {
                if (divAvg <= 0) return;
                const {ctx, scales: {x, y}} = chart;
                const xPx = x.getPixelForValue(divAvg);
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(xPx, y.top);
                ctx.lineTo(xPx, y.bottom);
                ctx.strokeStyle = '#6366f1';
                ctx.lineWidth = 1.5;
                ctx.setLineDash([4, 3]);
                ctx.stroke();
                ctx.fillStyle = '#6366f1';
                ctx.font = '10px system-ui';
                ctx.fillText(`avg ${divAvg.toFixed(2)}`, xPx + 3, y.top + 10);
                ctx.restore();
            }
        }]
    });

    // ── Chart 2: Misconduct Reason Donut ────────────────────────────────────
    const donutLabels  = <?= json_encode(array_keys($donut_groups)) ?>;
    const donutWeights = <?= json_encode(array_values(array_map(fn($g) => round($g['weight'], 1), $donut_groups))) ?>;
    const donutColors  = <?= json_encode(array_values(array_column($donut_groups, 'color'))) ?>;
    const donutCounts  = <?= json_encode(array_values(array_column($donut_groups, 'count'))) ?>;

    new Chart(document.getElementById('chart-donut'), {
        type: 'doughnut',
        data: {
            labels: donutLabels,
            datasets: [{
                data: donutWeights,
                backgroundColor: donutColors,
                borderWidth: 1,
                borderColor: '#fff',
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const i = ctx.dataIndex;
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = (ctx.parsed / total * 100).toFixed(1);
                            return [
                                ` ${donutCounts[i]} card${donutCounts[i] !== 1 ? 's' : ''}`,
                                ` ${ctx.parsed.toFixed(1)} pts (${pct}%)`,
                            ];
                        }
                    }
                }
            }
        }
    });

    // ── Chart 3: Severity Composition stacked bar ────────────────────────────
    new Chart(document.getElementById('chart-stacked'), {
        type: 'bar',
        data: {
            labels: teams,
            datasets: [
                { label: 'Procedural Yellow', data: procY, backgroundColor: '#fde68a', barThickness: 20, borderRadius: {topLeft:0,topRight:0,bottomLeft:3,bottomRight:3} },
                { label: 'Behavioural Yellow', data: behY,  backgroundColor: '#f59e0b', barThickness: 20, borderRadius: 0 },
                { label: 'Two-Yellow / Soft Red', data: softR, backgroundColor: '#fb923c', barThickness: 20, borderRadius: 0 },
                { label: 'Hard Red (Cat. A/B/D)', data: hardR, backgroundColor: '#ef4444', barThickness: 20, borderRadius: 0 },
                { label: 'Bench Penalty', data: bench,  backgroundColor: '#ea580c', barThickness: 20, borderRadius: {topLeft:3,topRight:3,bottomLeft:0,bottomRight:0} },
            ]
        },
        options: {
            ...defaults,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.x.toFixed(1)} pts`
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: { font: { size: 11 } },
                },
                y: {
                    stacked: true,
                    ticks: { font: { size: 11 }, maxRotation: 0 },
                    grid: { display: false },
                },
            },
        }
    });

    <?php if (!empty($weekly_cards) && count($weekly_cards) > 1): ?>
    // ── Chart 4: Weekly Cards Trend ──────────────────────────────────────────
    const weekLabels = <?= json_encode(array_column($weekly_cards, 'week')) ?>;
    const weekCards  = <?= json_encode(array_map('intval', array_column($weekly_cards, 'cards'))) ?>;

    new Chart(document.getElementById('chart-trend'), {
        type: 'line',
        data: {
            labels: weekLabels,
            datasets: [{
                label: 'Cards',
                data: weekCards,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,0.1)',
                tension: 0.3,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { font: { size: 10 } }, grid: { color: '#f3f4f6' } },
                y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
            },
        }
    });
    <?php endif; ?>

})();
<?php endif; ?>
</script>

</main>
</body>
</html>
