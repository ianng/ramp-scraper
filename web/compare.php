<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

$pdo      = get_pdo();
$all_orgs = get_all_orgs($pdo);
$w_sql    = weight_sql();

$club_colors = [
    ['border' => 'border-indigo-400', 'text' => 'text-indigo-600', 'badge' => 'bg-indigo-100 text-indigo-700', 'bg_light' => 'bg-indigo-50', 'chart' => '#6366f1', 'chart_light' => 'rgba(99,102,241,0.15)'],
    ['border' => 'border-teal-400', 'text' => 'text-teal-600', 'badge' => 'bg-teal-100 text-teal-700', 'bg_light' => 'bg-teal-50', 'chart' => '#0d9488', 'chart_light' => 'rgba(13,148,136,0.15)'],
];

$type_badge = [
    'mens'   => 'bg-blue-100 text-blue-700',
    'womens' => 'bg-pink-100 text-pink-700',
    'coed'   => 'bg-purple-100 text-purple-700',
];
$type_label = ['mens' => 'Mens', 'womens' => 'Womens', 'coed' => 'Coed'];

$page_title = 'Compare Clubs';
include __DIR__ . '/includes/header.php';

if (count($all_orgs) < 2): ?>
<div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-6 text-center">
    <h2 class="text-xl font-bold mb-2">Not enough clubs to compare</h2>
    <p>This page requires at least two organizations in the database. Currently there <?= count($all_orgs) === 1 ? 'is only 1 club' : 'are no clubs' ?> registered.</p>
</div>
</main></body></html>
<?php exit; endif;

// ── Map org id → color index ────────────────────────────────────────────────
$org_color = [];
foreach ($all_orgs as $i => $org) {
    $org_color[$org['id']] = $club_colors[$i % count($club_colors)];
}

// ═══════════════════════════════════════════════════════════════════════════════
// Section 1: Club Overview
// ═══════════════════════════════════════════════════════════════════════════════
$overview_sql = "
    SELECT o.id, o.name, o.slug,
           COUNT(DISTINCT g.id) AS games,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           SUM(CASE WHEN m.player_name='Bench Penalty' THEN 1 ELSE 0 END) AS bench,
           SUM({$w_sql}) AS total_weight
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    LEFT JOIN misconducts m ON m.game_id = g.id
    WHERE g.scraped_at IS NOT NULL
    GROUP BY o.id ORDER BY o.name
";
$overview_rows = $pdo->query($overview_sql)->fetchAll();

// Index by org id for quick-facts later
$overview_by_id = [];
foreach ($overview_rows as $row) {
    $overview_by_id[$row['id']] = $row;
}

// ── Section 2: Division type breakdown ──────────────────────────────────────
$type_sql = "
    SELECT o.id AS org_id, o.name AS org_name, d.type,
           COUNT(DISTINCT g.id) AS games,
           COUNT(m.id) AS cards,
           SUM({$w_sql}) AS total_weight
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    LEFT JOIN misconducts m ON m.game_id = g.id
    WHERE g.scraped_at IS NOT NULL
    GROUP BY o.id, d.type
    ORDER BY o.name, d.type
";
$type_rows = $pdo->query($type_sql)->fetchAll();

// Build: $type_data[type][org_id] = [games, cards, cpg]
$type_data = [];
foreach ($type_rows as $r) {
    $cpg = $r['games'] > 0 ? round($r['cards'] / $r['games'], 2) : 0;
    $type_data[$r['type']][$r['org_id']] = [
        'games' => (int)$r['games'],
        'cards' => (int)$r['cards'],
        'cpg'   => $cpg,
    ];
}

// ── Section 3: Severity profile ─────────────────────────────────────────────
$sev_sql = "
    SELECT o.id AS org_id, o.name AS org_name, m.reason, m.card_type, m.player_name, COUNT(*) AS cnt
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    JOIN misconducts m ON m.game_id = g.id
    GROUP BY o.id, m.reason, m.card_type,
             CASE WHEN m.player_name='Bench Penalty' THEN 'Bench' ELSE 'Player' END
";
$sev_rows = $pdo->query($sev_sql)->fetchAll();

$sev_categories = [
    'Procedural Yellow'   => '#fde68a',
    'Behavioural Yellow'  => '#f59e0b',
    'Dissent'             => '#d97706',
    'Two-Yellow Ejection' => '#fb923c',
    'Hard Red'            => '#ef4444',
    'Bench Penalty'       => '#ea580c',
];
$sev_cat_keys = array_keys($sev_categories);

// Build: $sev_data[org_id][category] = count
$sev_data = [];
foreach ($all_orgs as $org) {
    $sev_data[$org['id']] = array_fill_keys($sev_cat_keys, 0);
}

foreach ($sev_rows as $row) {
    $oid      = $row['org_id'];
    $r        = $row['reason'] ?? '';
    $ct       = $row['card_type'];
    $is_bench = $row['player_name'] === 'Bench Penalty';
    $cnt      = (int)$row['cnt'];

    if ($is_bench) {
        $key = 'Bench Penalty';
    } elseif ($ct === 'Yellow') {
        $yw = yellow_weight($r);
        if (str_contains($r, 'Dissent')) {
            $key = 'Dissent';
        } elseif (str_contains($r, 'Unsporting') || str_contains($r, 'Persistent')) {
            $key = 'Behavioural Yellow';
        } else {
            $key = 'Procedural Yellow';
        }
    } else {
        if (str_contains($r, 'Second Caution')) {
            $key = 'Two-Yellow Ejection';
        } else {
            $key = 'Hard Red';
        }
    }

    if (isset($sev_data[$oid][$key])) {
        $sev_data[$oid][$key] += $cnt;
    }
}

// ── Section 4: Division ranking table ───────────────────────────────────────
$rank_sql = "
    SELECT d.division_id, d.name, d.type, d.level,
           o.slug AS org_slug, o.name AS org_name, o.id AS org_id,
           COUNT(DISTINCT g.id) AS games,
           COUNT(m.id) AS total_cards,
           COALESCE(SUM({$w_sql}), 0) AS total_weight
    FROM divisions d
    JOIN organizations o ON d.org_id = o.id
    LEFT JOIN games g ON g.division_id = d.id AND g.scraped_at IS NOT NULL
    LEFT JOIN misconducts m ON m.game_id = g.id
    GROUP BY d.id
    HAVING games > 0
    ORDER BY total_weight / MAX(games, 1) DESC
";
$rank_rows = $pdo->query($rank_sql)->fetchAll();

// Track hottest division per org for quick facts
$hottest_div = [];
foreach ($rank_rows as $row) {
    $oid   = $row['org_id'];
    $score = $row['games'] > 0 ? $row['total_weight'] / $row['games'] : 0;
    if (!isset($hottest_div[$oid]) || $score > $hottest_div[$oid]['score']) {
        $hottest_div[$oid] = ['name' => $row['name'], 'division_id' => $row['division_id'], 'score' => $score, 'org_slug' => $row['org_slug']];
    }
}

// ── Section 5: Monthly trend ────────────────────────────────────────────────
$trend_sql = "
    SELECT o.id AS org_id, o.name AS org_name,
           strftime('%Y-%m', g.game_date) AS month,
           COUNT(DISTINCT g.id) AS games,
           COUNT(m.id) AS cards
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    LEFT JOIN misconducts m ON m.game_id = g.id
    WHERE g.scraped_at IS NOT NULL
    GROUP BY o.id, month
    ORDER BY month, o.name
