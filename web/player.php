<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

function fmt_date(string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('D, M j, Y', $ts) : htmlspecialchars($iso);
}

// --- Validate player name ---
$player_name = trim($_GET['name'] ?? '');
if ($player_name === '') {
    $page_title = 'Player Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-6 mt-4">';
    echo '<h2 class="text-xl font-bold mb-2">No player specified</h2>';
    echo '<p>Please provide a player name, e.g. <code>player.php?name=John+Doe</code></p>';
    echo '<a href="index.php" class="inline-block mt-4 text-primary underline">&larr; Back to Dashboard</a>';
    echo '</div></main></body></html>';
    exit;
}

$mode = ($_GET['mode'] ?? 'combined') === 'per_division' ? 'per_division' : 'combined';

$pdo = get_pdo();

// Most-used jersey number for this player
$jersey_stmt = $pdo->prepare("
    SELECT player_number FROM misconducts
    WHERE player_name = ? AND player_number != ''
    GROUP BY player_number ORDER BY COUNT(*) DESC LIMIT 1
");
$jersey_stmt->execute([$player_name]);
$jersey_number = $jersey_stmt->fetchColumn() ?: null;

// --- Fetch all data ---
$yellows_combined = get_player_yellows($pdo, $player_name, 'combined');
$reds             = get_player_reds($pdo, $player_name);
$served           = get_player_suspensions_served($pdo, $player_name);
$printable        = get_player_printable_suspensions($pdo, $player_name);
$compliance       = get_compliance_report($pdo, $player_name, $mode);

// Build full card history (yellows + reds merged, sorted by date)
$all_cards = array_merge($yellows_combined, $reds);
usort($all_cards, fn($a, $b) => strcmp($a['game_date'], $b['game_date']) ?: ((int)$a['game_id'] <=> (int)$b['game_id']));

// Gather teams and divisions the player appeared in
$teams = [];
$divisions = [];
foreach ($all_cards as $c) {
    $teams[$c['team']] = true;
    $divisions[$c['division_name']] = $c['division_id'];
}
foreach ($served as $s) {
    $teams[$s['team']] = true;
    $divisions[$s['division_name']] = $s['division_id'];
}

// Overall status based on combined yellows
$overall_status = yellow_status(count($yellows_combined));

// CSDC danger score from all cards
$player_danger    = 0.0;
$player_breakdown = [
    'proc_y' => ['count' => 0, 'weight' => 0.0, 'label' => 'Procedural Yellow',  'color' => '#f59e0b'],
    'beh_y'  => ['count' => 0, 'weight' => 0.0, 'label' => 'Behavioural Yellow', 'color' => '#d97706'],
    'soft_r' => ['count' => 0, 'weight' => 0.0, 'label' => 'Two-Yellow Ejection','color' => '#f97316'],
    'hard_r' => ['count' => 0, 'weight' => 0.0, 'label' => 'Direct Red Card',     'color' => '#dc2626'],
];
$player_timeline = [];
foreach ($all_cards as $c) {
    $w = card_weight($c['reason'] ?? '', $c['card_type']);
    $player_danger += $w;
    if ($c['card_type'] === 'Yellow') {
        $key = yellow_weight($c['reason'] ?? '') <= 1.0 ? 'proc_y' : 'beh_y';
    } else {
        $key = red_weight($c['reason'] ?? '') <= 3.0 ? 'soft_r' : 'hard_r';
    }
    $player_breakdown[$key]['count']++;
    $player_breakdown[$key]['weight'] += $w;
    // Timeline
    $month_key = date('M Y', strtotime($c['game_date'] ?: 'now'));
    if (!isset($player_timeline[$month_key])) {
        $player_timeline[$month_key] = ['y' => 0, 'r' => 0, 'sort' => $c['game_date'] ?: ''];
    }
    if ($c['card_type'] === 'Yellow') $player_timeline[$month_key]['y']++;
    else $player_timeline[$month_key]['r']++;
}
uasort($player_timeline, fn($a, $b) => strcmp($a['sort'], $b['sort']));
$pdanger_color  = $player_danger > 7.0 ? 'red'   : ($player_danger >= 3.0 ? 'amber' : 'green');
$pdanger_label  = $player_danger > 7.0 ? 'High Risk' : ($player_danger >= 3.0 ? 'Moderate Risk' : 'Low Risk');
$pdanger_text   = match($pdanger_color) { 'red' => 'text-red-600',   'amber' => 'text-amber-600',  default => 'text-green-600'  };
$pdanger_border = match($pdanger_color) { 'red' => 'border-red-500', 'amber' => 'border-amber-400', default => 'border-green-400' };

// League average danger score + player ranking
$w_sql_p = weight_sql();
$league_avg_danger = (float)$pdo->query("
    SELECT AVG(dw) FROM (
        SELECT m.player_name, SUM($w_sql_p) AS dw
        FROM misconducts m
        WHERE m.player_name != 'Bench Penalty'
        GROUP BY m.player_name
    )
")->fetchColumn();
$stmt_rank = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT m.player_name, SUM($w_sql_p) AS dw
        FROM misconducts m
        WHERE m.player_name != 'Bench Penalty'
        GROUP BY m.player_name
        HAVING dw > ?
    )
");
$stmt_rank->execute([$player_danger]);
$danger_rank         = (int)$stmt_rank->fetchColumn() + 1;
$total_ranked        = (int)$pdo->query("SELECT COUNT(DISTINCT player_name) FROM misconducts WHERE player_name != 'Bench Penalty'")->fetchColumn();

// Per-division data (for per_division mode)
$division_yellows = [];
if ($mode === 'per_division') {
    foreach ($divisions as $div_name => $div_id) {
        $division_yellows[$div_name] = get_player_yellows($pdo, $player_name, 'per_division', $div_id);
    }
}

$page_title = htmlspecialchars($player_name) . ' — Player Audit';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Compliance Summary Box -->
<?php
$comp = $compliance;
$comp_class = $comp['fully_compliant'] ? 'bg-green-50 border-green-400 text-green-800' : 'bg-red-50 border-red-400 text-red-800';
?>
<div class="<?= $comp_class ?> border-2 rounded-lg p-5 mb-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h3 class="font-bold text-lg">Suspension Summary</h3>
            <p class="mt-1">
                Triggered: <strong><?= $comp['expected_count'] ?></strong>
                &nbsp;|&nbsp; Served: <strong><?= $comp['served_count'] ?></strong>
                &nbsp;|&nbsp; Unserved: <strong><?= $comp['unserved_count'] ?></strong>
            </p>
            <?php if ($comp['expected_count'] > 0): ?>
            <p class="text-xs mt-1 opacity-75">
                <?= $comp['expected_yellow_count'] ?> from yellow accumulation
                <?php if ($comp['expected_red_count'] > 0): ?>
                    &nbsp;+&nbsp; <?= $comp['expected_red_count'] ?> from red card<?= $comp['expected_red_count'] !== 1 ? 's' : '' ?>
                <?php endif; ?>
                <?php if ($comp['unserved_count'] > 0): ?>
                    &mdash; unserved suspensions may be pending the league's next monthly report
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if (!$comp['fully_compliant']): ?>
            <span class="text-lg font-bold">&#9888; <?= $comp['unserved_count'] ?> suspension(s) unserved</span>
        <?php else: ?>
            <span class="text-lg font-bold">&#10003; All Suspensions Served</span>
        <?php endif; ?>
    </div>
</div>

<!-- Header: Player name, teams, divisions, status -->
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold text-primary"><?= htmlspecialchars($player_name) ?></h1>
            <?php if ($jersey_number !== null): ?>
            <span class="text-lg font-semibold bg-gray-200 text-gray-600 px-2 py-0.5 rounded">#<?= htmlspecialchars($jersey_number) ?></span>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2 mt-2">
            <?php foreach (array_keys($teams) as $team): ?>
                <a href="team.php?name=<?= urlencode($team) ?>" class="bg-primary/10 text-primary text-xs font-medium px-2.5 py-0.5 rounded hover:bg-primary/20 transition-colors"><?= htmlspecialchars($team) ?></a>
            <?php endforeach; ?>
            <?php foreach ($divisions as $div => $div_id_val): ?>
                <a href="division.php?id=<?= (int)$div_id_val ?>" class="bg-accent/20 text-green-900 text-xs font-medium px-2.5 py-0.5 rounded hover:bg-accent/40 transition-colors"><?= htmlspecialchars($div) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div>
        <span class="inline-block px-4 py-2 rounded-lg font-semibold text-sm <?= $overall_status['class'] ?>">
            <?= $overall_status['label'] ?> (<?= count($yellows_combined) ?> yellow<?= count($yellows_combined) !== 1 ? 's' : '' ?>)
        </span>
    </div>
</div>

<!-- Player Stats Bar -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-400">
        <div class="text-xs text-gray-500">Yellow Cards</div>
        <div class="text-xl font-bold text-amber-600"><?= count($yellows_combined) ?></div>
        <div class="text-xs text-gray-400 mt-0.5"><?= $overall_status['label'] ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-red-500">
        <div class="text-xs text-gray-500">Red Cards</div>
        <div class="text-xl font-bold text-red-600"><?= count($reds) ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 <?= $pdanger_border ?>">
        <div class="text-xs text-gray-500 flex items-center justify-between">
            <span>Danger Score</span>
            <a href="scoring.php" class="text-gray-300 hover:text-primary" title="About scoring">ⓘ</a>
        </div>
        <div class="text-xl font-bold <?= $pdanger_text ?>"><?= number_format($player_danger, 1) ?></div>
        <div class="text-xs <?= $pdanger_text ?> font-medium"><?= $pdanger_label ?></div>
        <?php if ($league_avg_danger > 0): ?>
        <?php $vs_avg = $player_danger - $league_avg_danger; ?>
        <div class="text-xs text-gray-400 mt-1">
            Avg: <?= number_format($league_avg_danger, 1) ?>
            <span class="<?= $vs_avg > 0 ? 'text-red-500' : 'text-green-600' ?> font-semibold">
                (<?= $vs_avg > 0 ? '+' : '' ?><?= number_format($vs_avg, 1) ?>)
            </span>
        </div>
        <div class="text-xs text-gray-400">
            #<?= $danger_rank ?> of <?= $total_ranked ?> players
        </div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-primary">
        <div class="text-xs text-gray-500">Total Cards</div>
        <div class="text-xl font-bold text-primary"><?= count($all_cards) ?></div>
    </div>
</div>

<?php if (!empty($all_cards)): ?>
<?php
$pb_labels  = [];
$pb_data    = [];
$pb_colors  = [];
foreach ($player_breakdown as $tier) {
    if ($tier['count'] === 0) continue;
    $pb_labels[]  = $tier['label'];
    $pb_data[]    = round($tier['weight'], 2);
    $pb_colors[]  = $tier['color'];
}
$ptl_labels  = json_encode(array_keys($player_timeline));
$ptl_yellows = json_encode(array_values(array_column($player_timeline, 'y')));
$ptl_reds    = json_encode(array_values(array_column($player_timeline, 'r')));
?>
<!-- Player Charts -->
<div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">

    <!-- CSDC Weight Breakdown -->
    <div class="md:col-span-2 bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-600 mb-3">Severity Breakdown
            <span class="font-normal text-gray-400 text-xs">(CSDC weight)</span>
        </h3>
        <div style="height:100px">
            <canvas id="chart-player-severity"></canvas>
        </div>
    </div>

    <!-- Card Timeline -->
    <div class="md:col-span-3 bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-600 mb-3">Card History Timeline</h3>
        <div style="height:100px">
            <canvas id="chart-player-timeline"></canvas>
        </div>
    </div>

</div>
<?php endif; ?>

<!-- View Toggle -->
<div class="mb-6 flex gap-1">
    <a href="?name=<?= urlencode($player_name) ?>&mode=combined"
       class="px-4 py-2 rounded-t-lg text-sm font-medium <?= $mode === 'combined' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
        Combined
    </a>
    <a href="?name=<?= urlencode($player_name) ?>&mode=per_division"
       class="px-4 py-2 rounded-t-lg text-sm font-medium <?= $mode === 'per_division' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
        Per Division
    </a>
</div>

<!-- Yellow Card Accumulation Timeline -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Yellow Card Accumulation Timeline</h2>

    <?php if ($mode === 'combined'): ?>
        <?php
        $yellows = $yellows_combined;
        $triggers = calculate_expected_suspensions($yellows);
        $trigger_map = [];
        foreach ($triggers as $t) {
            $trigger_map[$t['trigger_yellow_count']] = $t['rule'];
        }
        ?>
        <?php if (empty($yellows)): ?>
            <p class="text-gray-500 italic">No yellow cards recorded.</p>
        <?php else: ?>
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-2 text-left w-16">#</th>
                            <th class="px-4 py-2 text-left">Date</th>
                            <th class="px-4 py-2 text-left">Division</th>
                            <th class="px-4 py-2 text-left">Game</th>
                            <th class="px-4 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yellows as $i => $y):
                            $num = $i + 1;
                            $is_trigger = isset($trigger_map[$num]);
                            $row_class = $is_trigger ? 'bg-red-50' : ($num === 2 || $num === 4 || $num === 6 ? 'bg-amber-50' : '');
                        ?>
                        <tr class="<?= $row_class ?> border-t">
                            <td class="px-4 py-2 font-bold"><?= $num ?></td>
                            <td class="px-4 py-2"><?= fmt_date($y['game_date']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($y['division_name']) ?></td>
                            <td class="px-4 py-2"><a href="<?= gamesheet_url((int)$y['division_id'], (int)$y['game_id']) ?>" target="_blank" class="text-primary hover:underline"><?= (int)$y['game_id'] ?></a></td>
                            <td class="px-4 py-2 font-medium">
                                <?php if ($is_trigger): ?>
                                    <span class="text-red-700 font-bold">SUSPENSION TRIGGERED — Rule <?= $trigger_map[$num] ?></span>
                                <?php elseif ($num === 2 || $num === 4 || $num === 6): ?>
                                    <span class="text-amber-700">Warning</span>
                                <?php else: ?>
                                    <span class="text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: /* per_division */ ?>
        <?php if (empty($division_yellows)): ?>
            <p class="text-gray-500 italic">No yellow cards recorded.</p>
        <?php else: ?>
            <?php foreach ($division_yellows as $div_name => $yellows):
                $triggers = calculate_expected_suspensions($yellows);
                $trigger_map = [];
                foreach ($triggers as $t) {
                    $trigger_map[$t['trigger_yellow_count']] = $t['rule'];
                }
            ?>
                <h3 class="text-lg font-semibold text-gray-700 mt-4 mb-2"><?= htmlspecialchars($div_name) ?></h3>
                <?php if (empty($yellows)): ?>
                    <p class="text-gray-500 italic">No yellows in this division.</p>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow overflow-x-auto mb-4">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-4 py-2 text-left w-16">#</th>
                                    <th class="px-4 py-2 text-left">Date</th>
                                    <th class="px-4 py-2 text-left">Game</th>
                                    <th class="px-4 py-2 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($yellows as $i => $y):
                                    $num = $i + 1;
                                    $is_trigger = isset($trigger_map[$num]);
                                    $row_class = $is_trigger ? 'bg-red-50' : ($num === 2 || $num === 4 || $num === 6 ? 'bg-amber-50' : '');
                                ?>
                                <tr class="<?= $row_class ?> border-t">
                                    <td class="px-4 py-2 font-bold"><?= $num ?></td>
                                    <td class="px-4 py-2"><?= fmt_date($y['game_date']) ?></td>
                                    <td class="px-4 py-2"><a href="<?= gamesheet_url((int)$y['division_id'], (int)$y['game_id']) ?>" target="_blank" class="text-primary hover:underline"><?= (int)$y['game_id'] ?></a></td>
                                    <td class="px-4 py-2 font-medium">
                                        <?php if ($is_trigger): ?>
                                            <span class="text-red-700 font-bold">SUSPENSION TRIGGERED — Rule <?= $trigger_map[$num] ?></span>
                                        <?php elseif ($num === 2 || $num === 4 || $num === 6): ?>
                                            <span class="text-amber-700">Warning</span>
                                        <?php else: ?>
                                            <span class="text-gray-500">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- Red Card Suspensions -->
<?php if (!empty($comp['expected_red_suspensions'])): ?>
<section class="mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Red Card Suspensions</h2>
    <p class="text-sm text-gray-500 mb-3">Each red card (including ejection via two yellows in one game) carries an automatic 1-match suspension, independent of yellow card accumulation.</p>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left w-16">#</th>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Division</th>
                    <th class="px-4 py-2 text-left">Game</th>
                    <th class="px-4 py-2 text-left">Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comp['expected_red_suspensions'] as $i => $s):
                    $g = $s['trigger_game'];
                ?>
                <tr class="bg-red-50 border-t">
                    <td class="px-4 py-2 font-bold"><?= $i + 1 ?></td>
                    <td class="px-4 py-2"><?= fmt_date($g['game_date']) ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($g['division_name']) ?></td>
                    <td class="px-4 py-2"><a href="<?= gamesheet_url((int)$g['division_id'], (int)$g['game_id']) ?>" target="_blank" class="text-primary hover:underline"><?= (int)$g['game_id'] ?></a></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($g['reason'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Card History Table -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Card History</h2>
    <?php if (empty($all_cards)): ?>
        <p class="text-gray-500 italic">No cards recorded for this player.</p>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Division</th>
                        <th class="px-4 py-2 text-left">Game</th>
                        <th class="px-4 py-2 text-left">Team</th>
                        <th class="px-4 py-2 text-left">Reason</th>
                        <th class="px-4 py-2 text-left">Card</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_cards as $c):
                        $row_class = $c['card_type'] === 'Red' ? 'status-red' : 'status-amber';
                    ?>
                    <tr class="<?= $row_class ?> border-t">
                        <td class="px-4 py-2"><?= fmt_date($c['game_date']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($c['division_name']) ?></td>
                        <td class="px-4 py-2"><a href="<?= gamesheet_url((int)$c['division_id'], (int)$c['game_id']) ?>" target="_blank" class="text-primary hover:underline"><?= (int)$c['game_id'] ?></a></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($c['team']) ?></td>
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

<!-- Suspension History (served) -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Suspensions Served</h2>
    <?php if (empty($served)): ?>
        <p class="text-gray-500 italic">No suspensions served on record.</p>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Division</th>
                        <th class="px-4 py-2 text-left">Game</th>
                        <th class="px-4 py-2 text-left">Team</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($served as $s): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= fmt_date($s['game_date']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($s['division_name']) ?></td>
                        <td class="px-4 py-2"><a href="<?= gamesheet_url((int)$s['division_id'], (int)$s['game_id']) ?>" target="_blank" class="text-primary hover:underline"><?= (int)$s['game_id'] ?></a></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($s['team']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>


<div class="mt-6 mb-4">
    <a href="players.php" class="text-primary hover:underline">&larr; Back to Players</a>
</div>

</main>
</body>
<?php if (!empty($all_cards)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function() {
    const sevCtx = document.getElementById('chart-player-severity');
    if (sevCtx) {
        new Chart(sevCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($pb_labels) ?>,
                datasets: [{
                    data:            <?= json_encode($pb_data) ?>,
                    backgroundColor: <?= json_encode($pb_colors) ?>,
                    borderRadius: 3,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.x.toFixed(1)} pts` } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { font: { size: 9 } } },
                    y: { ticks: { font: { size: 9 } } }
                }
            }
        });
    }

    const tlCtx = document.getElementById('chart-player-timeline');
    if (tlCtx) {
        new Chart(tlCtx, {
            type: 'bar',
            data: {
                labels: <?= $ptl_labels ?>,
                datasets: [
                    { label: 'Yellows', data: <?= $ptl_yellows ?>, backgroundColor: '#f59e0b', stack: 'c' },
                    { label: 'Reds',    data: <?= $ptl_reds ?>,    backgroundColor: '#dc2626', stack: 'c' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } }
                },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 9 } } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, font: { size: 9 } } }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
</html>
