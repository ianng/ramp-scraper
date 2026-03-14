<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

function fmt_date(string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('D, M j, Y', $ts) : htmlspecialchars($iso);
}

$pdo = get_pdo();

// ── Division selector ──────────────────────────────────────────────────────
$req_div_id = (int)($_GET['division_id'] ?? 35378); // default: Mens 2

$all_divs = $pdo->query("SELECT * FROM divisions ORDER BY type, name")->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM divisions WHERE division_id = ?");
$stmt->execute([$req_div_id]);
$div_info = $stmt->fetch();
$div_pk   = $div_info ? (int)$div_info['id'] : null;

$type_badge = [
    'mens'   => 'bg-blue-100 text-blue-700',
    'womens' => 'bg-pink-100 text-pink-700',
    'coed'   => 'bg-purple-100 text-purple-700',
];

// ── KPI stats ──────────────────────────────────────────────────────────────
$total_games = 0;
$card_stats  = ['yellows' => 0, 'reds' => 0, 'total_cards' => 0];

if ($div_pk) {
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
    $card_stats = $stmt->fetch() ?: ['yellows' => 0, 'reds' => 0, 'total_cards' => 0];
}

$cards_per_game = ($total_games > 0 && ($card_stats['total_cards'] ?? 0) > 0)
    ? round($card_stats['total_cards'] / $total_games, 2) : 0;

// ── All-division scoring index (Chart 1) ───────────────────────────────────
$w_sql = weight_sql('m.reason', 'm.card_type', 'm.player_name');