";
$trend_rows = $pdo->query($trend_sql)->fetchAll();

// Build union of months + per-org cpg
$all_months   = [];
$trend_by_org = [];
foreach ($trend_rows as $r) {
    $all_months[$r['month']] = true;
    $cpg = $r['games'] > 0 ? round($r['cards'] / $r['games'], 2) : null;
    $trend_by_org[$r['org_id']][$r['month']] = $cpg;
}
ksort($all_months);
$month_labels = array_keys($all_months);

// Format month labels: '2025-09' -> 'Sep 2025'
$month_labels_nice = array_map(function ($m) {
    $ts = strtotime($m . '-01');
    return $ts ? date('M Y', $ts) : $m;
}, $month_labels);

// ── Section 6: Division Head-to-Head ────────────────────────────────────────
// Per-division detail for the H2H comparator (reuses $ranking_rows from Section 4)
// Build a JSON-friendly structure with reason breakdowns per division
$h2h_sql = "
    SELECT d.division_id, d.name AS div_name, d.type, d.level,
           o.slug AS org_slug, o.name AS org_name,
           COUNT(DISTINCT g.id) AS games,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           SUM(CASE WHEN m.player_name='Bench Penalty' THEN 1 ELSE 0 END) AS bench,
           COUNT(m.id) AS total_cards,
           COALESCE(SUM($w_sql), 0) AS total_weight
    FROM divisions d
    JOIN organizations o ON d.org_id = o.id
    LEFT JOIN games g ON g.division_id = d.id AND g.scraped_at IS NOT NULL
    LEFT JOIN misconducts m ON m.game_id = g.id
    GROUP BY d.id
    HAVING games > 0
    ORDER BY o.name, d.type, d.level
";
$h2h_rows = $pdo->query($h2h_sql)->fetchAll();

// Also get reason breakdown per division for H2H detail
$h2h_reason_sql = "
    SELECT d.division_id, m.reason, m.card_type, m.player_name, COUNT(*) AS cnt
    FROM divisions d
    JOIN games g ON g.division_id = d.id AND g.scraped_at IS NOT NULL
    JOIN misconducts m ON m.game_id = g.id
    GROUP BY d.division_id, m.reason, m.card_type,
             CASE WHEN m.player_name='Bench Penalty' THEN 'Bench' ELSE 'Player' END
";
$h2h_reason_rows = $pdo->query($h2h_reason_sql)->fetchAll();

// Build per-division severity breakdown
$h2h_severity = []; // division_id => [proc_y, beh_y, dissent, two_y, hard_r, bench]
foreach ($h2h_reason_rows as $r) {
    $did = $r['division_id'];
    if (!isset($h2h_severity[$did])) {
        $h2h_severity[$did] = ['proc_y' => 0, 'beh_y' => 0, 'dissent' => 0, 'two_y' => 0, 'hard_r' => 0, 'bench' => 0];
    }
    $cnt = (int)$r['cnt'];
    $is_bench = $r['player_name'] === 'Bench Penalty';
    if ($is_bench) {
        $h2h_severity[$did]['bench'] += $cnt;
    } elseif ($r['card_type'] === 'Yellow') {
        $w = yellow_weight($r['reason'] ?? '');
        if ($w >= 2.5) $h2h_severity[$did]['dissent'] += $cnt;
        elseif ($w >= 1.5) $h2h_severity[$did]['beh_y'] += $cnt;
        else $h2h_severity[$did]['proc_y'] += $cnt;
    } else {
        $w = red_weight($r['reason'] ?? '');
        if ($w <= 3.0) $h2h_severity[$did]['two_y'] += $cnt;
        else $h2h_severity[$did]['hard_r'] += $cnt;
    }
}

// Build JSON structure for JS
$h2h_json = [];
foreach ($h2h_rows as $r) {
    $did = (int)$r['division_id'];
    $games = max((int)$r['games'], 1);
    $h2h_json[$did] = [
        'name' => $r['div_name'],
        'org' => $r['org_name'],
        'org_slug' => $r['org_slug'],
        'type' => $r['type'],
        'level' => (int)$r['level'],
        'games' => (int)$r['games'],
        'yellows' => (int)$r['yellows'],
        'reds' => (int)$r['reds'],
        'bench' => (int)$r['bench'],
        'total_cards' => (int)$r['total_cards'],
        'cpg' => round((int)$r['total_cards'] / $games, 2),
        'score' => round((float)$r['total_weight'] / $games, 2),
        'severity' => $h2h_severity[$did] ?? ['proc_y' => 0, 'beh_y' => 0, 'dissent' => 0, 'two_y' => 0, 'hard_r' => 0, 'bench' => 0],
    ];
}

// Top 5 carded players per division
$h2h_top_players_sql = "
    SELECT d.division_id, m.player_name, COUNT(*) AS cards
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY d.division_id, m.player_name
    ORDER BY d.division_id, cards DESC
";
$h2h_top_players_rows = $pdo->query($h2h_top_players_sql)->fetchAll();
$h2h_top_players = [];
foreach ($h2h_top_players_rows as $r) {
    $did = (int)$r['division_id'];
    if (!isset($h2h_top_players[$did])) $h2h_top_players[$did] = [];
    if (count($h2h_top_players[$did]) < 5) {
        $h2h_top_players[$did][] = ['name' => $r['player_name'], 'cards' => (int)$r['cards']];
    }
}

// Top 3 misconduct reasons per division
$h2h_top_reasons_sql = "
    SELECT d.division_id, m.reason, COUNT(*) AS cnt
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    GROUP BY d.division_id, m.reason
    ORDER BY d.division_id, cnt DESC
";
$h2h_top_reasons_rows = $pdo->query($h2h_top_reasons_sql)->fetchAll();
$h2h_top_reasons = [];
foreach ($h2h_top_reasons_rows as $r) {
    $did = (int)$r['division_id'];
    if (!isset($h2h_top_reasons[$did])) $h2h_top_reasons[$did] = [];
    if (count($h2h_top_reasons[$did]) < 3) {
        $h2h_top_reasons[$did][] = ['reason' => $r['reason'], 'cnt' => (int)$r['cnt']];
    }
}

// Attach top_players and top_reasons to h2h_json
foreach ($h2h_json as $did => &$entry) {
    $entry['top_players'] = $h2h_top_players[$did] ?? [];
    $entry['top_reasons'] = $h2h_top_reasons[$did] ?? [];
}
unset($entry);

// Build grouped option list for dropdowns: [org_name => [division_id => label]]
$h2h_options = [];
foreach ($h2h_rows as $r) {
    $label = $r['div_name'] . ' (' . (int)$r['games'] . ' games)';
    $h2h_options[$r['org_name']][$r['division_id']] = $label;
}

// ── Section 6b: Team Head-to-Head ────────────────────────────────────────────
$team_h2h_sql = "
    SELECT m.team, o.name AS org_name, o.slug AS org_slug,
           COUNT(DISTINCT g.id) AS games,
           SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           SUM(CASE WHEN m.player_name='Bench Penalty' THEN 1 ELSE 0 END) AS bench,
           COUNT(*) AS total_cards,
           SUM($w_sql) AS total_weight,
           COUNT(DISTINCT CASE WHEN m.player_name != 'Bench Penalty' THEN m.player_name END) AS unique_players
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    GROUP BY m.team, o.id
    ORDER BY o.name, m.team
";
$team_h2h_rows = $pdo->query($team_h2h_sql)->fetchAll();

