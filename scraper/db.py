import sqlite3
import os
from config import DB_PATH, DIVISIONS


def get_db_path() -> str:
    base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, DB_PATH)


def get_connection() -> sqlite3.Connection:
    path = get_db_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA journal_mode = WAL")
    return conn


def init_db() -> None:
    conn = get_connection()
    cur = conn.cursor()

    cur.executescript("""
        CREATE TABLE IF NOT EXISTS divisions (
            id INTEGER PRIMARY KEY,
            division_id INTEGER UNIQUE,
            name TEXT,
            type TEXT,
            level INTEGER
        );

        CREATE TABLE IF NOT EXISTS games (
            id INTEGER PRIMARY KEY,
            game_id INTEGER UNIQUE,
            division_id INTEGER,
            game_number TEXT,
            game_date TEXT,
            location TEXT,
            home_team TEXT,
            away_team TEXT,
            scraped_at TEXT,
            FOREIGN KEY (division_id) REFERENCES divisions(id)
        );

        CREATE TABLE IF NOT EXISTS misconducts (
            id INTEGER PRIMARY KEY,
            game_id INTEGER,
            player_name TEXT,
            player_number TEXT,
            team TEXT,
            minute TEXT,
            reason TEXT,
            card_type TEXT,
            FOREIGN KEY (game_id) REFERENCES games(id)
        );

        CREATE TABLE IF NOT EXISTS suspensions_served (
            id INTEGER PRIMARY KEY,
            game_id INTEGER,
            player_name TEXT,
            team TEXT,
            FOREIGN KEY (game_id) REFERENCES games(id)
        );

        CREATE TABLE IF NOT EXISTS printable_suspensions (
            id INTEGER PRIMARY KEY,
            game_id INTEGER,
            player_name TEXT,
            team TEXT,
            FOREIGN KEY (game_id) REFERENCES games(id)
        );
    """)

    # Seed division records
    for div_id, info in DIVISIONS.items():
        cur.execute("""
            INSERT OR IGNORE INTO divisions (division_id, name, type, level)
            VALUES (?, ?, ?, ?)
        """, (div_id, info["name"], info["type"], info["level"]))

    conn.commit()
    conn.close()
    print(f"DB initialised at {get_db_path()}")


def get_division_pk(conn: sqlite3.Connection, division_id: int) -> int | None:
    row = conn.execute(
        "SELECT id FROM divisions WHERE division_id = ?", (division_id,)
    ).fetchone()
    return row["id"] if row else None


def game_already_scraped(conn: sqlite3.Connection, game_id: int) -> bool:
    row = conn.execute(
        "SELECT scraped_at FROM games WHERE game_id = ?", (game_id,)
    ).fetchone()
    return bool(row and row["scraped_at"])


def upsert_game(
    conn: sqlite3.Connection,
    game_id: int,
    division_id: int,
    game_number: str,
    game_date: str,
    location: str,
    home_team: str,
    away_team: str,
) -> int:
    """Insert or update a game row. Returns the games.id PK."""
    div_pk = get_division_pk(conn, division_id)
    conn.execute("""
        INSERT INTO games (game_id, division_id, game_number, game_date, location, home_team, away_team)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(game_id) DO UPDATE SET
            division_id   = excluded.division_id,
            game_number   = excluded.game_number,
            game_date     = excluded.game_date,
            location      = excluded.location,
            home_team     = excluded.home_team,
            away_team     = excluded.away_team
    """, (game_id, div_pk, game_number, game_date, location, home_team, away_team))
    row = conn.execute("SELECT id FROM games WHERE game_id = ?", (game_id,)).fetchone()
    return row["id"]


def mark_game_scraped(conn: sqlite3.Connection, game_id: int) -> None:
    from datetime import datetime, timezone
    now = datetime.now(timezone.utc).isoformat()
    conn.execute(
        "UPDATE games SET scraped_at = ? WHERE game_id = ?", (now, game_id)
    )


def insert_misconduct(
    conn: sqlite3.Connection,
    game_pk: int,
    player_name: str,
    player_number: str,
    team: str,
    minute: str,
    reason: str,
    card_type: str,
) -> None:
    conn.execute("""
        INSERT INTO misconducts (game_id, player_name, player_number, team, minute, reason, card_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    """, (game_pk, player_name, player_number, team, minute, reason, card_type))


def insert_suspension_served(
    conn: sqlite3.Connection, game_pk: int, player_name: str, team: str
) -> None:
    conn.execute("""
        INSERT INTO suspensions_served (game_id, player_name, team)
        VALUES (?, ?, ?)
    """, (game_pk, player_name, team))


def insert_printable_suspension(
    conn: sqlite3.Connection, game_pk: int, player_name: str, team: str
) -> None:
    conn.execute("""
        INSERT INTO printable_suspensions (game_id, player_name, team)
        VALUES (?, ?, ?)
    """, (game_pk, player_name, team))


def delete_game_data(conn: sqlite3.Connection, game_pk: int) -> None:
    """Remove all child rows before re-scraping a game."""
    for table in ("misconducts", "suspensions_served", "printable_suspensions"):
        conn.execute(f"DELETE FROM {table} WHERE game_id = ?", (game_pk,))
    conn.execute("UPDATE games SET scraped_at = NULL WHERE id = ?", (game_pk,))


def clear_suspension_data(conn: sqlite3.Connection, game_pk: int) -> None:
    """Remove only suspension rows for a game, leaving misconducts intact."""
    conn.execute("DELETE FROM suspensions_served WHERE game_id = ?", (game_pk,))
    conn.execute("UPDATE games SET scraped_at = NULL WHERE id = ?", (game_pk,))


def get_stats(conn: sqlite3.Connection) -> dict:
    stats = {}
    stats["divisions"] = conn.execute("SELECT COUNT(*) FROM divisions").fetchone()[0]
    stats["games_total"] = conn.execute("SELECT COUNT(*) FROM games").fetchone()[0]
    stats["games_scraped"] = conn.execute(
        "SELECT COUNT(*) FROM games WHERE scraped_at IS NOT NULL"
    ).fetchone()[0]
    stats["misconducts"] = conn.execute("SELECT COUNT(*) FROM misconducts").fetchone()[0]
    stats["yellows"] = conn.execute(
        "SELECT COUNT(*) FROM misconducts WHERE card_type = 'Yellow'"
    ).fetchone()[0]
    stats["reds"] = conn.execute(
        "SELECT COUNT(*) FROM misconducts WHERE card_type = 'Red'"
    ).fetchone()[0]
    stats["suspensions_served"] = conn.execute(
        "SELECT COUNT(*) FROM suspensions_served"
    ).fetchone()[0]
    stats["printable_suspensions"] = conn.execute(
        "SELECT COUNT(*) FROM printable_suspensions"
    ).fetchone()[0]
    stats["last_scraped"] = conn.execute(
        "SELECT MAX(scraped_at) FROM games"
    ).fetchone()[0]
    return stats