$div_scores_raw = $pdo->query("
    SELECT d.id AS div_pk, d.division_id AS ext_id, d.name AS div_name, d.type AS div_type,
           COUNT(DISTINCT g.id) AS games_played,
           COALESCE(SUM($w_sql), 0) AS total_weight
    FROM divisions d
    LEFT JOIN games g ON g.division_id = d.id
    LEFT JOIN misconducts m ON m.game_id = g.id
    GROUP BY d.id
")->fetchAll();

$div_scores = [];
foreach ($div_scores_raw as $r) {
    $gp = max(1, (int)$r['games_played']);
    $r['disc_score'] = round((float)$r['total_weight'] / $gp, 2);
    $div_scores[] = $r;
}
usort($div_scores, fn($a, $b) => $b['disc_score'] <=> $a['disc_score']);

$league_avg = count($div_scores) > 0
    ? round(array_sum(array_column($div_scores, 'disc_score')) / count($div_scores), 2)
    : 0;

// Chart 1 arrays
$c1_labels = array_column($div_scores, 'div_name');
$c1_scores = array_map('floatval', array_column($div_scores, 'disc_score'));
$c1_colors = [];
foreach ($div_scores as $r) {
    if ((int)$r['ext_id'] === $req_div_id) {
        $c1_colors[] = '#6366f1';
    } else {
        $sc = discipline_color((float)$r['disc_score']);
        $c1_colors[] = match($sc) { 'red' => '#ef4444', 'amber' => '#f59e0b', default => '#22c55e' };
    }
}

// ── Team rankings (Chart 2) ────────────────────────────────────────────────
$team_rankings = [];
$div_avg_score = 0.0;
$gp_map        = [];

if ($div_pk) {
    $stmt = $pdo->prepare("
        SELECT home_team AS team, COUNT(*) AS n FROM games WHERE division_id = ? GROUP BY home_team
        UNION ALL
        SELECT away_team, COUNT(*) FROM games WHERE division_id = ? GROUP BY away_team
    ");
    $stmt->execute([$div_pk, $div_pk]);
    foreach ($stmt->fetchAll() as $row) {
        $gp_map[$row['team']] = ($gp_map[$row['team']] ?? 0) + (int)$row['n'];
    }

    $stmt = $pdo->prepare("
        SELECT m.team,
               SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
               SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
               COUNT(*) AS total_cards,
               COALESCE(SUM($w_sql), 0) AS total_weight
        FROM misconducts m JOIN games g ON m.game_id = g.id
        WHERE g.division_id = ?
        GROUP BY m.team
    ");
    $stmt->execute([$div_pk]);
    $team_raw = $stmt->fetchAll();

    $carded_teams = array_column($team_raw, 'team');
    foreach (array_keys($gp_map) as $t) {
        if (!in_array($t, $carded_teams)) {
            $team_raw[] = ['team' => $t, 'yellows' => 0, 'reds' => 0, 'total_cards' => 0, 'total_weight' => 0.0];
        }
    }

    foreach ($team_raw as $row) {
        $gp    = max(1, $gp_map[$row['team']] ?? 1);
        $score = round((float)$row['total_weight'] / $gp, 2);
        $team_rankings[] = [
            'team'             => $row['team'],
            'yellows'          => (int)$row['yellows'],
            'reds'             => (int)$row['reds'],
            'total_cards'      => (int)$row['total_cards'],
            'games_played'     => $gp,
            'discipline_score' => $score,
        ];
    }
    usort($team_rankings, fn($a, $b) => $b['discipline_score'] <=> $a['discipline_score']);

    if (count($team_rankings) > 0) {
        $div_avg_score = round(
            array_sum(array_column($team_rankings, 'discipline_score')) / count($team_rankings),
            2
        );
    }
}

// Chart 2 arrays
$c2_labels = array_column($team_rankings, 'team');
$c2_scores = array_map('floatval', array_column($team_rankings, 'discipline_score'));
$c2_colors = array_map(fn($s) => match(discipline_color($s)) {
    'red' => '#ef4444', 'amber' => '#f59e0b', default => '#22c55e'
}, $c2_scores);

// ── Players of concern (with cross-division enrichment) ────────────────────
$concern_players = [];
if ($div_pk) {
    $stmt = $pdo->prepare("
        SELECT m.player_name, m.team,
               SUM(CASE WHEN m.card_type='Yellow'
                        AND NOT EXISTS (
                            SELECT 1 FROM misconducts m2
                            WHERE m2.game_id = m.game_id
                              AND m2.player_name = m.player_name
                              AND m2.card_type = 'Red'
                        )
                   THEN 1 ELSE 0 END) AS yellows,
               SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds
        FROM misconducts m JOIN games g ON m.game_id = g.id
        WHERE g.division_id = ? AND m.player_name != 'Bench Penalty'
        GROUP BY m.player_name, m.team
        HAVING yellows >= 3 OR reds >= 1
        ORDER BY reds DESC, yellows DESC
    ");
    $stmt->execute([$div_pk]);
    $raw_concerns = $stmt->fetchAll();

    foreach ($raw_concerns as $p) {
        $compliance = get_compliance_report($pdo, $p['player_name'], 'combined');
        $concern_players[] = array_merge($p, [
            'compliance'    => $compliance,
            'div_weight'    => 0.0,
            'total_weight'  => 0.0,
            'total_yellows' => (int)$p['yellows'],
            'total_reds'    => (int)$p['reds'],
            'div_count'     => 1,
        ]);
    }

    // Cross-division enrichment
    if (!empty($concern_players)) {
        $names        = array_column($concern_players, 'player_name');
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        // Weighted points in THIS division only
        $stmt = $pdo->prepare("
            SELECT m.player_name, COALESCE(SUM($w_sql), 0) AS div_weight
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE g.division_id = ? AND m.player_name IN ($placeholders)
              AND m.player_name != 'Bench Penalty'
            GROUP BY m.player_name
        ");
        $stmt->execute(array_merge([$div_pk], $names));
        $div_weight_map = [];
        foreach ($stmt->fetchAll() as $r) {
            $div_weight_map[$r['player_name']] = (float)$r['div_weight'];
        }

        // All-division aggregates
        $stmt = $pdo->prepare("
            SELECT m.player_name,
                   COALESCE(SUM($w_sql), 0) AS total_weight,
                   SUM(CASE WHEN m.card_type='Yellow'
                            AND NOT EXISTS (
                                SELECT 1 FROM misconducts m2
                                WHERE m2.game_id = m.game_id
                                  AND m2.player_name = m.player_name
                                  AND m2.card_type = 'Red'
                            ) THEN 1 ELSE 0 END) AS total_yellows,
                   SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS total_reds,
                   COUNT(DISTINCT g.division_id) AS div_count
            FROM misconducts m JOIN games g ON m.game_id = g.id
            WHERE m.player_name IN ($placeholders) AND m.player_name != 'Bench Penalty'
            GROUP BY m.player_name
        ");
        $stmt->execute($names);
        $all_div_map = [];
        foreach ($stmt->fetchAll() as $r) {
            $all_div_map[$r['player_name']] = $r;
        }

        // Merge
        foreach ($concern_players as &$p) {
            $name = $p['player_name'];
            $p['div_weight']    = $div_weight_map[$name] ?? 0.0;
            $p['total_weight']  = isset($all_div_map[$name]) ? (float)$all_div_map[$name]['total_weight'] : $p['div_weight'];
            $p['total_yellows'] = isset($all_div_map[$name]) ? (int)$all_div_map[$name]['total_yellows']  : (int)$p['yellows'];
            $p['total_reds']    = isset($all_div_map[$name]) ? (int)$all_div_map[$name]['total_reds']     : (int)$p['reds'];
            $p['div_count']     = isset($all_div_map[$name]) ? (int)$all_div_map[$name]['div_count']      : 1;
        }
        unset($p);
    }
}

// Sort concern_players by total_weight desc for Chart 3
usort($concern_players, fn($a, $b) => $b['total_weight'] <=> $a['total_weight']);

// Chart 3 arrays
$c3_labels     = [];
$c3_full_names = [];
$c3_div_pts    = [];
$c3_other_pts  = [];
foreach ($concern_players as $p) {
    $full              = $p['player_name'];
    $c3_full_names[]   = $full;
    $c3_labels[]       = strlen($full) > 18 ? substr($full, 0, 17) . '…' : $full;
    $dw                = (float)$p['div_weight'];
    $tw                = (float)$p['total_weight'];
    $c3_div_pts[]      = round($dw, 1);
    $c3_other_pts[]    = round(max(0.0, $tw - $dw), 1);
}

// ── Volatile games (top 4) ─────────────────────────────────────────────────
$volatile_games = [];
if ($div_pk) {
    $stmt = $pdo->prepare("
        SELECT g.game_id, g.game_date, g.home_team, g.away_team,
               SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
               SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
               COUNT(m.id) AS total_cards
        FROM games g JOIN misconducts m ON m.game_id = g.id
        WHERE g.division_id = ?
        GROUP BY g.id ORDER BY total_cards DESC, reds DESC LIMIT 4
    ");
    $stmt->execute([$div_pk]);
    $volatile_games = $stmt->fetchAll();
}

// ── Team Spotlight ─────────────────────────────────────────────────────────
$spotlight            = $team_rankings[0] ?? null;
$spotlight_above_avg  = 0;
$spotlight_is_concern = false;
$spotlight_offenders  = [];

if ($spotlight && $div_avg_score > 0) {
    $spotlight_above_avg  = round(($spotlight['discipline_score'] - $div_avg_score) / $div_avg_score * 100);
    $spotlight_is_concern = $spotlight_above_avg >= 50;
}

if ($spotlight && $div_pk) {
    $stmt = $pdo->prepare("
        SELECT m.player_name, m.team,
               SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
               SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
               COUNT(*) AS total_cards
        FROM misconducts m JOIN games g ON m.game_id = g.id
        WHERE g.division_id = ? AND m.team = ? AND m.player_name != 'Bench Penalty'
        GROUP BY m.player_name
        ORDER BY total_cards DESC LIMIT 5
    ");
    $stmt->execute([$div_pk, $spotlight['team']]);
    $spotlight_offenders = $stmt->fetchAll();
}

// ── Dynamic chart heights ──────────────────────────────────────────────────
$ch2_h = max(200, min(340, count($team_rankings) * 30));
$ch3_h = max(120, min(320, count($concern_players) * 30));

// ── Page setup ─────────────────────────────────────────────────────────────
$page_title = ($div_info ? htmlspecialchars($div_info['name']) . ' — ' : '') . 'Safety Report';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.ch1-wrap { height: 380px; }
.ch2-wrap { height: <?= $ch2_h ?>px; }
.ch3-wrap { height: <?= $ch3_h ?>px; }

@media print {
    nav, #nav-drawer, #nav-toggle { display: none !important; }
    main { max-width: 100% !important; padding: 0 !important; }
    @page { margin: 12mm 10mm; size: A4 portrait; }
    body { background: white !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .shadow, .shadow-md { box-shadow: none !important; }

    /* Side-by-side charts on page 1 */
    .charts-2col { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6mm !important; }

    /* Override screen heights for print */
    .ch1-wrap { height: 160px !important; }
    .ch2-wrap { height: 160px !important; }
    .ch3-wrap { height: 150px !important; }

    /* Page 2 forced break */
    .print-page-2 { break-before: page; }

    /* Volatile cards: 4 per row */
    .volatile-grid { grid-template-columns: repeat(4, 1fr) !important; }

    /* Player cards: 3 per row */
    .player-cards-grid { grid-template-columns: repeat(3, 1fr) !important; }

    /* Appendix: 2 cols */
    .appendix-grid { grid-template-columns: 1fr 1fr !important; gap: 6mm !important; }

    /* Expand gamesheet URLs for print */
    a.gamesheet-link::after { content: " (" attr(href) ")"; font-size: 7pt; color: #6b7280; }

    section { page-break-inside: avoid; }
}
</style>

<!-- Print-only report header -->
<div class="hidden print:block mb-4 border-b border-gray-300 pb-3">
    <div class="text-lg font-bold text-gray-900">FC Regina Indoor Soccer — Division Safety Report</div>
    <div class="text-sm text-gray-600 mt-0.5">
        <?= $div_info ? htmlspecialchars($div_info['name']) : 'All Divisions' ?>
        &nbsp;·&nbsp; Season 2025/26
        &nbsp;·&nbsp; Generated: <?= date('F j, Y') ?>
    </div>
</div>

<!-- Screen-only controls -->
<div class="print:hidden flex flex-wrap items-center gap-3 mb-5">
    <form method="get" class="flex items-center gap-2">
        <label for="division_id" class="text-sm font-medium text-gray-600">Division:</label>
        <select id="division_id" name="division_id"
                onchange="this.form.submit()"
                class="text-sm border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-primary">
            <?php foreach ($all_divs as $d): ?>
            <option value="<?= (int)$d['division_id'] ?>" <?= (int)$d['division_id'] === $req_div_id ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button onclick="window.print()"
            class="flex items-center gap-1.5 bg-primary text-white text-sm font-medium px-3 py-1.5 rounded hover:bg-primary/90 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Download PDF
    </button>
</div>

<?php if (!$div_info): ?>
<div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-6">
    <h2 class="text-lg font-bold mb-1">Division not found</h2>
    <p>No division found with ID <strong><?= $req_div_id ?></strong>. Please select a division from the dropdown above.</p>
</div>
<?php else: ?>

<!-- ── Division heading ─────────────────────────────────────────────────── -->
<div class="flex flex-wrap items-start justify-between gap-2 mb-5 print:mb-3">
    <div>
        <h1 class="text-2xl font-bold text-primary"><?= htmlspecialchars($div_info['name']) ?> — Safety Report</h1>
        <div class="flex gap-2 mt-1.5 flex-wrap">
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
            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">
                Season 2025/26
            </span>
        </div>
    </div>
</div>

<!-- ── KPI Strip ───────────────────────────────────────────────────────── -->
<section class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-blue-400">
        <div class="text-xs text-gray-500">Games Played</div>
        <div class="text-2xl font-bold text-blue-600"><?= $total_games ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-primary">
        <div class="text-xs text-gray-500">Cards / Game</div>
        <div class="text-2xl font-bold text-primary"><?= $cards_per_game ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-400">
        <div class="text-xs text-gray-500">Yellow Cards</div>
        <div class="text-2xl font-bold text-amber-600"><?= $card_stats['yellows'] ?? 0 ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-red-500">
        <div class="text-xs text-gray-500">Red Cards</div>
        <div class="text-2xl font-bold text-red-600"><?= $card_stats['reds'] ?? 0 ?></div>
    </div>
</section>

<!-- ── Charts row (2-col on lg / print) ───────────────────────────────── -->
<?php if (!empty($div_scores)): ?>
<section class="mb-6">
    <div class="charts-2col grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- Chart 1: Division Scoring Index -->
        <div>
            <h2 class="text-base font-bold mb-0.5">Division Scoring Index</h2>
            <p class="text-xs text-gray-400 mb-2">All divisions — selected division in indigo. Dashed line = league avg.</p>
            <div class="bg-white rounded-lg shadow p-3">
                <div class="ch1-wrap" style="position:relative">
                    <canvas id="c1"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart 2: Team Discipline Scores -->
        <div>
            <h2 class="text-base font-bold mb-0.5">Team Discipline Scores</h2>
            <p class="text-xs text-gray-400 mb-2"><?= htmlspecialchars($div_info['name']) ?> — sorted by score desc. Dashed line = div avg.</p>
            <div class="bg-white rounded-lg shadow p-3">
                <div class="ch2-wrap" style="position:relative">
                    <canvas id="c2"></canvas>
                </div>
            </div>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- ── Team Spotlight ──────────────────────────────────────────────────── -->
<?php if ($spotlight): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Team Spotlight</h2>
    <?php if ($spotlight_is_concern): ?>
    <div class="bg-red-50 border border-red-300 rounded-lg p-4 mb-3 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="font-semibold text-red-800">High Concern — <?= htmlspecialchars($spotlight['team']) ?></p>
            <p class="text-sm text-red-700 mt-0.5">
                Discipline score of <strong><?= number_format($spotlight['discipline_score'], 2) ?></strong> is
                <strong><?= $spotlight_above_avg ?>%</strong> above the division average of <?= number_format($div_avg_score, 2) ?>.
            </p>
        </div>
    </div>
    <?php endif; ?>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
                <h3 class="text-base font-bold text-gray-900">
                    <a href="team.php?name=<?= urlencode($spotlight['team']) ?>" class="text-primary hover:underline print:no-underline">
                        <?= htmlspecialchars($spotlight['team']) ?>
                    </a>
                </h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    <?= $spotlight['games_played'] ?> games &nbsp;·&nbsp;
                    <?= $spotlight['yellows'] ?> yellow<?= $spotlight['yellows'] !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
                    <?= $spotlight['reds'] ?> red<?= $spotlight['reds'] !== 1 ? 's' : '' ?>
                </p>
            </div>
            <div class="text-right">
                <div class="text-xl font-bold <?= match(discipline_color($spotlight['discipline_score'])) { 'red' => 'text-red-600', 'amber' => 'text-amber-600', default => 'text-green-600' } ?>">
                    <?= number_format($spotlight['discipline_score'], 2) ?>
                </div>
                <div class="text-xs text-gray-500">pts/game &nbsp;·&nbsp;
                    <?= $div_avg_score > 0
                        ? ($spotlight_above_avg >= 0 ? '+' : '') . $spotlight_above_avg . '% vs div avg'
                        : 'no avg data'
                    ?>
                </div>
            </div>
        </div>
        <?php if (!empty($spotlight_offenders)): ?>
        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Top Offenders</h4>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="py-1.5 text-left text-xs font-semibold text-gray-500">Player</th>
                    <th class="py-1.5 text-center text-xs font-semibold text-gray-500">Y</th>
                    <th class="py-1.5 text-center text-xs font-semibold text-gray-500">R</th>
                    <th class="py-1.5 text-center text-xs font-semibold text-gray-500">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($spotlight_offenders as $o): ?>
                <tr>
                    <td class="py-1.5 font-medium">
                        <a href="player.php?name=<?= urlencode($o['player_name']) ?>" class="text-primary hover:underline print:no-underline">
                            <?= htmlspecialchars($o['player_name']) ?>
                        </a>
                    </td>
                    <td class="py-1.5 text-center text-amber-600 font-semibold"><?= (int)$o['yellows'] ?></td>
                    <td class="py-1.5 text-center text-red-600 font-semibold"><?= (int)$o['reds'] ?></td>
                    <td class="py-1.5 text-center font-bold"><?= (int)$o['total_cards'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- PAGE 2                                                                -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="print-page-2">

<!-- ── Player Risk Index ──────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-0.5">Player Risk Index</h2>
    <p class="text-xs text-gray-400 mb-3">Players with 3+ yellows or 1+ red in this division. Indigo = pts earned in this division; gray = pts from other divisions.</p>

    <?php if (!empty($concern_players)): ?>
    <div class="bg-white rounded-lg shadow p-3 mb-4">
        <div class="ch3-wrap" style="position:relative">
            <canvas id="c3"></canvas>
        </div>
    </div>

    <!-- Player cards grid -->
    <div class="player-cards-grid grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($concern_players as $p):
            $comp     = $p['compliance'];
            $unserved = (int)$comp['unserved_count'];
            $ys       = yellow_status((int)$p['yellows']);
            $is_multi = (int)$p['div_count'] > 1;
            $border   = (int)$p['reds'] > 0 || $ys['class'] === 'status-red'
                ? 'border-red-500'
                : ($ys['class'] === 'status-amber' ? 'border-amber-400' : 'border-green-400');
        ?>
        <div class="bg-white rounded-lg shadow p-3 border-l-4 <?= $border ?>">
            <div class="flex items-start justify-between gap-2 mb-2">
                <div>
                    <a href="player.php?name=<?= urlencode($p['player_name']) ?>"
                       class="text-sm font-bold text-primary hover:underline print:no-underline">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                    <div class="text-xs text-gray-500"><?= htmlspecialchars($p['team']) ?></div>
                </div>
                <?php if ($comp['expected_count'] === 0): ?>
                    <span class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">No susp.</span>
                <?php elseif ($unserved === 0): ?>
                    <span class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700">Compliant</span>
                <?php else: ?>
                    <span class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700"><?= $unserved ?> unserved</span>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-2 gap-x-3 text-xs border-t border-gray-100 pt-2">
                <div class="text-gray-400 mb-0.5">This division</div>
                <div class="text-gray-400 mb-0.5">All divisions</div>
                <div class="font-semibold">
                    <span class="text-amber-600"><?= (int)$p['yellows'] ?>Y</span>
                    <span class="text-red-600 ml-1"><?= (int)$p['reds'] ?>R</span>
                </div>
                <div class="font-semibold">
                    <span class="text-amber-600"><?= (int)$p['total_yellows'] ?>Y</span>
                    <span class="text-red-600 ml-1"><?= (int)$p['total_reds'] ?>R</span>
                    <?php if ($is_multi): ?>
                    <span class="ml-1 text-xs bg-blue-100 text-blue-700 px-1 rounded"><?= (int)$p['div_count'] ?> divs</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="bg-white rounded-lg shadow p-8 text-center text-gray-400">
        <p class="text-sm">No players with 3+ yellow cards or red cards in this division.</p>
    </div>
    <?php endif; ?>
</section>

<!-- ── Most Volatile Games ────────────────────────────────────────────── -->
<?php if (!empty($volatile_games)): ?>
<section class="mb-6">
    <h2 class="text-lg font-bold mb-2">Most Volatile Games</h2>
    <div class="volatile-grid grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($volatile_games as $g):
            $url        = gamesheet_url($req_div_id, (int)$g['game_id']);
            $is_high    = (int)$g['total_cards'] >= 5;
            $top_border = $is_high ? 'border-t-4 border-red-500' : 'border-t-4 border-amber-400';
        ?>
        <div class="bg-white rounded-lg shadow p-3 <?= $top_border ?>">
            <div class="text-xs text-gray-500 mb-1"><?= fmt_date($g['game_date']) ?></div>
            <div class="text-sm font-semibold text-gray-800 mb-2 leading-snug">
                <?= htmlspecialchars($g['home_team']) ?> <span class="text-gray-400 font-normal">vs</span> <?= htmlspecialchars($g['away_team']) ?>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">
                    Y <?= (int)$g['yellows'] ?>
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">
                    R <?= (int)$g['reds'] ?>
                </span>
                <a href="<?= htmlspecialchars($url) ?>" target="_blank"
                   class="gamesheet-link ml-auto text-xs text-primary hover:underline font-medium">View &rarr;</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Appendix ───────────────────────────────────────────────────────── -->
<section class="mb-6">
    <h2 class="text-lg font-bold mb-3">Appendix — Understanding the Discipline Score</h2>
    <div class="appendix-grid grid grid-cols-1 gap-4">

        <!-- Left: How it's calculated + Yellow card weights -->
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-bold text-gray-800 mb-2">How is the Discipline Score calculated?</h3>
            <p class="text-xs text-gray-600 mb-3 leading-relaxed">
                Each misconduct is assigned severity points based on the Canadian Soccer Disciplinary Code.
                Those points are added up for a team across the season, then divided by games played to get the
                <strong>Discipline Score</strong> (points per game). Lower is better.
                Bench Penalties count at 1.5&times; — a bench card signals a team culture problem, not just an individual incident.
            </p>
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="py-1.5 px-2 text-left font-semibold text-gray-600">Yellow Card Offence</th>
                        <th class="py-1.5 px-2 text-right font-semibold text-gray-600">Pts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Delay of restart / required distance / entry without permission</td>
                        <td class="py-1 px-2 text-right font-medium">1.0</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Persistent infringement (tactical fouling)</td>
                        <td class="py-1 px-2 text-right font-medium">1.5</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Unsporting behaviour</td>
                        <td class="py-1 px-2 text-right font-medium">2.0</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Dissent by word or action</td>
                        <td class="py-1 px-2 text-right font-medium">2.5</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Right: Red card weights + thresholds + suspension rules -->
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-bold text-gray-800 mb-2">Direct Red Cards</h3>
            <table class="w-full text-xs mb-3">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="py-1.5 px-2 text-left font-semibold text-gray-600">Direct Red Card Offence</th>
                        <th class="py-1.5 px-2 text-right font-semibold text-gray-600">Pts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Two-Yellow ejection</td>
                        <td class="py-1 px-2 text-right font-medium">3.0</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">DOGSO (Cat. C)</td>
                        <td class="py-1 px-2 text-right font-medium">4.5</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Serious Foul Play (Cat. B)</td>
                        <td class="py-1 px-2 text-right font-medium">6.0</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Abuse of Official (Cat. D)</td>
                        <td class="py-1 px-2 text-right font-medium">7.0</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Spitting</td>
                        <td class="py-1 px-2 text-right font-medium">7.5</td>
                    </tr>
                    <tr>
                        <td class="py-1 px-2 text-gray-700">Violent Conduct (Cat. A)</td>
                        <td class="py-1 px-2 text-right font-medium">9.0</td>
                    </tr>
                </tbody>
            </table>
            <div class="text-xs text-gray-600 mb-2">
                <strong>Risk bands:</strong>
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500 align-middle mr-0.5"></span>Clean &lt; 1.0
                &nbsp;&middot;&nbsp;
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-400 align-middle mr-0.5"></span>Elevated 1.0–2.5
                &nbsp;&middot;&nbsp;
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500 align-middle mr-0.5"></span>High Risk &gt; 2.5
            </div>
            <div class="text-xs text-gray-600 leading-relaxed">
                <strong>Suspension triggers:</strong>
                3rd yellow → 1 match (Rule 7.1) &nbsp;&middot;&nbsp;
                5th yellow → 1 match (Rule 7.2) &nbsp;&middot;&nbsp;
                7th+ yellow → 1 match each (Rule 7.3) &nbsp;&middot;&nbsp;
                Any direct red → 1 match automatic
            </div>
        </div>

    </div>
</section>

</div><!-- .print-page-2 -->

<?php endif; // $div_info ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php if (!empty($div_scores)): ?>
<script>
(function () {
    const chartRefs = [];

    // Reusable avg-line plugin factory
    function makeAvgLine(avg, textLabel) {
        return {
            id: 'avgLine',
            afterDraw(chart) {
                if (avg <= 0) return;
                const {ctx, scales: {x, y}} = chart;
                const xPx = x.getPixelForValue(avg);
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(xPx, y.top);
                ctx.lineTo(xPx, y.bottom);
                ctx.strokeStyle = '#6b7280';
                ctx.lineWidth = 1.5;
                ctx.setLineDash([4, 3]);
                ctx.stroke();
                ctx.fillStyle = '#6b7280';
                ctx.font = '10px system-ui, sans-serif';
                ctx.fillText(textLabel, xPx + 3, y.top + 10);
                ctx.restore();
            }
        };
    }

    // ── Chart 1: Division Scoring Index ────────────────────────────────
    const c1Labels = <?= json_encode($c1_labels) ?>;
    const c1Scores = <?= json_encode($c1_scores) ?>;
    const c1Colors = <?= json_encode($c1_colors) ?>;
    const leagueAvg = <?= (float)$league_avg ?>;

    chartRefs.push(new Chart(document.getElementById('c1'), {
        type: 'bar',
        data: {
            labels: c1Labels,
            datasets: [{
                data: c1Scores,
                backgroundColor: c1Colors,
                borderRadius: 3,
                barThickness: 22,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const s = ctx.parsed.x;
                            const lbl = s > 2.5 ? 'High Risk' : s >= 1.0 ? 'Elevated' : 'Clean';
                            return ` Score: ${s.toFixed(2)} / game  [${lbl}]`;
                        }
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 } } },
                y: { ticks: { font: { size: 11 }, maxRotation: 0 }, grid: { display: false } },
            },
        },
        plugins: [makeAvgLine(leagueAvg, `league avg ${leagueAvg.toFixed(2)}`)]
    }));

    // ── Chart 2: Team Discipline Scores ────────────────────────────────
    <?php if (!empty($team_rankings)): ?>
    const c2Labels = <?= json_encode($c2_labels) ?>;
    const c2Scores = <?= json_encode($c2_scores) ?>;
    const c2Colors = <?= json_encode($c2_colors) ?>;
    const divAvg   = <?= (float)$div_avg_score ?>;

    chartRefs.push(new Chart(document.getElementById('c2'), {
        type: 'bar',
        data: {
            labels: c2Labels,
            datasets: [{
                data: c2Scores,
                backgroundColor: c2Colors,
                barThickness: 18,
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.parsed.x.toFixed(2)} pts/game`
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
                y: { ticks: { font: { size: 10 }, maxRotation: 0 }, grid: { display: false } },
            },
        },
        plugins: [makeAvgLine(divAvg, `div avg ${divAvg.toFixed(2)}`)]
    }));
    <?php endif; ?>

    // ── Chart 3: Player Risk Index ──────────────────────────────────────
    <?php if (!empty($concern_players)): ?>
    const c3Labels    = <?= json_encode($c3_labels) ?>;
    const c3FullNames = <?= json_encode($c3_full_names) ?>;
    const c3DivPts    = <?= json_encode($c3_div_pts) ?>;
    const c3OtherPts  = <?= json_encode($c3_other_pts) ?>;
    const divisionName = <?= json_encode($div_info['name']) ?>;

    chartRefs.push(new Chart(document.getElementById('c3'), {
        type: 'bar',
        data: {
            labels: c3Labels,
            datasets: [
                {
                    label: divisionName + ' (this div)',
                    data: c3DivPts,
                    backgroundColor: '#6366f1',
                    barThickness: 18,
                },
                {
                    label: 'Other divisions',
                    data: c3OtherPts,
                    backgroundColor: '#d1d5db',
                    barThickness: 18,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { font: { size: 10 }, boxWidth: 12 }
                },
                tooltip: {
                    callbacks: {
                        title: (items) => c3FullNames[items[0].dataIndex],
                        label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.x.toFixed(1)} pts`
                    }
                }
            },
            scales: {
                x: { stacked: true, beginAtZero: true, ticks: { font: { size: 10 } } },
                y: { stacked: true, ticks: { font: { size: 10 }, maxRotation: 0 } },
            },
        }
    }));
    <?php endif; ?>

    // Resize all charts before printing so they re-render at print CSS dimensions
    window.onbeforeprint = () => chartRefs.forEach(c => c.resize());

})();
</script>
<?php endif; ?>

</main>
</body>
</html>
