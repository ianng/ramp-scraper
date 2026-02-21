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
    }
}

$cards_per_game = ($total_games > 0 && $card_stats['total_cards'])
    ? round($card_stats['total_cards'] / $total_games, 2) : 0;

$page_title = $div_info
    ? htmlspecialchars($div_info['name']) . ' — Divisions'
    : 'Divisions';
require_once __DIR__ . '/includes/header.php';
?>

<div class="flex flex-col md:flex-row gap-6">

<!-- ── Sidebar ─────────────────────────────────────────────────────────────── -->
<aside class="md:w-56 shrink-0">
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
    <div class="text-5xl mb-4 font-thin">&larr;</div>
    <p class="text-lg font-medium text-gray-400">Select a division</p>
    <p class="text-sm mt-1">Team standings · Top players · Volatile games</p>
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
                    <th class="px-4 py-2.5 text-center">Per Game</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($team_standings as $i => $t):
                    $gp   = $games_per_team[$t['team']] ?? 0;
                    $rate = $gp > 0 ? round($t['total_cards'] / $gp, 2) : 0;
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
                    <td class="px-4 py-2 text-center text-gray-500"><?= $rate ?></td>
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

</main>
</body>
</html>