// Per-team severity
$team_sev_sql = "
    SELECT m.team, o.id AS org_id, m.reason, m.card_type, m.player_name, COUNT(*) AS cnt
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    GROUP BY m.team, o.id, m.reason, m.card_type,
             CASE WHEN m.player_name='Bench Penalty' THEN 'Bench' ELSE 'Player' END
";
$team_sev_rows = $pdo->query($team_sev_sql)->fetchAll();

$team_severity = [];
foreach ($team_sev_rows as $r) {
    $key = $r['team'] . '|' . $r['org_id'];
    if (!isset($team_severity[$key])) {
        $team_severity[$key] = ['proc_y' => 0, 'beh_y' => 0, 'dissent' => 0, 'two_y' => 0, 'hard_r' => 0, 'bench' => 0];
    }
    $cnt = (int)$r['cnt'];
    $is_bench = $r['player_name'] === 'Bench Penalty';
    if ($is_bench) { $team_severity[$key]['bench'] += $cnt; }
    elseif ($r['card_type'] === 'Yellow') {
        $w = yellow_weight($r['reason'] ?? '');
        if ($w >= 2.5) $team_severity[$key]['dissent'] += $cnt;
        elseif ($w >= 1.5) $team_severity[$key]['beh_y'] += $cnt;
        else $team_severity[$key]['proc_y'] += $cnt;
    } else {
        $w = red_weight($r['reason'] ?? '');
        if ($w <= 3.0) $team_severity[$key]['two_y'] += $cnt;
        else $team_severity[$key]['hard_r'] += $cnt;
    }
}

$team_h2h_json = [];
$team_h2h_options = [];
foreach ($team_h2h_rows as $r) {
    $games = max((int)$r['games'], 1);
    $key = $r['team'] . '|' . $r['org_slug'];
    $sev_key = $r['team'] . '|' . array_search($r['org_name'], array_column($all_orgs, 'name'));
    // Find org id
    foreach ($all_orgs as $o) { if ($o['slug'] === $r['org_slug']) { $sev_key = $r['team'] . '|' . $o['id']; break; } }
    $team_h2h_json[$key] = [
        'name' => $r['team'],
        'org' => $r['org_name'],
        'games' => (int)$r['games'],
        'yellows' => (int)$r['yellows'],
        'reds' => (int)$r['reds'],
        'bench' => (int)$r['bench'],
        'total_cards' => (int)$r['total_cards'],
        'cpg' => round((int)$r['total_cards'] / $games, 2),
        'score' => round((float)$r['total_weight'] / $games, 2),
        'unique_players' => (int)$r['unique_players'],
        'severity' => $team_severity[$sev_key] ?? ['proc_y' => 0, 'beh_y' => 0, 'dissent' => 0, 'two_y' => 0, 'hard_r' => 0, 'bench' => 0],
    ];
    $team_h2h_options[$r['org_name']][$key] = $r['team'] . ' (' . (int)$r['total_cards'] . ' cards)';
}

// Top 5 carded players per team (keyed by team|org_slug)
$team_top_players_sql = "
    SELECT m.team, o.slug AS org_slug, m.player_name, COUNT(*) AS cards
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.team, o.id, m.player_name
    ORDER BY m.team, o.id, cards DESC
";
$team_top_players_rows = $pdo->query($team_top_players_sql)->fetchAll();
$team_top_players = [];
foreach ($team_top_players_rows as $r) {
    $key = $r['team'] . '|' . $r['org_slug'];
    if (!isset($team_top_players[$key])) $team_top_players[$key] = [];
    if (count($team_top_players[$key]) < 5) {
        $team_top_players[$key][] = ['name' => $r['player_name'], 'cards' => (int)$r['cards']];
    }
}

// Divisions per team
$team_divs_sql = "
    SELECT DISTINCT m.team, o.slug AS org_slug, d.name AS div_name
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    GROUP BY m.team, o.id, d.id
";
$team_divs_rows = $pdo->query($team_divs_sql)->fetchAll();
$team_divs = [];
foreach ($team_divs_rows as $r) {
    $key = $r['team'] . '|' . $r['org_slug'];
    if (!isset($team_divs[$key])) $team_divs[$key] = [];
    $team_divs[$key][] = $r['div_name'];
}

// Attach to team_h2h_json
foreach ($team_h2h_json as $key => &$entry) {
    $entry['top_players'] = $team_top_players[$key] ?? [];
    $entry['divisions'] = $team_divs[$key] ?? [];
}
unset($entry);

// ── Section 6c: Player Head-to-Head ─────────────────────────────────────────
$player_h2h_sql = "
    SELECT m.player_name, o.name AS org_name, o.slug AS org_slug,
           GROUP_CONCAT(DISTINCT m.team) AS teams,
           SUM(CASE WHEN m.card_type='Yellow'
                     AND NOT EXISTS (
                         SELECT 1 FROM misconducts m2
                         WHERE m2.game_id = m.game_id
                           AND m2.player_name = m.player_name
                           AND m2.card_type = 'Red'
                     ) THEN 1 ELSE 0 END) AS yellows,
           SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) AS reds,
           COUNT(*) AS total_cards,
           SUM($w_sql) AS danger_score
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.player_name, o.id
    HAVING total_cards >= 2
    ORDER BY o.name, m.player_name
";
$player_h2h_rows = $pdo->query($player_h2h_sql)->fetchAll();

$player_h2h_json = [];
$player_h2h_options = [];
foreach ($player_h2h_rows as $r) {
    $key = $r['player_name'] . '|' . $r['org_slug'];
    $player_h2h_json[$key] = [
        'name' => $r['player_name'],
        'org' => $r['org_name'],
        'teams' => $r['teams'],
        'yellows' => (int)$r['yellows'],
        'reds' => (int)$r['reds'],
        'total_cards' => (int)$r['total_cards'],
        'danger' => round((float)$r['danger_score'], 1),
    ];
    $player_h2h_options[$r['org_name']][$key] = $r['player_name'] . ' (' . (int)$r['total_cards'] . ' cards)';
}

// Top 3 misconduct reasons per player
$player_reasons_sql = "
    SELECT m.player_name, o.slug AS org_slug, m.reason, COUNT(*) AS cnt
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.player_name, o.id, m.reason
    ORDER BY m.player_name, o.id, cnt DESC
";
$player_reasons_rows = $pdo->query($player_reasons_sql)->fetchAll();
$player_reasons = [];
foreach ($player_reasons_rows as $r) {
    $key = $r['player_name'] . '|' . $r['org_slug'];
    if (!isset($player_reasons[$key])) $player_reasons[$key] = [];
    if (count($player_reasons[$key]) < 3) {
        $player_reasons[$key][] = ['reason' => $r['reason'], 'cnt' => (int)$r['cnt']];
    }
}

// Divisions per player
$player_divs_sql = "
    SELECT DISTINCT m.player_name, o.slug AS org_slug, d.name AS div_name
    FROM misconducts m
    JOIN games g ON m.game_id = g.id
    JOIN divisions d ON g.division_id = d.id
    JOIN organizations o ON d.org_id = o.id
    WHERE m.player_name != 'Bench Penalty'
    GROUP BY m.player_name, o.id, d.id
";
$player_divs_rows = $pdo->query($player_divs_sql)->fetchAll();
$player_divs = [];
foreach ($player_divs_rows as $r) {
    $key = $r['player_name'] . '|' . $r['org_slug'];
    if (!isset($player_divs[$key])) $player_divs[$key] = [];
    $player_divs[$key][] = $r['div_name'];
}

