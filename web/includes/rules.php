<?php
/**
 * Yellow card suspension logic — Indoor Soccer League
 *
 * Rule 7.1: 3rd yellow  → 1 match suspension
 * Rule 7.2: 5th yellow  → 1 match suspension
 * Rule 7.3: 7th+ yellow → 1 match per additional caution (7th, 8th, 9th, …)
 */

/**
 * Given an ordered list of yellow card dates/games, return the list of
 * suspension triggers.
 *
 * @param  array $yellows  Ordered array of assoc arrays with at least:
 *                         ['game_date', 'game_id', 'division_name']
 * @return array           Each element: ['trigger_yellow_count', 'trigger_game', 'rule']
 */
function calculate_expected_suspensions(array $yellows): array {
    $suspensions = [];
    $count = 0;
    foreach ($yellows as $yellow) {
        $count++;
        $rule = null;
        if ($count === 3) {
            $rule = '7.1';
        } elseif ($count === 5) {
            $rule = '7.2';
        } elseif ($count >= 7) {
            $rule = '7.3';
        }
        if ($rule !== null) {
            $suspensions[] = [
                'trigger_yellow_count' => $count,
                'trigger_game'         => $yellow,
                'rule'                 => $rule,
            ];
        }
    }
    return $suspensions;
}

/**
 * Return the status colour class and label for a given yellow count.
 *
 * Note: suspensions are enforced via a monthly league report, not automatically
 * after the next game. "Triggered" means the threshold was reached; whether the
 * suspension has been served yet depends on when the league ran its report.
 */
function yellow_status(int $yellows): array {
    if ($yellows >= 7) {
        return ['class' => 'status-red',    'label' => 'Suspension Triggered (Rule 7.3)'];
    }
    if ($yellows === 6) {
        return ['class' => 'status-amber',  'label' => 'Warning — 1 from Rule 7.3'];
    }
    if ($yellows >= 5) {
        return ['class' => 'status-red',    'label' => 'Suspension Triggered (Rule 7.2)'];
    }
    if ($yellows === 4) {
        return ['class' => 'status-amber',  'label' => 'Warning — 1 from Rule 7.2'];
    }
    if ($yellows >= 3) {
        return ['class' => 'status-red',    'label' => 'Suspension Triggered (Rule 7.1)'];
    }
    if ($yellows === 2) {
        return ['class' => 'status-amber',  'label' => 'Warning — 1 from Rule 7.1'];
    }
    return ['class' => 'status-green', 'label' => 'Clean'];
}

/**
 * How many more yellows until the next suspension threshold?
 */
function yellows_until_next(int $yellows): ?int {
    if ($yellows < 2)  return 2 - $yellows;   // heading toward 3
    if ($yellows < 3)  return 3 - $yellows;
    if ($yellows < 5)  return 5 - $yellows;
    if ($yellows < 7)  return 7 - $yellows;
    return 1; // every subsequent yellow triggers a suspension
}

/**
 * Fetch all yellow cards for a player, in chronological order.
 *
 * @param PDO    $pdo
 * @param string $player_name  Exact match
 * @param string $mode         'combined' | 'per_division'
 * @param int|null $division_id  Only relevant when mode='per_division'
 */
function get_player_yellows(PDO $pdo, string $player_name, string $mode = 'combined', ?int $division_id = null): array {
    // Exclude yellows from games where this player also received a red card:
    // those are two-yellow ejections, which carry their own automatic suspension
    // and do NOT count toward the yellow accumulation totals.
    $red_game_subq = "
        NOT EXISTS (
            SELECT 1 FROM misconducts m2
            WHERE m2.game_id = m.game_id
              AND m2.player_name = m.player_name
              AND m2.card_type = 'Red'
        )
    ";

    if ($mode === 'per_division' && $division_id !== null) {
        $sql = "
            SELECT m.*, g.game_date, g.game_id, g.game_number,
                   d.name AS division_name, d.division_id, g.home_team, g.away_team
            FROM misconducts m
            JOIN games g ON m.game_id = g.id
            JOIN divisions d ON g.division_id = d.id
            WHERE m.player_name = :name
              AND m.card_type = 'Yellow'
              AND d.division_id = :div_id
              AND $red_game_subq
            ORDER BY g.game_date ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name' => $player_name, ':div_id' => $division_id]);
    } else {
        $sql = "
            SELECT m.*, g.game_date, g.game_id, g.game_number,
                   d.name AS division_name, d.division_id, g.home_team, g.away_team
            FROM misconducts m
            JOIN games g ON m.game_id = g.id
            JOIN divisions d ON g.division_id = d.id
            WHERE m.player_name = :name
              AND m.card_type = 'Yellow'
              AND $red_game_subq
            ORDER BY g.game_date ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name' => $player_name]);
    }
    return $stmt->fetchAll();
}

