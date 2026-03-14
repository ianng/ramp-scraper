<?php
define('DB_PATH', __DIR__ . '/../../data/cards.db');

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DB_PATH)) {
            die("Database not found. Run the scraper first: python scrape.py");
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON");
    }
    return $pdo;
}

/**
 * Fetch a single organization by slug, or null.
 */
function get_org(PDO $pdo, ?string $slug): ?array {
    if (!$slug) return null;
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Fetch all organizations for the club switcher dropdown.
 */
function get_all_orgs(PDO $pdo): array {
    return $pdo->query("SELECT * FROM organizations ORDER BY name")->fetchAll();
}

/**
 * Build a gamesheet URL using the division's org base_url and catid from the DB.
 * Uses a static cache to avoid repeated lookups.
 */
function gamesheet_url(int $division_id, int $game_id): string {
    static $cache = [];
    if (!isset($cache[$division_id])) {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            SELECT o.base_url, d.catid
            FROM divisions d
            JOIN organizations o ON d.org_id = o.id
            WHERE d.division_id = ?
        ");
        $stmt->execute([$division_id]);
        $cache[$division_id] = $stmt->fetch() ?: ['base_url' => '', 'catid' => 0];
    }
    $r = $cache[$division_id];
    return $r['base_url'] . '/division/' . $r['catid'] . '/' . $division_id . '/gamesheet/' . $game_id;
}

/**
 * Return SQL fragments for org filtering.
 * Returns ['join' => string, 'where' => string, 'params' => array]
 *
 * Usage:
 *   $org_f = org_filter($current_org);
 *   $sql = "... FROM games g JOIN divisions d ON g.division_id = d.id {$org_f['join']} WHERE 1=1 {$org_f['where']} ..."
 *   $stmt->execute(array_merge($other_params, $org_f['params']));
 */
function org_filter(?array $current_org): array {
    if (!$current_org) {
        return ['join' => '', 'where' => '', 'params' => []];
    }
    return [
        'join'   => '',
        'where'  => ' AND d.org_id = :_org_id',
        'params' => [':_org_id' => $current_org['id']],
    ];
}

/**
 * Build a URL preserving the current club parameter.
 */
function club_url(string $base, array $params = []): string {
    global $club_slug;
    if ($club_slug) {
        $params['club'] = $club_slug;
    }
    if (empty($params)) return $base;
    // If base already has query params, append with &
    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . http_build_query($params);
}

/**
 * Shorthand: just append club param to a simple page URL.
 */
function club_param(): string {
    global $club_slug;
    return $club_slug ? '&club=' . urlencode($club_slug) : '';
}

function club_param_first(): string {
    global $club_slug;
    return $club_slug ? '?club=' . urlencode($club_slug) : '';
}