// Attach to player_h2h_json
foreach ($player_h2h_json as $key => &$entry) {
    $entry['reasons'] = $player_reasons[$key] ?? [];
    $entry['divisions'] = $player_divs[$key] ?? [];
}
unset($entry);

// ── Section 7: Quick facts — most common reason + hottest team per org ──────
$reason_sql = "
    SELECT o.id AS org_id, m.reason, COUNT(*) AS cnt
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    JOIN misconducts m ON m.game_id = g.id
    GROUP BY o.id, m.reason
    ORDER BY o.id, cnt DESC
";
$reason_rows = $pdo->query($reason_sql)->fetchAll();
$top_reason = [];
$reason_totals = [];
foreach ($reason_rows as $r) {
    $oid = $r['org_id'];
    $reason_totals[$oid] = ($reason_totals[$oid] ?? 0) + (int)$r['cnt'];
    if (!isset($top_reason[$oid])) {
        $top_reason[$oid] = ['reason' => $r['reason'], 'cnt' => (int)$r['cnt']];
    }
}

$team_sql = "
    SELECT o.id AS org_id, m.team,
           COUNT(DISTINCT g.id) AS games,
           COUNT(m.id) AS cards
    FROM organizations o
    JOIN divisions d ON d.org_id = o.id
    JOIN games g ON g.division_id = d.id
    JOIN misconducts m ON m.game_id = g.id
    GROUP BY o.id, m.team
    ORDER BY o.id
";
$team_rows = $pdo->query($team_sql)->fetchAll();
$hottest_team = [];
foreach ($team_rows as $r) {
    $oid = $r['org_id'];
    $cpg = $r['games'] > 0 ? $r['cards'] / $r['games'] : 0;
    if (!isset($hottest_team[$oid]) || $cpg > $hottest_team[$oid]['cpg']) {
        $hottest_team[$oid] = ['team' => $r['team'], 'cpg' => $cpg];
    }
}

