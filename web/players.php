<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

$pdo = get_pdo();
$page_title = 'Players';

$divisions = $pdo->query("SELECT * FROM divisions ORDER BY type, name")->fetchAll();

$_w_sql = weight_sql('m.reason', 'm.card_type', 'm.player_name');
$players = $pdo->query("
    SELECT m.player_name,
           GROUP_CONCAT(DISTINCT m.team) AS teams,
           GROUP_CONCAT(DISTINCT d.name) AS divisions,
           SUM(CASE WHEN m.card_type='Yellow'
                     AND NOT EXISTS (
                         SELECT 1 FROM misconducts m2
                         WHERE m2.game_id = m.game_id
                           AND m2.player_name = m.player_name
                           AND m2.card_type = 'Red'
                     ) THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           SUM($_w_sql) AS danger_weight
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.player_name
    ORDER BY yellows DESC, reds DESC, m.player_name ASC
")->fetchAll();

// Pre-compute compliance for players at or past a suspension threshold.
$susp_map = [];
foreach ($players as $_p) {
    if ((int)$_p['yellows'] < 3 && (int)$_p['reds'] === 0) continue;
    $susp_map[$_p['player_name']] = get_compliance_report($pdo, $_p['player_name'], 'combined');
}

// Build unserved suspension alerts.
$alerts = [];
foreach ($players as $p) {
    $report = $susp_map[$p['player_name']] ?? null;
    if (!$report || $report['unserved_count'] <= 0) continue;
    $alerts[] = [
        'player'   => $p['player_name'],
        'unserved' => $report['unserved_count'],
        'expected' => $report['expected_count'],
        'served'   => $report['served_count'],
    ];
}
usort($alerts, fn($a, $b) => $b['unserved'] <=> $a['unserved']);

$all_teams = $pdo->query("SELECT DISTINCT team FROM misconducts ORDER BY team ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/includes/header.php';
?>

<!-- ── Filters Bar ────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form id="filter-form" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Division Type</label>
            <select id="f-type" class="border rounded px-3 py-1.5 text-sm">
                <option value="">All</option>
                <option value="mens">Mens</option>
                <option value="womens">Womens</option>
                <option value="coed">Coed</option>
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
        <div class="relative" id="team-combo-wrapper">
            <label class="block text-xs font-medium text-gray-500 mb-1">Team</label>
            <input type="text" id="f-team-search" placeholder="All teams…" autocomplete="off"
                   class="border rounded px-3 py-1.5 text-sm w-48">
            <input type="hidden" id="f-team" value="">
            <div id="team-dropdown" class="hidden absolute z-10 left-0 bg-white border rounded shadow-lg mt-1 w-48 max-h-48 overflow-y-auto text-sm"></div>
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

<!-- ── Player Card Counts Table ──────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-3">
    <h2 class="text-xl font-bold">Player Card Counts</h2>
    <button id="btn-export" class="bg-gray-100 border border-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm hover:bg-gray-200 transition-colors">
        Export CSV
    </button>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto mb-3">
    <table id="player-table" class="w-full text-sm">
        <thead class="bg-primary text-white">
            <tr id="sort-header-row">
                <th class="px-4 py-3 text-left cursor-pointer select-none hover:bg-white/10" data-sort="name">Player <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-left cursor-pointer select-none hover:bg-white/10" data-sort="teams">Team(s) <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-left cursor-pointer select-none hover:bg-white/10" data-sort="divs">Division(s) <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-white/10" data-sort="yellows">Yellows <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-white/10" data-sort="reds">Reds <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-white/10" data-sort="danger">Danger <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-left cursor-pointer select-none hover:bg-white/10" data-sort="status">Status <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-center cursor-pointer select-none hover:bg-white/10" data-sort="next">Next <span class="sort-ind text-white/40">↕</span></th>
                <th class="px-4 py-3 text-left cursor-pointer select-none hover:bg-white/10" data-sort="served">Served <span class="sort-ind text-white/40">↕</span></th>
            </tr>
        </thead>
        <tbody id="player-tbody" class="divide-y divide-gray-100">
<?php
foreach ($players as $p):
    $yellows      = (int)$p['yellows'];
    $reds         = (int)$p['reds'];
    $danger       = round((float)$p['danger_weight'], 1);
    $status       = yellow_status($yellows);
    $next         = yellows_until_next($yellows);
    $rpt          = $susp_map[$p['player_name']] ?? null;
    $served_label = !$rpt || $rpt['expected_count'] === 0 ? '—'
                  : ($rpt['fully_compliant'] ? 'Served' : $rpt['unserved_count'] . ' unserved');
    $danger_class = $danger > 7.0 ? 'text-red-600 font-bold' : ($danger >= 3.0 ? 'text-amber-600 font-semibold' : 'text-green-600');
?>
            <tr class="<?= $status['class'] ?>"
                data-name="<?= htmlspecialchars($p['player_name']) ?>"
                data-teams="<?= htmlspecialchars($p['teams']) ?>"
                data-divs="<?= htmlspecialchars($p['divisions']) ?>"
                data-yellows="<?= $yellows ?>"
                data-reds="<?= $reds ?>"
                data-danger="<?= $danger ?>"
                data-status="<?= htmlspecialchars($status['label']) ?>"
                data-next="<?= $next ?? 999 ?>"
                data-served="<?= htmlspecialchars($served_label) ?>">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=<?= urlencode($p['player_name']) ?>" class="text-primary hover:underline">
                        <?= htmlspecialchars($p['player_name']) ?>
                    </a>
                </td>
                <td class="px-4 py-2" data-label="Team"><?= htmlspecialchars($p['teams']) ?></td>
                <td class="px-4 py-2 text-xs" data-label="Division"><?= htmlspecialchars($p['divisions']) ?></td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600" data-label="Yellow"><?= $yellows ?></td>
                <td class="px-4 py-2 text-center font-semibold text-red-600" data-label="Red"><?= $reds ?></td>
                <td class="px-4 py-2 text-center <?= $danger_class ?>" data-label="Danger"><?= number_format($danger, 1) ?></td>
                <td class="px-4 py-2" data-label="Status"><?= $status['label'] ?></td>
                <td class="px-4 py-2 text-center" data-label="Next"><?= $next !== null ? $next . ' away' : '—' ?></td>
                <td class="px-4 py-2" data-label="Served">
                    <?php if (!$rpt || $rpt['expected_count'] === 0): ?>
                        <span class="text-gray-400">—</span>
                    <?php elseif ($rpt['fully_compliant']): ?>
                        <span class="text-green-700 font-medium">&#10003; Served</span>
                    <?php else: ?>
                        <span class="text-red-600 font-semibold"><?= $rpt['unserved_count'] ?> unserved</span>
                    <?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Pagination Controls ──────────────────────────────────────────────────── -->
<div id="pagination-controls" class="flex flex-wrap items-center justify-between mb-6 px-1 gap-2">
    <div class="text-sm text-gray-500" id="pagination-info"></div>
    <div class="flex items-center gap-2">
        <label class="text-sm text-gray-500">Per page:</label>
        <select id="per-page" class="border rounded px-2 py-1 text-sm">
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="0">All</option>
        </select>
        <button id="btn-prev" class="px-3 py-1 rounded border text-sm bg-white hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed" disabled>
            &larr; Prev
        </button>
        <button id="btn-next" class="px-3 py-1 rounded border text-sm bg-white hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed">
            Next &rarr;
        </button>
    </div>
</div>

<!-- ── Unserved Suspensions ──────────────────────────────────────────────────── -->
<?php if (!empty($alerts)): ?>
<div class="mb-6">
    <h2 class="text-xl font-bold mb-3 text-red-700">Unserved Suspensions</h2>
    <p class="text-sm text-gray-500 mb-3">Suspensions triggered by yellow card accumulation that have not yet been recorded as served. Note: the league enforces these via a monthly report — they may be pending rather than definitively missed.</p>
    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($alerts as $a): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="font-semibold">
                <a href="player.php?name=<?= urlencode($a['player']) ?>" class="text-red-800 hover:underline">
                    <?= htmlspecialchars($a['player']) ?>
                </a>
            </div>
            <div class="text-sm text-red-600 mt-1">
                <?= $a['unserved'] ?> unserved suspension<?= $a['unserved'] > 1 ? 's' : '' ?>
                <span class="text-xs text-red-400">(<?= $a['served'] ?>/<?= $a['expected'] ?> served)</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form       = document.getElementById('filter-form');
    const tbody      = document.getElementById('player-tbody');
    const typeEl     = document.getElementById('f-type');
    const divEl      = document.getElementById('f-division');
    const teamSearch = document.getElementById('f-team-search');
    const teamHidden = document.getElementById('f-team');
    const teamDrop   = document.getElementById('team-dropdown');
    const allTeams   = <?= json_encode($all_teams) ?>;

    // ── Pagination ─────────────────────────────────────────────────────────────
    let currentPage = 1;
    let perPage     = 25;

    function paginateDom() {
        const rows  = Array.from(tbody.querySelectorAll('tr[data-name]'));
        const total = rows.length;
        if (total === 0) { updatePaginationInfo(0, 0, 0); return; }
        const start = perPage === 0 ? 0 : (currentPage - 1) * perPage;
        const end   = perPage === 0 ? total : Math.min(start + perPage, total);
        rows.forEach((r, i) => r.style.display = (i >= start && i < end) ? '' : 'none');
        updatePaginationInfo(total, start + 1, end);
    }

    function updatePaginationInfo(total, from, to) {
        const info    = document.getElementById('pagination-info');
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');
        const pages   = perPage === 0 ? 1 : Math.ceil(total / perPage);
        info.textContent = total === 0
            ? 'No results'
            : `Showing ${from}–${to} of ${total} players`;
        btnPrev.disabled = currentPage <= 1;
        btnNext.disabled = currentPage >= pages || perPage === 0;
    }

    document.getElementById('btn-prev').addEventListener('click', () => {
        if (currentPage > 1) { currentPage--; paginateDom(); }
    });
    document.getElementById('btn-next').addEventListener('click', () => {
        const total = tbody.querySelectorAll('tr[data-name]').length;
        const pages = perPage === 0 ? 1 : Math.ceil(total / perPage);
        if (currentPage < pages) { currentPage++; paginateDom(); }
    });
    document.getElementById('per-page').addEventListener('change', e => {
        perPage = parseInt(e.target.value);
        currentPage = 1;
        paginateDom();
    });

    // Initial pagination on page load
    paginateDom();

    // ── Team combobox ──────────────────────────────────────────────────────────
    function renderTeamOptions(q) {
        const lower    = q.toLowerCase();
        const filtered = q ? allTeams.filter(t => t.toLowerCase().includes(lower)) : allTeams;
        const clearOpt = '<div class="px-3 py-1.5 cursor-pointer hover:bg-gray-100 text-gray-400 italic" data-val="">All teams</div>';
        teamDrop.innerHTML = clearOpt + (filtered.length
            ? filtered.map(t => `<div class="px-3 py-1.5 cursor-pointer hover:bg-primary hover:text-white truncate" data-val="${esc(t)}">${esc(t)}</div>`).join('')
            : '<div class="px-3 py-2 text-gray-400 text-xs">No match</div>');
    }

    teamSearch.addEventListener('focus', () => {
        renderTeamOptions(teamSearch.value);
        teamDrop.classList.remove('hidden');
    });
    teamSearch.addEventListener('input', () => {
        teamHidden.value = '';
        renderTeamOptions(teamSearch.value);
        teamDrop.classList.remove('hidden');
    });
    teamDrop.addEventListener('mousedown', e => {
        const opt = e.target.closest('[data-val]');
        if (!opt) return;
        e.preventDefault();
        teamHidden.value = opt.dataset.val;
        teamSearch.value = opt.dataset.val;
        teamDrop.classList.add('hidden');
    });
    document.addEventListener('click', e => {
        if (!document.getElementById('team-combo-wrapper').contains(e.target))
            teamDrop.classList.add('hidden');
    });

    // Filter division dropdown when type changes
    typeEl.addEventListener('change', () => {
        const sel = typeEl.value;
        for (const opt of divEl.options) {
            if (!opt.value) { opt.hidden = false; continue; }
            opt.hidden = sel && opt.dataset.type !== sel;
        }
        if (divEl.selectedOptions[0]?.hidden) divEl.value = '';
    });

    // ── Sort ───────────────────────────────────────────────────────────────────
    let sortCol  = null;
    let sortDir  = -1;
    let lastData = null;

    const NUMERIC_COLS = new Set(['yellows', 'reds', 'danger', 'next']);
    const DEFAULT_DESC = new Set(['yellows', 'reds', 'danger']);

    document.querySelectorAll('#sort-header-row th[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            if (sortCol === col) {
                sortDir *= -1;
            } else {
                sortCol = col;
                sortDir = DEFAULT_DESC.has(col) ? -1 : 1;
            }
            currentPage = 1;
            if (lastData) {
                renderRows(lastData);
            } else {
                sortDomRows();
                paginateDom();
            }
            updateSortIndicators();
        });
    });

    function updateSortIndicators() {
        document.querySelectorAll('#sort-header-row th[data-sort]').forEach(th => {
            const ind = th.querySelector('.sort-ind');
            if (!ind) return;
            if (th.dataset.sort !== sortCol) {
                ind.textContent = '↕';
                ind.className = 'sort-ind text-white/40';
            } else {
                ind.textContent = sortDir === 1 ? '↑' : '↓';
                ind.className = 'sort-ind';
            }
        });
    }

    function sortDomRows() {
        const rows = Array.from(tbody.querySelectorAll('tr[data-name]'));
        if (!rows.length || !sortCol) return;
        rows.sort((a, b) => {
            const av = NUMERIC_COLS.has(sortCol)
                ? parseFloat(a.dataset[sortCol] ?? 0)
                : (a.dataset[sortCol] ?? '').toLowerCase();
            const bv = NUMERIC_COLS.has(sortCol)
                ? parseFloat(b.dataset[sortCol] ?? 0)
                : (b.dataset[sortCol] ?? '').toLowerCase();
            return sortDir * (av < bv ? -1 : av > bv ? 1 : 0);
        });
        rows.forEach(r => tbody.appendChild(r));
    }

    function applySortToData(players) {
        if (!sortCol) return players;
        const colMap = {
            name:    p => p.name.toLowerCase(),
            teams:   p => p.teams.join(',').toLowerCase(),
            divs:    p => p.divisions.join(',').toLowerCase(),
            yellows: p => p.yellow_count,
            reds:    p => p.red_count,
            danger:  p => p.danger_score,
            status:  p => p.status_label.toLowerCase(),
            next:    p => p.next_threshold ?? 999,
            served:  p => p.served_label.toLowerCase(),
        };
        const key = colMap[sortCol] ?? (p => p.name.toLowerCase());
        return [...players].sort((a, b) => {
            const av = key(a), bv = key(b);
            return sortDir * (av < bv ? -1 : av > bv ? 1 : 0);
        });
    }

    function renderRows(players) {
        if (!players.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">No players match filters.</td></tr>';
            updatePaginationInfo(0, 0, 0);
            return;
        }
        const sorted = applySortToData(players);
        tbody.innerHTML = sorted.map(p => {
            const danger      = p.danger_score ?? 0;
            const dangerClass = danger > 7.0 ? 'text-red-600 font-bold'
                              : danger >= 3.0 ? 'text-amber-600 font-semibold'
                              : 'text-green-600';
            return `<tr class="${esc(p.status_class)}"
                data-name="${esc(p.name)}"
                data-teams="${esc(p.teams.join(', '))}"
                data-divs="${esc(p.divisions.join(', '))}"
                data-yellows="${p.yellow_count}"
                data-reds="${p.red_count}"
                data-danger="${danger}"
                data-status="${esc(p.status_label)}"
                data-next="${p.next_threshold ?? 999}"
                data-served="${esc(p.served_label)}">
                <td class="px-4 py-2 font-medium">
                    <a href="player.php?name=${encodeURIComponent(p.name)}" class="text-primary hover:underline">${esc(p.name)}</a>
                </td>
                <td class="px-4 py-2" data-label="Team">${esc(p.teams.join(', '))}</td>
                <td class="px-4 py-2 text-xs" data-label="Division">${esc(p.divisions.join(', '))}</td>
                <td class="px-4 py-2 text-center font-semibold text-amber-600" data-label="Yellow">${p.yellow_count}</td>
                <td class="px-4 py-2 text-center font-semibold text-red-600" data-label="Red">${p.red_count}</td>
                <td class="px-4 py-2 text-center ${dangerClass}" data-label="Danger">${danger.toFixed(1)}</td>
                <td class="px-4 py-2" data-label="Status">${esc(p.status_label)}</td>
                <td class="px-4 py-2 text-center" data-label="Next">${p.next_threshold !== null ? p.next_threshold + ' away' : '—'}</td>
                <td class="px-4 py-2" data-label="Served"><span class="${esc(p.served_class)}">${esc(p.served_label)}</span></td>
            </tr>`;
        }).join('');
        currentPage = 1;
        paginateDom();
    }

    // ── AJAX filter ─────────────────────────────────────────────────────────────
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const params = new URLSearchParams({ action: 'players' });
        const type = typeEl.value;              if (type) params.set('div_type', type);
        const div  = divEl.value;               if (div)  params.set('division_id', div);
        const team = teamHidden.value.trim();   if (team) params.set('team', team);
        params.set('mode', document.getElementById('f-mode').value);
        const ymin = document.getElementById('f-ymin').value;
        const ymax = document.getElementById('f-ymax').value;
        if (ymin) params.set('min_yellows', ymin);
        if (ymax) params.set('max_yellows', ymax);

        tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">Loading…</td></tr>';

        try {
            const res  = await fetch('api.php?' + params.toString());
            const data = await res.json();
            lastData = data.players ?? [];
            renderRows(lastData);
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-red-500">Error loading data.</td></tr>';
        }
    });

    // ── CSV Export (all rows, ignoring pagination) ───────────────────────────────
    document.getElementById('btn-export').addEventListener('click', () => {
        const table   = document.getElementById('player-table');
        const headers = Array.from(table.querySelectorAll('thead th')).map(th =>
            '"' + th.textContent.trim().replace(/[↕↑↓]/g, '').trim().replace(/"/g, '""') + '"'
        );
        const allTrs = Array.from(table.querySelectorAll('tbody tr[data-name]'));
        const csv    = [headers.join(',')];
        allTrs.forEach(tr => {
            const cells = Array.from(tr.querySelectorAll('td')).map(td =>
                '"' + td.textContent.trim().replace(/"/g, '""') + '"'
            );
            csv.push(cells.join(','));
        });
        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = 'player_cards_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
    });

    // Pre-populate team filter from ?team= URL param
    const urlTeam = new URLSearchParams(location.search).get('team');
    if (urlTeam) {
        teamSearch.value = urlTeam;
        teamHidden.value = urlTeam;
        form.dispatchEvent(new Event('submit'));
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
});
</script>

</main>
</body>
</html>
