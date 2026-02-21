<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

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

// --- Fetch all data ---
$yellows_combined = get_player_yellows($pdo, $player_name, 'combined');
$reds             = get_player_reds($pdo, $player_name);
$served           = get_player_suspensions_served($pdo, $player_name);
$printable        = get_player_printable_suspensions($pdo, $player_name);
$compliance       = get_compliance_report($pdo, $player_name, $mode);

// Build full card history (yellows + reds merged, sorted by date)
$all_cards = array_merge($yellows_combined, $reds);
usort($all_cards, fn($a, $b) => strcmp($a['game_date'] . $a['game_id'], $b['game_date'] . $b['game_id']));

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
            <h3 class="font-bold text-lg">Compliance Summary</h3>
            <p class="mt-1">
                Expected suspensions: <strong><?= $comp['expected_count'] ?></strong>
                &nbsp;|&nbsp; Recorded as served: <strong><?= $comp['served_count'] ?></strong>
                &nbsp;|&nbsp; Printable gamesheet: <strong><?= $comp['printable_count'] ?></strong>
                &nbsp;|&nbsp; Discrepancy: <strong><?= $comp['discrepancy_count'] ?></strong>
            </p>
        </div>
        <?php if (!$comp['fully_compliant']): ?>
            <span class="text-lg font-bold">&#9888; <?= $comp['discrepancy_count'] ?> suspension(s) appear to have been missed</span>
        <?php else: ?>
            <span class="text-lg font-bold">&#10003; Fully Compliant</span>
        <?php endif; ?>
    </div>
</div>

<!-- Header: Player name, teams, divisions, status -->
<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="text-3xl font-bold text-primary"><?= htmlspecialchars($player_name) ?></h1>
        <div class="flex flex-wrap gap-2 mt-2">
            <?php foreach (array_keys($teams) as $team): ?>
                <span class="bg-primary/10 text-primary text-xs font-medium px-2.5 py-0.5 rounded"><?= htmlspecialchars($team) ?></span>
            <?php endforeach; ?>
            <?php foreach (array_keys($divisions) as $div): ?>
                <span class="bg-accent/20 text-green-900 text-xs font-medium px-2.5 py-0.5 rounded"><?= htmlspecialchars($div) ?></span>
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
            <div class="bg-white rounded-lg shadow overflow-hidden">
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
                            <td class="px-4 py-2"><?= htmlspecialchars($y['game_date']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($y['division_name']) ?></td>
                            <td class="px-4 py-2">#<?= htmlspecialchars($y['game_number']) ?></td>
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
                    <div class="bg-white rounded-lg shadow overflow-hidden mb-4">
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
                                    <td class="px-4 py-2"><?= htmlspecialchars($y['game_date']) ?></td>
                                    <td class="px-4 py-2">#<?= htmlspecialchars($y['game_number']) ?></td>
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
                        <th class="px-4 py-2 text-left">Game #</th>
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
                        <td class="px-4 py-2"><?= htmlspecialchars($c['game_date']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($c['division_name']) ?></td>
                        <td class="px-4 py-2">#<?= htmlspecialchars($c['game_number']) ?></td>
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
                        <th class="px-4 py-2 text-left">Game #</th>
                        <th class="px-4 py-2 text-left">Team</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($served as $s): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= htmlspecialchars($s['game_date']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($s['division_name']) ?></td>
                        <td class="px-4 py-2">#<?= htmlspecialchars($s['game_number']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($s['team']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- Printable Gamesheet Suspensions -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Printable Gamesheet Suspensions</h2>
    <?php if (empty($printable)): ?>
        <p class="text-gray-500 italic">No printable gamesheet suspensions on record.</p>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Division</th>
                        <th class="px-4 py-2 text-left">Game #</th>
                        <th class="px-4 py-2 text-left">Team</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($printable as $p): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= htmlspecialchars($p['game_date']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($p['division_name']) ?></td>
                        <td class="px-4 py-2">#<?= htmlspecialchars($p['game_number']) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars($p['team']) ?></td>
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