?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 1: Club Overview -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">Club Overview</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($overview_rows as $idx => $row):
            $c = $org_color[$row['id']] ?? $club_colors[0];
            $games = max((int)$row['games'], 1);
            $cpg   = round(((int)$row['yellows'] + (int)$row['reds']) / $games, 2);
            $spg   = round((float)$row['total_weight'] / $games, 2);
            $bpg   = round((int)$row['bench'] / $games, 2);
        ?>
        <div class="bg-white rounded-lg shadow border-l-4 <?= $c['border'] ?> p-5">
            <h3 class="text-lg font-bold <?= $c['text'] ?> mb-3"><?= htmlspecialchars($row['name']) ?></h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">Games</span><span class="font-semibold"><?= (int)$row['games'] ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Yellows</span><span class="font-semibold text-amber-600"><?= (int)$row['yellows'] ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Reds</span><span class="font-semibold text-red-600"><?= (int)$row['reds'] ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Cards / Game</span><span class="font-semibold"><?= $cpg ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">CSDC Score / Game</span><span class="font-semibold"><?= $spg ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Bench Penalties</span><span class="font-semibold"><?= (int)$row['bench'] ?></span></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 2: Division Type Comparison -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">Cards per Game by Division Type</h2>
    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <div style="position:relative; height:300px">
            <canvas id="chart-type-compare"></canvas>
        </div>
    </div>
    <!-- Summary table -->
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-4 py-2.5 text-left">Type</th>
                    <?php foreach ($overview_rows as $row): ?>
                    <th class="px-4 py-2.5 text-center"><?= htmlspecialchars($row['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach (['mens', 'womens', 'coed'] as $type):
                    if (!isset($type_data[$type])) continue;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium">
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $type_badge[$type] ?? 'bg-gray-100 text-gray-600' ?>">
                            <?= $type_label[$type] ?>
                        </span>
                    </td>
                    <?php foreach ($overview_rows as $row):
                        $td = $type_data[$type][$row['id']] ?? null;
                    ?>
                    <td class="px-4 py-2 text-center">
                        <?php if ($td): ?>
                            <span class="font-semibold"><?= $td['cpg'] ?></span>
                            <span class="text-xs text-gray-400">(<?= $td['cards'] ?> in <?= $td['games'] ?>g)</span>
                        <?php else: ?>
                            <span class="text-gray-300">-</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 3: Severity Profile -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">Severity Profile</h2>

    <!-- Doughnuts side by side -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <?php foreach ($overview_rows as $idx => $row):
            $c   = $org_color[$row['id']] ?? $club_colors[0];
            $oid = $row['id'];
        ?>
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold <?= $c['text'] ?> mb-2"><?= htmlspecialchars($row['name']) ?></h3>
            <div style="position:relative; height:240px">
                <canvas id="chart-sev-donut-<?= $oid ?>"></canvas>
            </div>
            <div class="mt-2 space-y-0.5">
                <?php foreach ($sev_categories as $cat => $color):
                    $cnt = $sev_data[$oid][$cat] ?? 0;
                    if ($cnt === 0) continue;
                ?>
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                    <span class="inline-block w-2.5 h-2.5 rounded-sm shrink-0" style="background:<?= $color ?>"></span>
                    <?= htmlspecialchars($cat) ?>
                    <span class="text-gray-400 ml-auto"><?= $cnt ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Proportion stacked bar -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Severity Proportion by Club</h3>
        <div class="flex flex-wrap gap-3 text-xs text-gray-500 mb-3">
            <?php foreach ($sev_categories as $cat => $color): ?>
            <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:<?= $color ?>"></span><?= $cat ?></span>
            <?php endforeach; ?>
        </div>
        <div style="position:relative; height:<?= max(100, count($overview_rows) * 50) ?>px">
            <canvas id="chart-sev-stacked"></canvas>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 4: Division Ranking Table -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">All Divisions Ranked by Discipline Score</h2>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-primary text-white">
                <tr>
                    <th class="px-3 py-2.5 text-center">#</th>
                    <th class="px-3 py-2.5 text-left">Division</th>
                    <th class="px-3 py-2.5 text-left">Club</th>
                    <th class="px-3 py-2.5 text-center">Type</th>
                    <th class="px-3 py-2.5 text-center">Games</th>
                    <th class="px-3 py-2.5 text-center">Cards</th>
                    <th class="px-3 py-2.5 text-center">Cards/Game</th>
                    <th class="px-3 py-2.5 text-center">Score</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rank_rows as $i => $row):
                    $score = $row['games'] > 0 ? round($row['total_weight'] / $row['games'], 2) : 0;
                    $cpg   = $row['games'] > 0 ? round($row['total_cards'] / $row['games'], 2) : 0;
                    $sc    = discipline_color($score);
                    $score_cls = match($sc) {
                        'red'   => 'text-red-600 font-bold',
                        'amber' => 'text-amber-600 font-semibold',
                        default => 'text-green-600 font-semibold',
                    };
                    $c = $org_color[$row['org_id']] ?? $club_colors[0];
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-center text-gray-400"><?= $i + 1 ?></td>
                    <td class="px-3 py-2 font-medium">
                        <a href="division.php?id=<?= (int)$row['division_id'] ?>&club=<?= urlencode($row['org_slug']) ?>"
                           class="text-primary hover:underline"><?= htmlspecialchars($row['name']) ?></a>
                    </td>
                    <td class="px-3 py-2">
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $c['badge'] ?>">
                            <?= htmlspecialchars($row['org_name']) ?>
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $type_badge[$row['type']] ?? 'bg-gray-100 text-gray-600' ?>">
                            <?= $type_label[$row['type']] ?? ucfirst($row['type']) ?>
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center text-gray-500"><?= (int)$row['games'] ?></td>
                    <td class="px-3 py-2 text-center font-semibold"><?= (int)$row['total_cards'] ?></td>
                    <td class="px-3 py-2 text-center"><?= $cpg ?></td>
                    <td class="px-3 py-2 text-center <?= $score_cls ?>"><?= number_format($score, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 5: Monthly Trend -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">Monthly Trend &mdash; Cards per Game</h2>
    <div class="bg-white rounded-lg shadow p-4">
        <div style="position:relative; height:300px">
            <canvas id="chart-monthly-trend"></canvas>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 6: Division Head-to-Head -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-1">Division Head-to-Head</h2>
    <p class="text-sm text-gray-500 mb-4">Pick any two divisions — across clubs or within the same club — to compare side-by-side.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Division A</label>
            <input type="text" id="h2h-a-search" placeholder="Search divisions…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="h2h-a" value="">
            <div id="h2h-a-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Division B</label>
            <input type="text" id="h2h-b-search" placeholder="Search divisions…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="h2h-b" value="">
            <div id="h2h-b-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
    </div>

    <!-- H2H result panel (hidden until both selected) -->
    <div id="h2h-panel" class="hidden">
        <!-- Stats comparison -->
        <div class="grid grid-cols-3 gap-0 bg-white rounded-lg shadow overflow-hidden mb-4">
            <div id="h2h-col-a" class="text-center p-4 border-r border-gray-100"></div>
            <div class="text-center p-4 text-xs text-gray-400 font-semibold uppercase tracking-wide flex flex-col justify-center gap-3">
                <div>Games</div><div>Yellows</div><div>Reds</div><div>Bench</div><div>Cards/Game</div><div>Score/Game</div>
            </div>
            <div id="h2h-col-b" class="text-center p-4 border-l border-gray-100"></div>
        </div>

        <!-- Top players + reasons -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div id="h2h-extra-a" class="bg-white rounded-lg shadow p-4"></div>
            <div id="h2h-extra-b" class="bg-white rounded-lg shadow p-4"></div>
        </div>

        <!-- Severity comparison bar -->
        <div class="bg-white rounded-lg shadow p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-3">Severity Composition</h3>
            <div style="position:relative; height:120px">
                <canvas id="chart-h2h-severity"></canvas>
            </div>
            <div class="flex flex-wrap gap-3 text-xs text-gray-500 mt-2 justify-center">
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#fde68a"></span>Procedural</span>
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#f59e0b"></span>Behavioural</span>
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#d97706"></span>Dissent</span>
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#fb923c"></span>Two-Yellow</span>
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#ef4444"></span>Direct Red</span>
                <span><span class="inline-block w-3 h-3 rounded-sm mr-1" style="background:#ea580c"></span>Bench</span>
            </div>
        </div>
    </div>

    <div id="h2h-empty" class="bg-gray-50 rounded-lg p-8 text-center text-gray-400">
        Select two divisions above to see their head-to-head comparison.
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 6b: Team Head-to-Head -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-1">Team Head-to-Head</h2>
    <p class="text-sm text-gray-500 mb-4">Compare any two teams — across clubs or within the same league.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Team A</label>
            <input type="text" id="th2h-a-search" placeholder="Search teams…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="th2h-a" value="">
            <div id="th2h-a-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Team B</label>
            <input type="text" id="th2h-b-search" placeholder="Search teams…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="th2h-b" value="">
            <div id="th2h-b-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
    </div>

    <div id="th2h-panel" class="hidden">
        <div class="grid grid-cols-3 gap-0 bg-white rounded-lg shadow overflow-hidden mb-4">
            <div id="th2h-col-a" class="text-center p-4 border-r border-gray-100"></div>
            <div class="text-center p-4 text-xs text-gray-400 font-semibold uppercase tracking-wide flex flex-col justify-center gap-3">
                <div>Games w/ Cards</div><div>Players Carded</div><div>Yellows</div><div>Reds</div><div>Bench</div><div>Cards/Game</div><div>Score/Game</div>
            </div>
            <div id="th2h-col-b" class="text-center p-4 border-l border-gray-100"></div>
        </div>
        <!-- Top players per team -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div id="th2h-extra-a" class="bg-white rounded-lg shadow p-4"></div>
            <div id="th2h-extra-b" class="bg-white rounded-lg shadow p-4"></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-3">Severity Composition</h3>
            <div style="position:relative; height:120px">
                <canvas id="chart-th2h-severity"></canvas>
            </div>
        </div>
    </div>
    <div id="th2h-empty" class="bg-gray-50 rounded-lg p-8 text-center text-gray-400">
        Select two teams above to compare.
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 6c: Player Head-to-Head -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-1">Player Head-to-Head</h2>
    <p class="text-sm text-gray-500 mb-4">Compare discipline records of any two players. Only players with 2+ cards shown.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Player A</label>
            <input type="text" id="ph2h-a-search" placeholder="Search players…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="ph2h-a" value="">
            <div id="ph2h-a-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
        <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">Player B</label>
            <input type="text" id="ph2h-b-search" placeholder="Search players…" autocomplete="off"
                   class="w-full border rounded px-3 py-2 text-sm">
            <input type="hidden" id="ph2h-b" value="">
            <div id="ph2h-b-dropdown" class="hidden absolute z-20 left-0 right-0 bg-white border rounded shadow-lg mt-1 max-h-60 overflow-y-auto text-sm"></div>
        </div>
    </div>

    <div id="ph2h-panel" class="hidden">
        <div class="grid grid-cols-3 gap-0 bg-white rounded-lg shadow overflow-hidden mb-4">
            <div id="ph2h-col-a" class="text-center p-4 border-r border-gray-100"></div>
            <div class="text-center p-4 text-xs text-gray-400 font-semibold uppercase tracking-wide flex flex-col justify-center gap-3">
                <div>Team(s)</div><div>Divisions</div><div>Yellows</div><div>Reds</div><div>Total Cards</div><div>Danger Score</div>
            </div>
            <div id="ph2h-col-b" class="text-center p-4 border-l border-gray-100"></div>
        </div>
        <!-- Visual bar comparison + reasons -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div id="ph2h-extra-a" class="bg-white rounded-lg shadow p-4"></div>
            <div id="ph2h-extra-b" class="bg-white rounded-lg shadow p-4"></div>
        </div>
        <!-- Side-by-side bars -->
        <div id="ph2h-bars" class="bg-white rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-3">Card Comparison</h3>
            <div style="position:relative; height:120px">
                <canvas id="chart-ph2h-bars"></canvas>
            </div>
        </div>
    </div>
    <div id="ph2h-empty" class="bg-gray-50 rounded-lg p-8 text-center text-gray-400">
        Select two players above to compare.
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Section 7: Quick Facts -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<section class="mb-8">
    <h2 class="text-xl font-bold text-primary mb-4">Quick Facts</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($overview_rows as $idx => $row):
            $oid   = $row['id'];
            $c     = $org_color[$oid] ?? $club_colors[0];
            $games = max((int)$row['games'], 1);
        ?>
        <div class="bg-white rounded-lg shadow border-l-4 <?= $c['border'] ?> p-5">
            <h3 class="text-lg font-bold <?= $c['text'] ?> mb-3"><?= htmlspecialchars($row['name']) ?></h3>
            <div class="space-y-3 text-sm">
                <!-- Most common reason -->
                <div>
                    <span class="text-xs text-gray-400 uppercase tracking-wide">Most Common Misconduct</span>
                    <?php if (isset($top_reason[$oid])): ?>
                    <div class="font-semibold mt-0.5">
                        <?= htmlspecialchars($top_reason[$oid]['reason']) ?>
                        <span class="text-gray-400 font-normal">
                            (<?= $top_reason[$oid]['cnt'] ?> &mdash; <?= $reason_totals[$oid] > 0 ? round($top_reason[$oid]['cnt'] / $reason_totals[$oid] * 100, 1) : 0 ?>%)
                        </span>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-300">-</div>
                    <?php endif; ?>
                </div>

                <!-- Red card rate -->
                <div>
                    <span class="text-xs text-gray-400 uppercase tracking-wide">Red Card Rate</span>
                    <div class="font-semibold text-red-600 mt-0.5"><?= number_format((int)$row['reds'] / $games, 2) ?> <span class="text-gray-400 font-normal">per game</span></div>
                </div>

                <!-- Bench penalty rate -->
                <div>
                    <span class="text-xs text-gray-400 uppercase tracking-wide">Bench Penalty Rate</span>
                    <div class="font-semibold mt-0.5"><?= number_format((int)$row['bench'] / $games, 2) ?> <span class="text-gray-400 font-normal">per game</span></div>
                </div>

                <!-- Hottest division -->
                <div>
                    <span class="text-xs text-gray-400 uppercase tracking-wide">Hottest Division</span>
                    <?php if (isset($hottest_div[$oid])): ?>
                    <div class="font-semibold mt-0.5">
                        <a href="division.php?id=<?= (int)$hottest_div[$oid]['division_id'] ?>&club=<?= urlencode($hottest_div[$oid]['org_slug']) ?>"
                           class="text-primary hover:underline"><?= htmlspecialchars($hottest_div[$oid]['name']) ?></a>
                        <span class="text-gray-400 font-normal">(<?= number_format($hottest_div[$oid]['score'], 2) ?> pts/game)</span>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-300">-</div>
                    <?php endif; ?>
                </div>

                <!-- Hottest team -->
                <div>
                    <span class="text-xs text-gray-400 uppercase tracking-wide">Hottest Team</span>
                    <?php if (isset($hottest_team[$oid])): ?>
                    <div class="font-semibold mt-0.5">
                        <?= htmlspecialchars($hottest_team[$oid]['team']) ?>
                        <span class="text-gray-400 font-normal">(<?= number_format($hottest_team[$oid]['cpg'], 2) ?> cards/game)</span>
                    </div>
                    <?php else: ?>
                    <div class="text-gray-300">-</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Chart.js -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const defaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
};

// ── Club metadata ───────────────────────────────────────────────────────────
const orgs = <?= json_encode(array_values(array_map(function ($row) use ($org_color) {
    $c = $org_color[$row['id']] ?? ['chart' => '#6366f1', 'chart_light' => 'rgba(99,102,241,0.15)'];
    return ['id' => $row['id'], 'name' => $row['name'], 'chart' => $c['chart'], 'chart_light' => $c['chart_light']];
}, $overview_rows))) ?>;

// ── Section 2: Division Type grouped bar ────────────────────────────────────
(function() {
    const types = ['Mens', 'Womens', 'Coed'];
    const typeKeys = ['mens', 'womens', 'coed'];
    const typeData = <?= json_encode($type_data) ?>;

    const datasets = orgs.map(org => ({
        label: org.name,
        data: typeKeys.map(t => (typeData[t] && typeData[t][org.id]) ? typeData[t][org.id].cpg : 0),
        backgroundColor: org.chart,
        borderRadius: 3,
    }));

    new Chart(document.getElementById('chart-type-compare'), {
        type: 'bar',
        data: { labels: types, datasets },
        options: {
            ...defaults,
            plugins: {
                legend: { display: true, position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} cards/game`
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, title: { display: true, text: 'Cards per Game', font: { size: 11 } } },
            },
        }
    });
})();

// ── Section 3: Severity doughnuts ───────────────────────────────────────────
(function() {
    const sevData  = <?= json_encode($sev_data) ?>;
    const catKeys  = <?= json_encode($sev_cat_keys) ?>;
    const catColors = <?= json_encode(array_values($sev_categories)) ?>;

    orgs.forEach(org => {
        const el = document.getElementById('chart-sev-donut-' + org.id);
        if (!el) return;
        const counts = catKeys.map(k => sevData[org.id] ? (sevData[org.id][k] || 0) : 0);
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: catKeys,
                datasets: [{
                    data: counts,
                    backgroundColor: catColors,
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
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                                return ` ${ctx.parsed} cards (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    });

    // Proportion stacked bar
    const orgNames = orgs.map(o => o.name);
    const stackedDatasets = catKeys.map((cat, ci) => ({
        label: cat,
        data: orgs.map(org => {
            const orgTotal = catKeys.reduce((sum, k) => sum + ((sevData[org.id] && sevData[org.id][k]) || 0), 0);
            const val = (sevData[org.id] && sevData[org.id][cat]) || 0;
            return orgTotal > 0 ? Math.round(val / orgTotal * 1000) / 10 : 0;
        }),
        backgroundColor: catColors[ci],
        barThickness: 28,
    }));

    new Chart(document.getElementById('chart-sev-stacked'), {
        type: 'bar',
        data: { labels: orgNames, datasets: stackedDatasets },
        options: {
            ...defaults,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.x.toFixed(1)}%`
                    }
                }
            },
            scales: {
                x: { stacked: true, beginAtZero: true, max: 100, grid: { color: '#f3f4f6' }, ticks: { callback: v => v + '%', font: { size: 11 } } },
                y: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
            },
        }
    });
})();

// ── Section 5: Monthly trend line chart ─────────────────────────────────────
(function() {
    const months = <?= json_encode($month_labels) ?>;
    const monthLabels = <?= json_encode($month_labels_nice) ?>;
    const trendByOrg = <?= json_encode($trend_by_org) ?>;

    const datasets = orgs.map(org => ({
        label: org.name,
        data: months.map(m => (trendByOrg[org.id] && trendByOrg[org.id][m] !== undefined) ? trendByOrg[org.id][m] : null),
        borderColor: org.chart,
        backgroundColor: org.chart_light,
        tension: 0.3,
        fill: false,
        pointRadius: 4,
        pointHoverRadius: 6,
        spanGaps: false,
    }));

    new Chart(document.getElementById('chart-monthly-trend'), {
        type: 'line',
        data: { labels: monthLabels, datasets },
        options: {
            ...defaults,
            plugins: {
                legend: { display: true, position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y !== null ? ctx.parsed.y.toFixed(2) : '-'} cards/game`
                    }
                }
            },
            scales: {
                x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, title: { display: true, text: 'Cards / Game', font: { size: 11 } } },
            },
        }
    });
})();