/**
 * Fetch all red cards for a player.
 */
function get_player_reds(PDO $pdo, string $player_name): array {
    $stmt = $pdo->prepare("
        SELECT m.*, g.game_date, g.game_id, g.game_number,
               d.name AS division_name, d.division_id
        FROM misconducts m
        JOIN games g ON m.game_id = g.id
        JOIN divisions d ON g.division_id = d.id
        WHERE m.player_name = :name
          AND m.card_type = 'Red'
        ORDER BY g.game_date ASC
    ");
    $stmt->execute([':name' => $player_name]);
    return $stmt->fetchAll();
}

/**
 * Each red card (including ejection via two yellows) carries an automatic
 * 1-match suspension, separate from yellow card accumulation.
 *
 * @param  array $reds  As returned by get_player_reds()
 * @return array        Each element: ['trigger_game', 'rule' => 'Red Card']
 */
function calculate_red_card_suspensions(array $reds): array {
    $suspensions = [];
    foreach ($reds as $red) {
        $suspensions[] = [
            'trigger_game' => $red,
            'rule'         => 'Red Card',
        ];
    }
    return $suspensions;
}

/**
 * Fetch all suspensions served for a player.
 */
function get_player_suspensions_served(PDO $pdo, string $player_name): array {
    $stmt = $pdo->prepare("
        SELECT ss.*, g.game_date, g.game_id, g.game_number,
               d.name AS division_name, d.division_id
        FROM suspensions_served ss
        JOIN games g ON ss.game_id = g.id
        JOIN divisions d ON g.division_id = d.id
        WHERE ss.player_name = :name
        ORDER BY g.game_date ASC
    ");
    $stmt->execute([':name' => $player_name]);
    return $stmt->fetchAll();
}

/**
 * Fetch printable suspension records for a player.
 */
function get_player_printable_suspensions(PDO $pdo, string $player_name): array {
    $stmt = $pdo->prepare("
        SELECT ps.*, g.game_date, g.game_id, g.game_number,
               d.name AS division_name, d.division_id
        FROM printable_suspensions ps
        JOIN games g ON ps.game_id = g.id
        JOIN divisions d ON g.division_id = d.id
        WHERE ps.player_name = :name
        ORDER BY g.game_date ASC
    ");
    $stmt->execute([':name' => $player_name]);
    return $stmt->fetchAll();
}

/**
 * Build a compliance report for a player:
 * - How many suspensions should have been triggered
 * - How many are actually recorded as served
 * - List discrepancies
 */
function get_compliance_report(PDO $pdo, string $player_name, string $mode = 'combined'): array {
    $yellows = get_player_yellows($pdo, $player_name, $mode);
    $reds    = get_player_reds($pdo, $player_name);

    $expected_yellow = calculate_expected_suspensions($yellows);
    $expected_red    = calculate_red_card_suspensions($reds);

    $served = get_player_suspensions_served($pdo, $player_name);

    $served_count   = count($served);
    $expected_count = count($expected_yellow) + count($expected_red);

    // Unserved = suspensions triggered but not yet recorded as served.
    // Because the league enforces suspensions via a monthly report (not after
    // each individual game), unserved suspensions may simply be pending the
    // next report rather than definitively missed.  We expose both the raw
    // count and a flag so the UI can present this appropriately.
    $unserved = max(0, $expected_count - $served_count);

    return [
        'expected_suspensions'     => $expected_yellow,
        'expected_red_suspensions' => $expected_red,
        'expected_yellow_count'    => count($expected_yellow),
        'expected_red_count'       => count($expected_red),
        'expected_count'           => $expected_count,
        'served_count'             => $served_count,
        'unserved_count'           => $unserved,
        'fully_compliant'          => $unserved === 0,
    ];
}
