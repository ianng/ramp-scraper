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

// ── Canadian Soccer Disciplinary Code: Misconduct Severity Weights ──────────
//
// Yellow cards are Category E offences under the CSDC. Their sub-weights
// reflect escalating risk to player/official safety and league culture:
//
//   Dissent by word or action              → 2.5  (challenges authority)
//   Unsporting Behavior                    → 2.0  (broad behaviour violation)
//   Persistent infringement                → 1.5  (tactical/pattern fouling)
//   Procedural offences (delay, distance)  → 1.0  (game-management only)
//
// Direct red cards follow CSDC categories A–D:
//
//   Category A  Violent Conduct            → 9.0  (endangers others)
//   Spitting (at person)                   → 7.5  (morally reprehensible)
//   Category D  Abuse of an Official       → 7.0  (undermines authority)
//   Category B  Serious Foul Play          → 6.0  (dangerous challenge)
//   Category C  DOGSO                      → 4.5  (tactical/professional foul)
//   Two-Yellow Ejection                    → 3.0  (accumulated cautions)
//
// Bench penalties carry a 1.5× multiplier: the bench being carded signals
// a team-culture failure, not an individual lapse.

/**
 * Weight for a yellow card (Category E) reason.
 */
function yellow_weight(string $reason): float {
    if (str_contains($reason, 'Dissent'))                  return 2.5;
    if (str_contains($reason, 'Unsporting'))               return 2.0;
    if (str_contains($reason, 'Persistent infringement'))  return 1.5;
    return 1.0; // Delaying restart, required distance, entry without permission
}

/**
 * Weight for a red card reason aligned to CSDC categories A–D.
 */
function red_weight(string $reason): float {
    if (str_contains($reason, 'Category A') || str_contains($reason, 'Violent Conduct')) return 9.0;
    if (str_contains($reason, 'Spitting'))   return 7.5;
    if (str_contains($reason, 'Category D') || str_contains($reason, 'Foul and Abusive')
      || str_contains($reason, 'Abuse of an Official'))   return 7.0;
    if (str_contains($reason, 'Serious Foul Play'))       return 6.0;
    if (str_contains($reason, 'Denying Obvious') || str_contains($reason, 'DOGSO')) return 4.5;
    if (str_contains($reason, 'Second Caution'))          return 3.0;
    return 4.0;
}

/**
 * Combined card weight. Pass reason and card_type ('Yellow'|'Red').
 */
function card_weight(string $reason, string $card_type): float {
    return $card_type === 'Yellow' ? yellow_weight($reason) : red_weight($reason);
}

/**
 * Discipline score colour threshold (per game, using CSDC-weighted scores).
 *
 * Calibrated against league data: league average ≈ 0.9–1.0 pts/game for
 * teams with cards (behavioral yellows only). Violent conduct reds push
 * teams to 3–4+/game.
 *
 * Green < 1.0  | Amber 1.0–2.5  | Red > 2.5
 */
function discipline_color(float $score): string {
    if ($score > 2.5) return 'red';
    if ($score >= 1.0) return 'amber';
    return 'green';
}

function discipline_label(float $score): string {
    return match(discipline_color($score)) {
        'red'   => 'High Risk',
        'amber' => 'Elevated',
        default => 'Clean',
    };
}

/**
 * Generates a SQL expression (scalar float) for the weighted score of one
 * misconduct row. Applies the 1.5× bench multiplier inline.
 *
 * @param string $reason_col  SQL expression for the reason column
 * @param string $card_col    SQL expression for the card_type column
 * @param string $player_col  SQL expression for the player_name column
 */
function weight_sql(
    string $reason_col = 'm.reason',
    string $card_col   = 'm.card_type',
    string $player_col = 'm.player_name'
): string {
    return "
        (CASE
            WHEN $card_col = 'Yellow' THEN
                CASE
                    WHEN $reason_col LIKE '%Dissent%'                          THEN 2.5
                    WHEN $reason_col LIKE '%Unsporting%'                       THEN 2.0
                    WHEN $reason_col LIKE '%Persistent infringement%'          THEN 1.5
                    ELSE 1.0
                END
            WHEN $card_col = 'Red' THEN
                CASE
                    WHEN $reason_col LIKE '%Category A%'
                      OR $reason_col LIKE '%Violent Conduct%'                  THEN 9.0
                    WHEN $reason_col LIKE '%Spitting%'                         THEN 7.5
                    WHEN $reason_col LIKE '%Category D%'
                      OR $reason_col LIKE '%Foul and Abusive%'
                      OR $reason_col LIKE '%Abuse of an Official%'             THEN 7.0
                    WHEN $reason_col LIKE '%Serious Foul Play%'                THEN 6.0
                    WHEN $reason_col LIKE '%Denying Obvious%'                  THEN 4.5
                    WHEN $reason_col LIKE '%Second Caution%'                   THEN 3.0
                    ELSE 4.0
                END
            ELSE 0.0
        END * CASE WHEN $player_col = 'Bench Penalty' THEN 1.5 ELSE 1.0 END)
    ";
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
