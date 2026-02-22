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
               SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
               SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
               COUNT(*) AS total_cards
        FROM misconducts m WHERE m.team = ?
    ");
    $stmt->execute([$team_name]);
    $team_info = $stmt->fetch();

    if ($team_info && $team_info['team'] !== null) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.name, d.division_id, d.type
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
            FROM misconducts m WHERE m.team = ?
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
<div class="flex flex-wrap items-start justify-between gap-3 mb-5">
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
    <div class="text-right">
        <div class="text-2xl font-bold text-primary"><?= $cards_per_game ?></div>
        <div class="text-xs text-gray-500">cards / game</div>
    </div>
</div>

<!-- ── Stats bar ────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-blue-400">
        <div class="text-xs text-gray-500">Games Played</div>
        <div class="text-xl font-bold text-blue-600"><?= $games_played ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-400">
        <div class="text-xs text-gray-500">Yellows</div>
        <div class="text-xl font-bold text-amber-600"><?= $team_info['yellows'] ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-red-500">
        <div class="text-xs text-gray-500">Reds</div>
        <div class="text-xl font-bold text-red-600"><?= $team_info['reds'] ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-3 border-l-4 border-primary">
        <div class="text-xs text-gray-500">Total Cards</div>
        <div class="text-xl font-bold text-primary"><?= $team_info['total_cards'] ?></div>
    </div>
</div>

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
                    $row_class = $c['card_type'] === 'Red' ? 'status-red' : 'status-amber';
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
                        <a href="player.php?name=<?= urlencode($c['player_name']) ?>" class="text-primary hover:underline">
                            <?= htmlspecialchars($c['player_name']) ?>
                        </a>
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
</script>

</main>
</body>
</html>
