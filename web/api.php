<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rules.php';

$action = $_GET['action'] ?? '';

if ($action !== 'export_csv') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

switch ($action) {
    case 'players':
        echo json_encode(fetch_players($pdo), JSON_THROW_ON_ERROR);
        break;
    case 'stats':
        handle_stats($pdo);
        break;
    case 'teams':
        handle_teams($pdo);
        break;
    case 'discrepancies':
        handle_discrepancies($pdo);
        break;
    case 'export_csv':
        handle_export_csv($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}

/* ------------------------------------------------------------------ */
/*  Shared player-fetch logic (used by players + export_csv)          */
/* ------------------------------------------------------------------ */

function fetch_players(PDO $pdo): array {
    $div_type    = $_GET['div_type'] ?? 'all';
    $division_id = isset($_GET['division_id']) && $_GET['division_id'] !== ''
                     ? (int) $_GET['division_id'] : null;
    $team_search = $_GET['team'] ?? '';
    $min_yellows = isset($_GET['min_yellows']) && $_GET['min_yellows'] !== ''
                     ? (int) $_GET['min_yellows'] : null;
    $max_yellows = isset($_GET['max_yellows']) && $_GET['max_yellows'] !== ''
                     ? (int) $_GET['max_yellows'] : null;
    $mode        = $_GET['mode'] ?? 'combined';

    $params = [];
    $where  = [];

    if ($div_type !== 'all') {
        $where[]              = "d.type = :div_type";
        $params[':div_type']  = $div_type;
    }
    if ($division_id !== null) {
        $where[]                 = "d.division_id = :division_id";
        $params[':division_id']  = $division_id;
    }
    if ($team_search !== '') {
        $where[]           = "m.team LIKE :team";
        $params[':team']   = '%' . $team_search . '%';
    }

    $where[] = "m.player_name != 'Bench Penalty'";
    $where_clause = 'WHERE ' . implode(' AND ', $where);

    /* ---- Build the aggregation query ---- */

    if ($mode === 'per_division') {
        $select_extra = "d.name AS division_name";
        $group_by     = "m.player_name, d.id";
    } else {
        $select_extra = "GROUP_CONCAT(DISTINCT d.name) AS division_names";
        $group_by     = "m.player_name";
    }

    // Yellows from games where the player also received a red card are excluded:
    // those are two-yellow ejections and don't count toward accumulation.
    $w_sql = weight_sql();
    $sql = "
        SELECT
            m.player_name,
            GROUP_CONCAT(DISTINCT m.team) AS teams,
            {$select_extra},
            SUM(CASE WHEN m.card_type = 'Yellow'
                      AND NOT EXISTS (
                          SELECT 1 FROM misconducts m2
                          WHERE m2.game_id = m.game_id
                            AND m2.player_name = m.player_name
                            AND m2.card_type = 'Red'
                      ) THEN 1 ELSE 0 END) AS yellow_count,
            SUM(CASE WHEN m.card_type = 'Red' THEN 1 ELSE 0 END) AS red_count,
            SUM($w_sql) AS danger_weight
        FROM misconducts m
        JOIN games g      ON m.game_id     = g.id
        JOIN divisions d  ON g.division_id = d.id
        {$where_clause}
        GROUP BY {$group_by}
    ";

    $having = [];
    if ($min_yellows !== null) {
        $having[]                 = "yellow_count >= :min_yellows";
        $params[':min_yellows']   = $min_yellows;
    }
    if ($max_yellows !== null) {
        $having[]                 = "yellow_count <= :max_yellows";
        $params[':max_yellows']   = $max_yellows;
    }
    if ($having) {
        $sql .= ' HAVING ' . implode(' AND ', $having);
    }

    $sql .= ' ORDER BY yellow_count DESC, red_count DESC, m.player_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    /* ---- Map rows to response objects ---- */

    $result = [];
    foreach ($rows as $row) {
        $yellows = (int) $row['yellow_count'];
        $reds    = (int) $row['red_count'];
        $status  = yellow_status($yellows);
        $next    = yellows_until_next($yellows);

        $teams = array_values(array_unique(explode(',', $row['teams'])));
        sort($teams);

        if ($mode === 'per_division') {
            $divisions = [$row['division_name']];
        } else {
            $divisions = array_values(array_unique(explode(',', $row['division_names'])));
            sort($divisions);
        }

        if ($yellows >= 3 || $reds > 0) {
            $rpt = get_compliance_report($pdo, $row['player_name'], 'combined');
            if ($rpt['expected_count'] === 0) {
                $served_label = '—';
                $served_class = 'text-gray-400';
            } elseif ($rpt['fully_compliant']) {
                $served_label = '✓ Served';
                $served_class = 'text-green-700 font-medium';
            } else {
                $served_label = $rpt['unserved_count'] . ' unserved';
                $served_class = 'text-red-600 font-semibold';
            }
        } else {
            $served_label = '—';
            $served_class = 'text-gray-400';
        }

        $result[] = [
            'name'           => $row['player_name'],
            'teams'          => $teams,
            'divisions'      => $divisions,
            'yellow_count'   => $yellows,
            'red_count'      => $reds,
            'danger_score'   => round((float)($row['danger_weight'] ?? 0), 1),
            'status_class'   => $status['class'],
            'status_label'   => $status['label'],
            'next_threshold' => $next,
            'served_label'   => $served_label,
            'served_class'   => $served_class,
        ];
    }

    // ── Compute filtered stats ────────────────────────────────────────────────
    $stat_yellows = array_sum(array_column($result, 'yellow_count'));
    $stat_reds    = array_sum(array_column($result, 'red_count'));
    $stat_susp    = count(array_filter($result, fn($p) => $p['status_class'] === 'status-red'));

    $teams_set = [];
    $divs_set  = [];
    foreach ($result as $p) {
        foreach ($p['teams']     as $t) $teams_set[$t] = true;
        foreach ($p['divisions'] as $d) $divs_set[$d]  = true;
    }

    // Games count: scoped to division filters only (ignore team/yellow filters)
    $gw = ['g.scraped_at IS NOT NULL'];
    $gp = [];
    if ($div_type !== 'all') {
        $gw[] = 'd.type = :div_type';
        $gp[':div_type'] = $div_type;
    }
    if ($division_id !== null) {
        $gw[] = 'd.division_id = :division_id';
        $gp[':division_id'] = $division_id;
    }
    $gs = $pdo->prepare(
        "SELECT COUNT(*) FROM games g JOIN divisions d ON g.division_id = d.id WHERE " . implode(' AND ', $gw)
    );
    $gs->execute($gp);

    return [
        'players' => $result,
        'stats'   => [
            'total_yellows'  => $stat_yellows,
            'total_reds'     => $stat_reds,
            'suspension_due' => $stat_susp,
            'total_games'    => (int) $gs->fetchColumn(),
            'total_divs'     => count($divs_set),
            'total_teams'    => count($teams_set),
        ],
    ];
}

/* ------------------------------------------------------------------ */
/*  action=stats                                                      */
/* ------------------------------------------------------------------ */

function handle_stats(PDO $pdo): void {
    $totals = $pdo->query("
        SELECT
            SUM(CASE WHEN card_type = 'Yellow' THEN 1 ELSE 0 END) AS total_yellows,
            SUM(CASE WHEN card_type = 'Red'    THEN 1 ELSE 0 END) AS total_reds
        FROM misconducts
    ")->fetch();

    /* Count players at a suspension threshold (yellow accumulation or red card) */
    $players = $pdo->query("
        SELECT m.player_name,
               SUM(CASE WHEN m.card_type = 'Yellow'
                         AND NOT EXISTS (
                             SELECT 1 FROM misconducts m2
                             WHERE m2.game_id = m.game_id
                               AND m2.player_name = m.player_name
                               AND m2.card_type = 'Red'
                         ) THEN 1 ELSE 0 END) AS yc,
               SUM(CASE WHEN m.card_type = 'Red' THEN 1 ELSE 0 END) AS rc
        FROM misconducts m
        GROUP BY m.player_name
    ")->fetchAll();

    $suspension_due = 0;
    foreach ($players as $p) {
        if (yellow_status((int) $p['yc'])['class'] === 'status-red' || (int) $p['rc'] > 0) {
            $suspension_due++;
        }
    }

    $last_scraped = $pdo->query("SELECT MAX(scraped_at) FROM games")->fetchColumn();

    echo json_encode([
        'total_yellows'        => (int) ($totals['total_yellows'] ?? 0),
        'total_reds'           => (int) ($totals['total_reds'] ?? 0),
        'suspension_due_count' => $suspension_due,
        'last_scraped'         => $last_scraped ?: null,
    ]);
}

/* ------------------------------------------------------------------ */
/*  action=teams                                                      */
/* ------------------------------------------------------------------ */

function handle_teams(PDO $pdo): void {
    $div_type    = $_GET['div_type'] ?? 'all';
    $division_id = isset($_GET['division_id']) && $_GET['division_id'] !== ''
                     ? (int) $_GET['division_id'] : null;

    $params = [];
    $where  = [];

    if ($div_type !== 'all') {
        $where[]              = "d.type = :div_type";
        $params[':div_type']  = $div_type;
    }
    if ($division_id !== null) {
        $where[]                 = "d.division_id = :division_id";
        $params[':division_id']  = $division_id;
    }

    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $w = weight_sql();
    $sql = "
        SELECT
            m.team,
            d.name AS division,
            SUM(CASE WHEN m.card_type='Yellow' AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS yellows,
            SUM(CASE WHEN m.card_type='Red'    AND m.player_name != 'Bench Penalty' THEN 1 ELSE 0 END) AS reds,
            SUM(CASE WHEN m.card_type='Yellow' AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_yellows,
            SUM(CASE WHEN m.card_type='Red'    AND m.player_name  = 'Bench Penalty' THEN 1 ELSE 0 END) AS bench_reds,
            COUNT(*)                                                                                     AS total_cards,
            COUNT(DISTINCT CASE WHEN m.player_name != 'Bench Penalty' THEN m.player_name END)           AS unique_players,
            SUM($w)                                                                                      AS discipline_weight,
            (SELECT COUNT(*) FROM games g2
             WHERE (g2.home_team = m.team OR g2.away_team = m.team)
               AND g2.division_id = d.id)                                                               AS games_played
        FROM misconducts m
        JOIN games g      ON m.game_id     = g.id
        JOIN divisions d  ON g.division_id = d.id
        {$where_clause}
        GROUP BY m.team, d.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $gp    = max((int) $row['games_played'], 1);
        $score = round((float) $row['discipline_weight'] / $gp, 2);

        $result[] = [
            'team'             => $row['team'],
            'division'         => $row['division'],
            'yellows'          => (int) $row['yellows'],
            'reds'             => (int) $row['reds'],
            'bench_yellows'    => (int) $row['bench_yellows'],
            'bench_reds'       => (int) $row['bench_reds'],
            'total_cards'      => (int) $row['total_cards'],
            'unique_players'   => (int) $row['unique_players'],
            'games_played'     => (int) $row['games_played'],
            'discipline_score' => $score,
        ];
    }

    usort($result, fn($a, $b) => $b['discipline_score'] <=> $a['discipline_score']);

    echo json_encode($result);
}

/* ------------------------------------------------------------------ */
/*  action=discrepancies                                              */
/* ------------------------------------------------------------------ */

function handle_discrepancies(PDO $pdo): void {
    $mode = $_GET['mode'] ?? 'combined';

    /* Pre-filter: players with 3+ accumulation yellows OR any red card */
    $stmt = $pdo->query("
        SELECT
            m.player_name,
            GROUP_CONCAT(DISTINCT m.team)  AS teams,
            GROUP_CONCAT(DISTINCT d.name)  AS divisions,
            SUM(CASE WHEN m.card_type = 'Yellow'
                      AND NOT EXISTS (
                          SELECT 1 FROM misconducts m2
                          WHERE m2.game_id = m.game_id
                            AND m2.player_name = m.player_name
                            AND m2.card_type = 'Red'
                      ) THEN 1 ELSE 0 END) AS yc,
            SUM(CASE WHEN m.card_type = 'Red' THEN 1 ELSE 0 END) AS rc
        FROM misconducts m
        JOIN games g      ON m.game_id     = g.id
        JOIN divisions d  ON g.division_id = d.id
        GROUP BY m.player_name
        HAVING yc >= 3 OR rc >= 1
        ORDER BY yc DESC
    ");

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $report = get_compliance_report($pdo, $row['player_name'], $mode);

        if ($report['unserved_count'] <= 0) {
            continue;
        }

        $teams = array_values(array_unique(explode(',', $row['teams'])));
        sort($teams);
        $divisions = array_values(array_unique(explode(',', $row['divisions'])));
        sort($divisions);

        $result[] = [
            'name'           => $row['player_name'],
            'expected_count' => $report['expected_count'],
            'served_count'   => $report['served_count'],
            'unserved_count' => $report['unserved_count'],
            'teams'          => $teams,
            'divisions'      => $divisions,
        ];
    }

    usort($result, fn($a, $b) => $b['unserved_count'] <=> $a['unserved_count']);

    echo json_encode($result);
}

/* ------------------------------------------------------------------ */
/*  action=export_csv                                                 */
/* ------------------------------------------------------------------ */

function handle_export_csv(PDO $pdo): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="misconducts.csv"');

    $players = fetch_players($pdo)['players'];

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Player', 'Teams', 'Divisions', 'Yellows', 'Reds', 'Status']);

    foreach ($players as $p) {
        fputcsv($out, [
            $p['name'],
            implode('; ', $p['teams']),
            implode('; ', $p['divisions']),
            $p['yellow_count'],
            $p['red_count'],
            $p['status_label'],
        ]);
    }

    fclose($out);
}
