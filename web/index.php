<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

$pdo = get_pdo();
$view = $_GET['view'] ?? 'players';

// ── Stats ──────────────────────────────────────────────────────────────────
$total_yellows = (int)$pdo->query("SELECT COUNT(*) FROM misconducts WHERE card_type='Yellow'")->fetchColumn();
$total_reds    = (int)$pdo->query("SELECT COUNT(*) FROM misconducts WHERE card_type='Red'")->fetchColumn();
$last_scraped  = $pdo->query("SELECT MAX(scraped_at) FROM games")->fetchColumn();

// Players with a suspension currently due (3, 5, 7+ yellows with unserved suspension)
$player_yellow_counts = $pdo->query("
    SELECT m.player_name, COUNT(*) AS yellows
    FROM misconducts m
    WHERE m.card_type = 'Yellow'
    GROUP BY m.player_name
")->fetchAll();

$suspension_due_count = 0;
foreach ($player_yellow_counts as $row) {
    $status = yellow_status((int)$row['yellows']);
    if ($status['class'] === 'status-red') {
        $suspension_due_count++;
    }
}

// ── Divisions for filter dropdowns ─────────────────────────────────────────
$divisions = $pdo->query("SELECT * FROM divisions ORDER BY type, name")->fetchAll();

// ── Page title ─────────────────────────────────────────────────────────────
$page_title = match ($view) {
    'teams'         => 'Team Discipline Rankings',
    'discrepancies' => 'Discrepancy Report',
    default         => 'Misconduct Tracker',
};

include __DIR__ . '/includes/header.php';
?>

<!-- ── Stats Bar ──────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-amber-400">
        <div class="text-sm text-gray-500">Total Yellows</div>
        <div class="text-2xl font-bold text-amber-600"><?= $total_yellows ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
        <div class="text-sm text-gray-500">Total Reds</div>
        <div class="text-2xl font-bold text-red-600"><?= $total_reds ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-primary">
        <div class="text-sm text-gray-500">Suspensions Due</div>
        <div class="text-2xl font-bold text-primary"><?= $suspension_due_count ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-4 border-l-4 border-gray-400">
        <div class="text-sm text-gray-500">Last Scraped</div>
        <div class="text-lg font-semibold text-gray-700"><?= $last_scraped ? htmlspecialchars($last_scraped) : 'Never' ?></div>
    </div>
</div>

<?php if ($view === 'teams'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     TEAM DISCIPLINE RANKINGS
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$teams = $pdo->query("
    SELECT m.team,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds,
           COUNT(*) AS total_cards
    FROM misconducts m
    GROUP BY m.team
    ORDER BY total_cards DESC
")->fetchAll();
?>
<h2 class="text-xl font-bold mb-4">Team Discipline Rankings</h2>
<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-primary text-white">
            <tr>
                <th class="px-4 py-3 text-left">#</th>
                <th class="px-4 py-3 text-left">Team</th>
                <th class="px-4 py-3 text-center">Yellows</th>
                <th class="px-4 py-3 text-center">Reds</th>
                <th class="px-4 py-3 text-center">Total Cards</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($teams as $i => $t): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2"><?= $i + 1 ?></td>
                <td class="px-4 py-2 font-medium"><?= htmlspecialchars($t['team']) ?></td>
                <td class="px-4 py-2 text-center text-amber-600 font-semibold"><?= $t['yellows'] ?></td>
                <td class="px-4 py-2 text-center text-red-600 font-semibold"><?= $t['reds'] ?></td>
                <td class="px-4 py-2 text-center font-bold"><?= $t['total_cards'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($view === 'discrepancies'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     DISCREPANCY REPORT
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$all_players = $pdo->query("
    SELECT DISTINCT player_name FROM misconducts WHERE card_type='Yellow'
")->fetchAll(PDO::FETCH_COLUMN);

$discrepancies = [];
foreach ($all_players as $name) {
    $report = get_compliance_report($pdo, $name, 'combined');
    if ($report['discrepancy_count'] > 0) {
        $discrepancies[] = [
            'player'      => $name,
            'expected'    => $report['expected_count'],
            'served'      => $report['served_count'],
            'printable'   => $report['printable_count'],
            'missing'     => $report['discrepancy_count'],
            'suspensions' => $report['expected_suspensions'],
        ];
    }
}
usort($discrepancies, fn($a, $b) => $b['missing'] <=> $a['missing']);
?>
<h2 class="text-xl font-bold mb-4">Compliance Discrepancy Report</h2>
<?php if (empty($discrepancies)): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-green-800">
        No compliance discrepancies found. All suspension obligations appear fulfilled.
    </div>
<?php else: ?>
<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-danger text-white">
            <tr>
                <th class="px-4 py-3 text-left">Player</th>
                <th class="px-4 py-3 text-center">Expected Suspensions</th>
                <th class="px-4 py-3 text-center">Served</th>
                <th class="px-4 py-3 text-center">Printable Records</th>
                <th class="px-4 py-3 text-center">Missing</th>
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
                <td class="px-4 py-2 text-center"><?= $d['printable'] ?></td>
                <td class="px-4 py-2 text-center font-bold text-red-600"><?= $d['missing'] ?></td>
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

<!-- ── Filters Bar ────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form id="filter-form" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Division Type</label>
            <select id="f-type" class="border rounded px-3 py-1.5 text-sm">
                <option value="">All</option>
                <option value="Mens">Mens</option>
                <option value="Womens">Womens</option>
                <option value="Coed">Coed</option>
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
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Team</label>
            <input type="text" id="f-team" placeholder="Filter by team…" class="border rounded px-3 py-1.5 text-sm w-40">
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
            </tr>
        </thead>
        <tbody id="player-tbody" class="divide-y divide-gray-100">
<?php
// ── Initial server-side render ────────────────────────────────────────────
$players = $pdo->query("
    SELECT m.player_name,
           GROUP_CONCAT(DISTINCT m.team) AS teams,
           GROUP_CONCAT(DISTINCT d.name) AS divisions,
           COUNT(DISTINCT d.division_id) AS div_count,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) AS reds
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    GROUP BY m.player_name
    ORDER BY yellows DESC, reds DESC, m.player_name ASC
")->fetchAll();

foreach ($players as $p):
    $yellows = (int)$p['yellows'];
    $reds    = (int)$p['reds'];
    $status  = yellow_status($yellows);
    $next    = yellows_until_next($yellows);
    $guest   = (int)$p['div_count'] >= 2;
?>
            <tr class="<?= $status['class'] ?>">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=<?= urlencode($p['player_name']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                    <?php if ($guest): ?><span class="badge-guest">Guest</span><?php endif; ?>
                </td>
                <td class="px-4 py-2"><?= htmlspecialchars($p['teams']) ?></td>
                <td class="px-4 py-2 text-xs"><?= htmlspecialchars($p['divisions']) ?></td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600"><?= $yellows ?></td>
                <td class="px-4 py-2 text-center font-semibold text-red-600"><?= $reds ?></td>
                <td class="px-4 py-2"><?= $status['label'] ?></td>
                <td class="px-4 py-2 text-center"><?= $next !== null ? $next . ' away' : '—' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Compliance Alert Panel ─────────────────────────────────────────────── -->
<?php
$alerts = [];
foreach ($players as $p) {
    $yellows = (int)$p['yellows'];
    if ($yellows < 3) continue;
    $report = get_compliance_report($pdo, $p['player_name'], 'combined');
    if ($report['discrepancy_count'] > 0) {
        $alerts[] = [
            'player'  => $p['player_name'],
            'missing' => $report['discrepancy_count'],
            'expected' => $report['expected_count'],
            'served'   => max($report['served_count'], $report['printable_count']),
        ];
    }
}
usort($alerts, fn($a, $b) => $b['missing'] <=> $a['missing']);
?>
<?php if (!empty($alerts)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3 text-red-700">Compliance Alerts</h2>
    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($alerts as $a): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="font-semibold">
                <a href="player.php?name=<?= urlencode($a['player']) ?>" class="text-red-800 hover:underline">
                    <?= htmlspecialchars($a['player']) ?>
                </a>
            </div>
            <div class="text-sm text-red-600 mt-1">
                <?= $a['missing'] ?> suspension<?= $a['missing'] > 1 ? 's' : '' ?> not recorded
                <span class="text-xs text-red-400">(<?= $a['served'] ?>/<?= $a['expected'] ?> served)</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── JavaScript: AJAX filtering + CSV export ────────────────────────────── -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form    = document.getElementById('filter-form');
    const tbody   = document.getElementById('player-tbody');
    const typeEl  = document.getElementById('f-type');
    const divEl   = document.getElementById('f-division');

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
        const type = typeEl.value;          if (type) params.set('type', type);
        const div  = divEl.value;           if (div)  params.set('division_id', div);
        const team = document.getElementById('f-team').value.trim();
        if (team) params.set('team', team);
        const mode = document.getElementById('f-mode').value;
        params.set('mode', mode);
        const ymin = document.getElementById('f-ymin').value;
        const ymax = document.getElementById('f-ymax').value;
        if (ymin) params.set('yellow_min', ymin);
        if (ymax) params.set('yellow_max', ymax);

        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Loading…</td></tr>';

        try {
            const res  = await fetch('api.php?' + params.toString());
            const data = await res.json();
            renderRows(data.players ?? []);
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
            const guest = (parseInt(p.div_count) || 0) >= 2;
            return `<tr class="${esc(p.status_class)}">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=${encodeURIComponent(p.player_name)}" class="text-primary hover:underline">${esc(p.player_name)}</a>
                    ${guest ? '<span class="badge-guest">Guest</span>' : ''}
                </td>
                <td class="px-4 py-2">${esc(p.teams)}</td>
                <td class="px-4 py-2 text-xs">${esc(p.divisions)}</td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600">${p.yellows}</td>
                <td class="px-4 py-2 text-center font-semibold text-red-600">${p.reds}</td>
                <td class="px-4 py-2">${esc(p.status_label)}</td>
                <td class="px-4 py-2 text-center">${p.next_threshold !== null ? p.next_threshold + ' away' : '—'}</td>
            </tr>`;
        }).join('');
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
});
</script>

<?php endif; ?>

</main>
</body>
</html>
