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
        echo json_encode(fetch_players($pdo));
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

    $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    /* ---- Build the aggregation query ---- */

    if ($mode === 'per_division') {
        $select_extra = "d.name AS division_name";
        $group_by     = "m.player_name, d.id";
    } else {
        $select_extra = "GROUP_CONCAT(DISTINCT d.name) AS division_names";
        $group_by     = "m.player_name";
    }

    $sql = "
        SELECT
            m.player_name,
            GROUP_CONCAT(DISTINCT m.team) AS teams,
            {$select_extra},
            SUM(CASE WHEN m.card_type = 'Yellow' THEN 1 ELSE 0 END) AS yellow_count,
            SUM(CASE WHEN m.card_type = 'Red'    THEN 1 ELSE 0 END) AS red_count,
            COUNT(DISTINCT d.id) AS division_count
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

    /* For per_division mode we need a separate is_guest lookup */
    $guests = [];
    if ($mode === 'per_division') {
        $g_stmt = $pdo->query("
            SELECT m.player_name
            FROM misconducts m
            JOIN games g     ON m.game_id     = g.id
            JOIN divisions d ON g.division_id = d.id
            GROUP BY m.player_name
            HAVING COUNT(DISTINCT d.id) >= 2
        ");
        foreach ($g_stmt->fetchAll() as $r) {
            $guests[$r['player_name']] = true;
        }
    }

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
            $is_guest  = isset($guests[$row['player_name']]);
        } else {
            $divisions = array_values(array_unique(explode(',', $row['division_names'])));
            sort($divisions);
            $is_guest = (int) $row['division_count'] >= 2;
        }

        $result[] = [
            'name'           => $row['player_name'],
            'teams'          => $teams,
            'divisions'      => $divisions,
            'yellow_count'   => $yellows,
            'red_count'      => $reds,
            'status_class'   => $status['class'],
            'status_label'   => $status['label'],
            'next_threshold' => $next,
            'is_guest'       => $is_guest,
        ];
    }

    return $result;
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

    /* Count players at a suspension threshold (status-red) */
    $players = $pdo->query("
        SELECT player_name,
               SUM(CASE WHEN card_type = 'Yellow' THEN 1 ELSE 0 END) AS yc
        FROM misconducts
        GROUP BY player_name
    ")->fetchAll();

    $suspension_due = 0;
    foreach ($players as $p) {
        if (yellow_status((int) $p['yc'])['class'] === 'status-red') {
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

    $sql = "
        SELECT
            m.team,
            d.name AS division,
            SUM(CASE WHEN m.card_type = 'Yellow' THEN 1 ELSE 0 END) AS yellow_count,
            SUM(CASE WHEN m.card_type = 'Red'    THEN 1 ELSE 0 END) AS red_count,
            COUNT(*)                       AS total_cards,
            COUNT(DISTINCT m.player_name)  AS unique_players
        FROM misconducts m
        JOIN games g      ON m.game_id     = g.id
        JOIN divisions d  ON g.division_id = d.id
        {$where_clause}
        GROUP BY m.team, d.id
        ORDER BY total_cards DESC, m.team ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[] = [
            'team'           => $row['team'],
            'division'       => $row['division'],
            'yellow_count'   => (int) $row['yellow_count'],
            'red_count'      => (int) $row['red_count'],
            'total_cards'    => (int) $row['total_cards'],
            'unique_players' => (int) $row['unique_players'],
        ];
    }

    echo json_encode($result);
}

/* ------------------------------------------------------------------ */
/*  action=discrepancies                                              */
/* ------------------------------------------------------------------ */

function handle_discrepancies(PDO $pdo): void {
    $mode = $_GET['mode'] ?? 'combined';

    /* Pre-filter: only players with 3+ yellows can have a suspension trigger */
    $stmt = $pdo->query("
        SELECT
            m.player_name,
            GROUP_CONCAT(DISTINCT m.team)  AS teams,
            GROUP_CONCAT(DISTINCT d.name)  AS divisions,
            SUM(CASE WHEN m.card_type = 'Yellow' THEN 1 ELSE 0 END) AS yc
        FROM misconducts m
        JOIN games g      ON m.game_id     = g.id
        JOIN divisions d  ON g.division_id = d.id
        GROUP BY m.player_name
        HAVING yc >= 3
        ORDER BY yc DESC
    ");

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $report = get_compliance_report($pdo, $row['player_name'], $mode);

        if ($report['discrepancy_count'] <= 0) {
            continue;
        }

        $teams = array_values(array_unique(explode(',', $row['teams'])));
        sort($teams);
        $divisions = array_values(array_unique(explode(',', $row['divisions'])));
        sort($divisions);

        $result[] = [
            'name'              => $row['player_name'],
            'expected_count'    => $report['expected_count'],
            'served_count'      => max($report['served_count'], $report['printable_count']),
            'discrepancy_count' => $report['discrepancy_count'],
            'teams'             => $teams,
            'divisions'         => $divisions,
        ];
    }

    usort($result, fn($a, $b) => $b['discrepancy_count'] <=> $a['discrepancy_count']);

    echo json_encode($result);
}

/* ------------------------------------------------------------------ */
/*  action=export_csv                                                 */
/* ------------------------------------------------------------------ */

function handle_export_csv(PDO $pdo): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="misconducts.csv"');

    $players = fetch_players($pdo);

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
