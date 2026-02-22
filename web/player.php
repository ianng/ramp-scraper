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
    <a href="index.php" class="text-primary hover:underline">&larr; Back to Dashboard</a>
</div>

</main>
</body>
</html>