// ── Utility functions ─────────────────────────────────────────────────────────
function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function winClass(a, b, lowerIsBetter = true) {
    if (a === b) return '';
    if (lowerIsBetter) return a < b ? 'text-green-600' : 'text-red-600';
    return a > b ? 'text-green-600' : 'text-red-600';
}

// ── Reusable Searchable Combobox ─────────────────────────────────────────────
function createSearchableSelect(searchId, hiddenId, dropdownId, options, onSelect) {
    const searchEl = document.getElementById(searchId);
    const hiddenEl = document.getElementById(hiddenId);
    const dropdownEl = document.getElementById(dropdownId);

    function renderDropdown(query) {
        const q = (query || '').toLowerCase();
        // Group options
        const groups = {};
        options.forEach(o => {
            if (q && !o.label.toLowerCase().includes(q)) return;
            if (!groups[o.group]) groups[o.group] = [];
            groups[o.group].push(o);
        });

        let html = '<div class="px-3 py-1.5 text-gray-400 hover:bg-gray-100 cursor-pointer border-b" data-value="">&times; Clear selection</div>';
        const groupKeys = Object.keys(groups);
        if (groupKeys.length === 0) {
            html += '<div class="px-3 py-2 text-gray-400 italic">No matches</div>';
        } else {
            groupKeys.forEach(g => {
                html += `<div class="px-3 py-1 text-xs font-bold text-gray-400 uppercase tracking-wide bg-gray-50 sticky top-0">${esc(g)}</div>`;
                groups[g].forEach(o => {
                    html += `<div class="px-3 py-1.5 hover:bg-blue-50 cursor-pointer" data-value="${esc(o.value)}" data-label="${esc(o.label)}">${esc(o.label)}</div>`;
                });
            });
        }
        dropdownEl.innerHTML = html;
        dropdownEl.classList.remove('hidden');
    }

    searchEl.addEventListener('focus', () => renderDropdown(searchEl.value));
    searchEl.addEventListener('input', () => renderDropdown(searchEl.value));

    dropdownEl.addEventListener('mousedown', (e) => {
        e.preventDefault(); // prevent blur
        const item = e.target.closest('[data-value]');
        if (!item) return;
        const val = item.dataset.value;
        const label = item.dataset.label || '';
        hiddenEl.value = val;
        searchEl.value = label;
        dropdownEl.classList.add('hidden');
        if (onSelect) onSelect(val);
    });

    searchEl.addEventListener('blur', () => {
        setTimeout(() => dropdownEl.classList.add('hidden'), 150);
    });
}

