import sqlite3
import os
from config import DB_PATH, CLUBS, get_all_divisions


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
        CREATE TABLE IF NOT EXISTS organizations (
            id INTEGER PRIMARY KEY,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            base_url TEXT NOT NULL,
            org_id INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS divisions (
            id INTEGER PRIMARY KEY,
            division_id INTEGER UNIQUE,
            name TEXT,
            type TEXT,
            level INTEGER,
            org_id INTEGER REFERENCES organizations(id),
            catid INTEGER
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

        -- Manual name corrections keyed by RAMP external game_id.
        -- Applied during scraping so corrections survive --full re-scrapes.
        CREATE TABLE IF NOT EXISTS name_corrections (
            id INTEGER PRIMARY KEY,
            game_id INTEGER NOT NULL,
            wrong_name TEXT NOT NULL,
            correct_name TEXT NOT NULL,
            UNIQUE(game_id, wrong_name)
        );
    """)

    # Migrate existing divisions table: add org_id and catid columns if missing
    cols = {row[1] for row in cur.execute("PRAGMA table_info(divisions)").fetchall()}
    if "org_id" not in cols:
        cur.execute("ALTER TABLE divisions ADD COLUMN org_id INTEGER REFERENCES organizations(id)")
    if "catid" not in cols:
        cur.execute("ALTER TABLE divisions ADD COLUMN catid INTEGER")

    # Seed organizations and divisions from config
    for slug, club in CLUBS.items():
        cur.execute("""
            INSERT OR IGNORE INTO organizations (slug, name, base_url, org_id)
            VALUES (?, ?, ?, ?)
        """, (slug, club["name"], club["base_url"], club["org_id"]))

        # Get the org PK
        org_pk = cur.execute(
            "SELECT id FROM organizations WHERE slug = ?", (slug,)
        ).fetchone()[0]

        # Update org fields in case they changed
        cur.execute("""
            UPDATE organizations SET name = ?, base_url = ?, org_id = ?
            WHERE slug = ?
        """, (club["name"], club["base_url"], club["org_id"], slug))

        all_divs = get_all_divisions(club)
        for div_id, info in all_divs.items():
            cur.execute("""
                INSERT INTO divisions (division_id, name, type, level, org_id, catid)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(division_id) DO UPDATE SET
                    name = excluded.name,
                    type = excluded.type,
                    level = excluded.level,
                    org_id = excluded.org_id,
                    catid = excluded.catid
            """, (div_id, info["name"], info["type"], info["level"], org_pk, info["catid"]))

    # Backfill org_id for any existing divisions that predate multi-club support
    cur.execute("""
        UPDATE divisions SET
            org_id = (SELECT id FROM organizations WHERE slug = 'regina'),
            catid = 3935
        WHERE org_id IS NULL
    """)

    # Seed known name corrections (game_id = RAMP external GID).
    KNOWN_CORRECTIONS = [
        (1707872, "khaed issa",           "Khaled Issa"),
        (1707883, "Abdul Rahman  Nasser", "Abdulrahman Nasser"),
        (1666700, "Carlos Gonzales",      "Carlos Gonzalez"),
        (1666930, "mahmoud issa",         "Mahmoud Issa"),
        (1667045, "mahmoud issa",         "Mahmoud Issa"),
        (1666882, "shan dhillon",         "Shan Dhillon"),
        (1667057, "riley meloche",        "Riley Meloche"),
        (1666995, "SHAM WELDEMICHEL",     "Sham Weldemichel"),
        (1667101, "sham weldemichel",     "Sham Weldemichel"),
        (1666848, "Adebo Falase",         "Adeoba Falase"),
        (1707887, "Sulliman Akbari",      "Suleman Akbari"),
    ]
    for gid, wrong, correct in KNOWN_CORRECTIONS:
        cur.execute("""
            INSERT OR IGNORE INTO name_corrections (game_id, wrong_name, correct_name)
            VALUES (?, ?, ?)
        """, (gid, wrong, correct))

    conn.commit()
    conn.close()
    print(f"DB initialised at {get_db_path()}")


def get_org_pk(conn: sqlite3.Connection, slug: str) -> int | None:
    row = conn.execute(
        "SELECT id FROM organizations WHERE slug = ?", (slug,)
    ).fetchone()
    return row["id"] if row else None


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


def get_name_corrections(conn: sqlite3.Connection, ramp_game_id: int) -> dict:
    """Return {wrong_name: correct_name} for the given RAMP external game_id."""
    rows = conn.execute(
        "SELECT wrong_name, correct_name FROM name_corrections WHERE game_id = ?",
        (ramp_game_id,),
    ).fetchall()
    return {r["wrong_name"]: r["correct_name"] for r in rows}


def insert_name_correction(
    conn: sqlite3.Connection,
    ramp_game_id: int,
    wrong_name: str,
    correct_name: str,
) -> None:
    """Persist a name correction and commit immediately."""
    conn.execute("""
        INSERT OR IGNORE INTO name_corrections (game_id, wrong_name, correct_name)
        VALUES (?, ?, ?)
    """, (ramp_game_id, wrong_name, correct_name))
    conn.commit()


def insert_misconduct(
    conn: sqlite3.Connection,
    game_pk: int,
    player_name: str,
    player_number: str,
    team: str,
    minute: str,
    reason: str,
    card_type: str,
    corrections: dict | None = None,
) -> None:
    if corrections:
        player_name = corrections.get(player_name, player_name)
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


def get_stats(conn: sqlite3.Connection, org_slug: str | None = None) -> dict:
    """Get DB stats, optionally filtered to a single organization."""
    stats = {}

    def _count(sql: str, params: tuple = ()) -> int:
        return conn.execute(sql, params).fetchone()[0]

    if org_slug:
        p = (org_slug,)
        org_join = (
            "JOIN divisions d ON d.id = g.division_id "
            "JOIN organizations o ON d.org_id = o.id"
        )
        org_where = "o.slug = ?"

        stats["divisions"] = _count(
            f"SELECT COUNT(*) FROM divisions d JOIN organizations o ON d.org_id = o.id WHERE {org_where}", p)
        stats["games_total"] = _count(
            f"SELECT COUNT(*) FROM games g {org_join} WHERE {org_where}", p)
        stats["games_scraped"] = _count(
            f"SELECT COUNT(*) FROM games g {org_join} WHERE {org_where} AND g.scraped_at IS NOT NULL", p)
        stats["misconducts"] = _count(
            f"SELECT COUNT(*) FROM misconducts m JOIN games g ON m.game_id = g.id {org_join} WHERE {org_where}", p)
        stats["yellows"] = _count(
            f"SELECT COUNT(*) FROM misconducts m JOIN games g ON m.game_id = g.id {org_join} WHERE {org_where} AND m.card_type = 'Yellow'", p)
        stats["reds"] = _count(
            f"SELECT COUNT(*) FROM misconducts m JOIN games g ON m.game_id = g.id {org_join} WHERE {org_where} AND m.card_type = 'Red'", p)
        stats["suspensions_served"] = _count(
            f"SELECT COUNT(*) FROM suspensions_served s JOIN games g ON s.game_id = g.id {org_join} WHERE {org_where}", p)
        stats["printable_suspensions"] = _count(
            f"SELECT COUNT(*) FROM printable_suspensions ps JOIN games g ON ps.game_id = g.id {org_join} WHERE {org_where}", p)
        stats["last_scraped"] = conn.execute(
            f"SELECT MAX(g.scraped_at) FROM games g {org_join} WHERE {org_where}", p
        ).fetchone()[0]
    else:
        stats["divisions"] = _count("SELECT COUNT(*) FROM divisions")
        stats["games_total"] = _count("SELECT COUNT(*) FROM games")
        stats["games_scraped"] = _count("SELECT COUNT(*) FROM games WHERE scraped_at IS NOT NULL")
        stats["misconducts"] = _count("SELECT COUNT(*) FROM misconducts")
        stats["yellows"] = _count("SELECT COUNT(*) FROM misconducts WHERE card_type = 'Yellow'")
        stats["reds"] = _count("SELECT COUNT(*) FROM misconducts WHERE card_type = 'Red'")
        stats["suspensions_served"] = _count("SELECT COUNT(*) FROM suspensions_served")
        stats["printable_suspensions"] = _count("SELECT COUNT(*) FROM printable_suspensions")
        stats["last_scraped"] = conn.execute("SELECT MAX(scraped_at) FROM games").fetchone()[0]

    return stats
