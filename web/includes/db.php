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