// ── Build option arrays from PHP data ────────────────────────────────────────
const h2hOptions = <?= json_encode(array_values(call_user_func(function() use ($h2h_options) {
    $out = [];
    foreach ($h2h_options as $org_name => $divs) {
        foreach ($divs as $did => $label) {
            $out[] = ['value' => (string)$did, 'label' => $label, 'group' => $org_name];
        }
    }
    return $out;
}))) ?>;

const teamH2HOptions = <?= json_encode(array_values(call_user_func(function() use ($team_h2h_options) {
    $out = [];
    foreach ($team_h2h_options as $org_name => $teams) {
        foreach ($teams as $key => $label) {
            $out[] = ['value' => $key, 'label' => $label, 'group' => $org_name];
        }
    }
    return $out;
}))) ?>;

const playerH2HOptions = <?= json_encode(array_values(call_user_func(function() use ($player_h2h_options) {
    $out = [];
    foreach ($player_h2h_options as $org_name => $players) {
        foreach ($players as $key => $label) {
            $out[] = ['value' => $key, 'label' => $label, 'group' => $org_name];
        }
    }
    return $out;
}))) ?>;

// Initialize all 6 searchable selects
createSearchableSelect('h2h-a-search', 'h2h-a', 'h2h-a-dropdown', h2hOptions, () => updateH2H());
createSearchableSelect('h2h-b-search', 'h2h-b', 'h2h-b-dropdown', h2hOptions, () => updateH2H());
createSearchableSelect('th2h-a-search', 'th2h-a', 'th2h-a-dropdown', teamH2HOptions, () => updateTeamH2H());
createSearchableSelect('th2h-b-search', 'th2h-b', 'th2h-b-dropdown', teamH2HOptions, () => updateTeamH2H());
createSearchableSelect('ph2h-a-search', 'ph2h-a', 'ph2h-a-dropdown', playerH2HOptions, () => updatePlayerH2H());
createSearchableSelect('ph2h-b-search', 'ph2h-b', 'ph2h-b-dropdown', playerH2HOptions, () => updatePlayerH2H());

// ── Section 6: Division Head-to-Head ─────────────────────────────────────────
const h2hData = <?= json_encode($h2h_json) ?>;
let h2hChart = null;

function renderTopList(title, items, nameKey, countKey, countLabel) {
    if (!items || items.length === 0) return `<div class="text-xs text-gray-300 italic">No data</div>`;
    let html = `<h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">${esc(title)}</h4><div class="space-y-1">`;
    items.forEach((item, i) => {
        html += `<div class="flex justify-between text-sm"><span class="text-gray-700">${i+1}. ${esc(item[nameKey])}</span><span class="font-semibold text-gray-500">${item[countKey]} ${countLabel}</span></div>`;
    });
    return html + '</div>';
}

function updateH2H() {
    const aId = document.getElementById('h2h-a').value;
    const bId = document.getElementById('h2h-b').value;
    const panel = document.getElementById('h2h-panel');
    const empty = document.getElementById('h2h-empty');

    if (!aId || !bId) {
        panel.classList.add('hidden');
        empty.classList.remove('hidden');
        return;
    }

    const a = h2hData[aId], b = h2hData[bId];
    if (!a || !b) return;

    panel.classList.remove('hidden');
    empty.classList.add('hidden');

    // Build stat columns with winner highlighting
    function statCol(d, other, elId) {
        document.getElementById(elId).innerHTML = `
            <div class="font-bold text-sm text-gray-800 mb-1">${esc(d.name)}</div>
            <div class="text-xs text-gray-400 mb-3">${esc(d.org)}</div>
            <div class="space-y-3">
                <div class="text-lg font-bold text-blue-600">${d.games}</div>
                <div class="text-lg font-bold text-amber-600">${d.yellows}</div>
                <div class="text-lg font-bold ${winClass(d.reds, other.reds, true)  || 'text-red-600'}">${d.reds}</div>
                <div class="text-lg font-bold ${winClass(d.bench, other.bench, true) || 'text-orange-600'}">${d.bench}</div>
                <div class="text-lg font-bold ${winClass(d.cpg, other.cpg, true) || 'text-gray-800'}">${d.cpg.toFixed(2)}</div>
                <div class="text-lg font-bold ${winClass(d.score, other.score, true) || 'text-gray-800'}">${d.score.toFixed(2)}</div>
            </div>
        `;
    }
    statCol(a, b, 'h2h-col-a');
    statCol(b, a, 'h2h-col-b');

    // Extra panels: top players + top reasons
    function extraCol(d, elId) {
        let html = renderTopList('Top 5 Carded Players', d.top_players, 'name', 'cards', 'cards');
        html += '<div class="mt-3"></div>';
        html += renderTopList('Top 3 Misconduct Reasons', d.top_reasons, 'reason', 'cnt', '');
        document.getElementById(elId).innerHTML = html;
    }
    extraCol(a, 'h2h-extra-a');
    extraCol(b, 'h2h-extra-b');

    // Severity stacked bar chart
    const sevKeys = ['proc_y', 'beh_y', 'dissent', 'two_y', 'hard_r', 'bench'];
    const sevLabels = ['Procedural', 'Behavioural', 'Dissent', 'Two-Yellow', 'Direct Red', 'Bench'];
    const sevColors = ['#fde68a', '#f59e0b', '#d97706', '#fb923c', '#ef4444', '#ea580c'];

    if (h2hChart) h2hChart.destroy();
    const el = document.getElementById('chart-h2h-severity');
    h2hChart = new Chart(el, {
        type: 'bar',
        data: {
            labels: [a.name, b.name],
            datasets: sevKeys.map((k, i) => ({
                label: sevLabels[i],
                data: [a.severity[k] || 0, b.severity[k] || 0],
                backgroundColor: sevColors[i],
                barThickness: 30,
            }))
        },
        options: {
            ...defaults,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.x} cards` } }
            },
            scales: {
                x: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' } },
                y: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
            }
        }
    });
}

// ── Section 6b: Team Head-to-Head ────────────────────────────────────────────
const teamH2HData = <?= json_encode($team_h2h_json) ?>;
let teamH2HChart = null;

function updateTeamH2H() {
    const aKey = document.getElementById('th2h-a').value;
    const bKey = document.getElementById('th2h-b').value;
    const panel = document.getElementById('th2h-panel');
    const empty = document.getElementById('th2h-empty');

    if (!aKey || !bKey) { panel.classList.add('hidden'); empty.classList.remove('hidden'); return; }
    const a = teamH2HData[aKey], b = teamH2HData[bKey];
    if (!a || !b) return;

    panel.classList.remove('hidden');
    empty.classList.add('hidden');

    function teamCol(d, other, elId) {
        document.getElementById(elId).innerHTML = `
            <div class="font-bold text-sm text-gray-800 mb-1">${esc(d.name)}</div>
            <div class="text-xs text-gray-400 mb-3">${esc(d.org)}</div>
            <div class="space-y-3">
                <div class="text-lg font-bold text-blue-600">${d.games}</div>
                <div class="text-lg font-bold text-gray-700">${d.unique_players}</div>
                <div class="text-lg font-bold text-amber-600">${d.yellows}</div>
                <div class="text-lg font-bold ${winClass(d.reds, other.reds, true) || 'text-red-600'}">${d.reds}</div>
                <div class="text-lg font-bold ${winClass(d.bench, other.bench, true) || 'text-orange-600'}">${d.bench}</div>
                <div class="text-lg font-bold ${winClass(d.cpg, other.cpg, true) || 'text-gray-800'}">${d.cpg.toFixed(2)}</div>
                <div class="text-lg font-bold ${winClass(d.score, other.score, true) || 'text-gray-800'}">${d.score.toFixed(2)}</div>
            </div>`;
    }
    teamCol(a, b, 'th2h-col-a');
    teamCol(b, a, 'th2h-col-b');

    // Extra panels: top players + divisions
    function teamExtraCol(d, elId) {
        let html = '';
        if (d.divisions && d.divisions.length > 0) {
            html += `<h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Divisions</h4>`;
            html += `<div class="text-sm text-gray-600 mb-3">${d.divisions.map(v => esc(v)).join(', ')}</div>`;
        }
        html += renderTopList('Top 5 Carded Players', d.top_players, 'name', 'cards', 'cards');
        document.getElementById(elId).innerHTML = html;
    }
    teamExtraCol(a, 'th2h-extra-a');
    teamExtraCol(b, 'th2h-extra-b');

    const sevKeys = ['proc_y', 'beh_y', 'dissent', 'two_y', 'hard_r', 'bench'];
    const sevLabels = ['Procedural', 'Behavioural', 'Dissent', 'Two-Yellow', 'Direct Red', 'Bench'];
    const sevColors = ['#fde68a', '#f59e0b', '#d97706', '#fb923c', '#ef4444', '#ea580c'];

    if (teamH2HChart) teamH2HChart.destroy();
    teamH2HChart = new Chart(document.getElementById('chart-th2h-severity'), {
        type: 'bar',
        data: {
            labels: [a.name, b.name],
            datasets: sevKeys.map((k, i) => ({
                label: sevLabels[i], data: [a.severity[k] || 0, b.severity[k] || 0],
                backgroundColor: sevColors[i], barThickness: 30,
            }))
        },
        options: {
            ...defaults, indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.x} cards` } } },
            scales: { x: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' } }, y: { stacked: true, grid: { display: false } } }
        }
    });
}

// ── Section 6c: Player Head-to-Head ──────────────────────────────────────────
const playerH2HData = <?= json_encode($player_h2h_json) ?>;
let playerH2HChart = null;

function updatePlayerH2H() {
    const aKey = document.getElementById('ph2h-a').value;
    const bKey = document.getElementById('ph2h-b').value;
    const panel = document.getElementById('ph2h-panel');
    const empty = document.getElementById('ph2h-empty');

    if (!aKey || !bKey) { panel.classList.add('hidden'); empty.classList.remove('hidden'); return; }
    const a = playerH2HData[aKey], b = playerH2HData[bKey];
    if (!a || !b) return;

    panel.classList.remove('hidden');
    empty.classList.add('hidden');

    function playerCol(d, other, elId) {
        const dc = d.danger > 7.0 ? 'text-red-600' : d.danger >= 3.0 ? 'text-amber-600' : 'text-green-600';
        const divsHtml = (d.divisions && d.divisions.length > 0) ? d.divisions.map(v => esc(v)).join(', ') : '-';
        document.getElementById(elId).innerHTML = `
            <div class="font-bold text-sm text-gray-800 mb-1">${esc(d.name)}</div>
            <div class="text-xs text-gray-400 mb-3">${esc(d.org)}</div>
            <div class="space-y-3">
                <div class="text-sm text-gray-600">${esc(d.teams)}</div>
                <div class="text-sm text-gray-600">${divsHtml}</div>
                <div class="text-lg font-bold ${winClass(d.yellows, other.yellows, true) || 'text-amber-600'}">${d.yellows}</div>
                <div class="text-lg font-bold ${winClass(d.reds, other.reds, true) || 'text-red-600'}">${d.reds}</div>
                <div class="text-lg font-bold ${winClass(d.total_cards, other.total_cards, true) || 'text-gray-800'}">${d.total_cards}</div>
                <div class="text-lg font-bold ${winClass(d.danger, other.danger, true) || dc}">${d.danger.toFixed(1)}</div>
            </div>`;
    }
    playerCol(a, b, 'ph2h-col-a');
    playerCol(b, a, 'ph2h-col-b');

    // Extra panels: reasons
    function playerExtraCol(d, elId) {
        let html = renderTopList('Top Misconduct Reasons', d.reasons, 'reason', 'cnt', '');
        document.getElementById(elId).innerHTML = html;
    }
    playerExtraCol(a, 'ph2h-extra-a');
    playerExtraCol(b, 'ph2h-extra-b');

    // Side-by-side bar chart for yellows/reds
    if (playerH2HChart) playerH2HChart.destroy();
    playerH2HChart = new Chart(document.getElementById('chart-ph2h-bars'), {
        type: 'bar',
        data: {
            labels: ['Yellows', 'Reds'],
            datasets: [
                { label: a.name, data: [a.yellows, a.reds], backgroundColor: '#6366f1', borderRadius: 3, barPercentage: 0.6 },
                { label: b.name, data: [b.yellows, b.reds], backgroundColor: '#0d9488', borderRadius: 3, barPercentage: 0.6 },
            ]
        },
        options: {
            ...defaults,
            indexAxis: 'y',
            plugins: {
                legend: { display: true, position: 'top', labels: { font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.x}` } }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: '#f3f4f6' } },
                y: { grid: { display: false } }
            }
        }
    });
}
</script>

</main>
</body>
</html>
