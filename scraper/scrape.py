#!/usr/bin/env python3
"""
Indoor Soccer League Misconduct Scraper
Usage:
    python scrape.py                     # Incremental scrape all divisions
    python scrape.py --full              # Force re-scrape every game
    python scrape.py --update            # Re-scrape stale future fixtures now in the past
    python scrape.py --division 35372    # Single division only
    python scrape.py --status            # Show DB stats, no scraping
"""

import argparse
import re
import sys
import time
from typing import Optional
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

import db
from config import BASE_URL, CATID, DIVISIONS, HEADERS, REQUEST_DELAY, ORG_ID, SEASON_IDS


# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------

session = requests.Session()
session.headers.update(HEADERS)


def fetch(url: str) -> Optional[BeautifulSoup]:
    try:
        resp = session.get(url, timeout=20)
        resp.raise_for_status()
        return BeautifulSoup(resp.text, "lxml")
    except requests.RequestException as exc:
        print(f"  [WARN] Failed to fetch {url}: {exc}")
        return None


def fetch_json(url: str) -> Optional[list | dict]:
    try:
        resp = session.get(url, timeout=20)
        resp.raise_for_status()
        return resp.json()
    except requests.RequestException as exc:
        print(f"  [WARN] Failed to fetch {url}: {exc}")
        return None
    except Exception as exc:
        print(f"  [WARN] JSON decode error for {url}: {exc}")
        return None


def sleep():
    time.sleep(REQUEST_DELAY)


# ---------------------------------------------------------------------------
# URL builders
# ---------------------------------------------------------------------------

def games_url(division_id: int) -> str:
    return f"{BASE_URL}/division/{CATID}/{division_id}/games"


def gamesheet_url(division_id: int, game_id: int) -> str:
    return f"{BASE_URL}/division/{CATID}/{division_id}/gamesheet/{game_id}"


# ---------------------------------------------------------------------------
# Games list — fetched via RAMP JSON API
# ---------------------------------------------------------------------------

def fetch_games_for_division(division_id: int) -> list[dict]:
    """
    Fetch all games for a division across all configured seasons using the
    RAMP JSON API: /api/leaguegame/get/{orgId}/{seasonId}/{catId}/{divId}/0/0/

    Returns list of dicts with keys:
        game_id, game_number, game_date, location, home_team, away_team, gamesheet_link
    """
    games = []
    for season_id in SEASON_IDS:
        url = f"{BASE_URL}/api/leaguegame/get/{ORG_ID}/{season_id}/{CATID}/{division_id}/0/0/"
        data = fetch_json(url)
        sleep()
        if not data:
            continue
        for g in data:
            gid = g.get("GID")
            if not gid:
                continue
            # Strip score suffix from team names e.g. "Continental FC (4)" → "Continental FC"
            home = re.sub(r'\s*\(\d+\)\s*$', '', g.get("HomeTeamName") or "").strip()
            away = re.sub(r'\s*\(\d+\)\s*$', '', g.get("AwayTeamName") or "").strip()
            games.append({
                "game_id": int(gid),
                "game_number": str(g.get("gameNumber") or ""),
                "game_date": g.get("sDate") or g.get("sDateString") or "",
                "location": g.get("ArenaName") or "",
                "home_team": home,
                "away_team": away,
                "gamesheet_link": f"{BASE_URL}/division/{CATID}/{division_id}/gamesheet/{gid}",
            })
    return games


# ---------------------------------------------------------------------------
# Parsing: gamesheet page
# ---------------------------------------------------------------------------

def find_printable_url(soup: BeautifulSoup, gamesheet_page_url: str) -> Optional[str]:
    """
    Look for a printable/print link on the gamesheet page.
    Common patterns:
        <a href="...print...">Print</a>
        <a href="...printable...">Printable Gamesheet</a>
        <a class="print-btn" ...>
    """
    # Search all anchors for print-related text or href
    for a in soup.find_all("a", href=True):
        href = a["href"]
        text = a.get_text(strip=True).lower()
        if "print" in href.lower() or "print" in text:
            return urljoin(gamesheet_page_url, href)

    # Fallback: look for button with onclick containing print URL
    for btn in soup.find_all(["button", "input"], attrs={"onclick": True}):
        onclick = btn.get("onclick", "")
        m = re.search(r"(https?://[^\s'\"]+print[^\s'\"]*)", onclick)
        if m:
            return m.group(1)
        # Relative URL in onclick
        m = re.search(r"window\.open\(['\"]([^'\"]+)['\"]", onclick)
        if m:
            url = m.group(1)
            if "print" in url.lower():
                return urljoin(gamesheet_page_url, url)

    return None


def parse_misconduct_table(soup: BeautifulSoup) -> list[dict]:
    """
    Parse the 'Time of Misconducts' table from a RAMP gamesheet page.

    Each data row is a single cell containing inline text like:
      "Cozmos 2at 00:00 -#22 Mike Collinsfor Unsporting Behavior [Yellow]"
    """
    misconducts = []

    # Find the table whose first row header contains "Misconduct"
    for table in soup.find_all("table"):
        first_row = table.find("tr")
        if not first_row:
            continue
        header_text = first_row.get_text(strip=True).lower()
        if "misconduct" not in header_text:
            continue
        # Found the right table — parse data rows
        for row in table.find_all("tr")[1:]:
            cell_text = row.get_text(strip=True)
            if not cell_text or "no misconduct" in cell_text.lower():
                continue
            m = _parse_misconduct_line(cell_text)
            if m:
                misconducts.append(m)
        break  # Only one misconduct table per page

    return misconducts


# RAMP inline misconduct format:
#   "{Team}at {MM:SS} -#{num} {Name}for {Reason} [{Yellow|Red}]"
# Note: no spaces before "at" or "for" — they run directly into the text
_MISCONDUCT_RE = re.compile(
    r"^(.+?)at\s+(\d{1,2}:\d{2})\s+-\s*(?:#(\d+)\s+)?(.+?)for\s+(.+?)\s+\[(Yellow|Red)\]$",
    re.IGNORECASE,
)


def _parse_misconduct_line(text: str) -> Optional[dict]:
    m = _MISCONDUCT_RE.match(text)
    if not m:
        return None
    return {
        "team":          m.group(1).strip(),
        "minute":        m.group(2).strip(),
        "player_number": m.group(3).strip() if m.group(3) else "",
        "player_name":   re.sub(r'\s*\(AP\)\s*', '', m.group(4)).strip(),
        "reason":        m.group(5).strip(),
        "card_type":     m.group(6).capitalize(),
    }


def parse_suspensions_served(soup: BeautifulSoup) -> list[dict]:
    """
    Parse the Completed Suspensions section from a RAMP gamesheet page.
    Returns list of {player_name, team}.

    RAMP structure:
        <h3>Completed Suspensions</h3>
        <table ...>
            <tr><td>Player Name</td></tr>   (one row per player)
            ...
        </table>
    Empty case:
        <table><tr><td>No Completed Suspensions</td></tr></table>

    There is no team column — only the player name is present.
    """
    suspensions = []

    for h3 in soup.find_all("h3"):
        if "completed suspension" not in h3.get_text(strip=True).lower():
            continue
        # Find the immediately following table sibling
        table = h3.find_next_sibling("table")
        if not table:
            break
        for row in table.find_all("tr"):
            name = row.get_text(strip=True)
            if not name or "no completed" in name.lower():
                continue
            suspensions.append({"player_name": name, "team": ""})
        break

    return suspensions


def parse_printable_gamesheet(soup: BeautifulSoup) -> list[dict]:
    """
    Parse suspended players from the printable gamesheet.
    Returns list of {player_name, team}.
    """
    suspended = []

    text = soup.get_text(separator="\n")
    in_section = False
    for line in text.splitlines():
        stripped = line.strip()
        if re.search(r"suspend", stripped, re.I):
            in_section = True
            continue
        if in_section:
            if not stripped:
                # Blank line might end section — but keep scanning a few more
                continue
            # If we hit another section header, stop
            if re.match(r"^[A-Z][A-Z\s]+:?\s*$", stripped) and len(stripped) > 20:
                break
            # Player entries often look like "Smith, John   TeamName"
            # or "John Smith - TeamName"
            m = re.match(r"^([A-Za-z ,'\-\.]+?)\s{2,}(.+)$", stripped)
            if m:
                suspended.append({
                    "player_name": m.group(1).strip(),
                    "team": m.group(2).strip(),
                })

    # Fallback: scan tables on printable page
    if not suspended:
        for table in soup.find_all("table"):
            header_text = " ".join(th.get_text(strip=True).lower() for th in table.find_all("th"))
            if "suspend" in header_text or "player" in header_text:
                suspended.extend(_parse_suspension_rows(table))

    return suspended


# ---------------------------------------------------------------------------
# Core scraping logic
# ---------------------------------------------------------------------------

def scrape_gamesheet(
    conn,
    game_pk: int,
    game_id: int,
    division_id: int,
    force: bool,
    suspensions_only: bool = False,
) -> None:
    """Scrape a single gamesheet and store results in DB.

    suspensions_only: if True, skip misconduct parsing and only (re-)load
                      the Completed Suspensions section.  Misconducts already
                      in the DB are left untouched.
    """
    url = gamesheet_url(division_id, game_id)
    print(f"    Fetching gamesheet {game_id} …", end=" ", flush=True)

    soup = fetch(url)
    sleep()
    if not soup:
        print("SKIP (fetch failed)")
        return

    if force:
        db.delete_game_data(conn, game_pk)

    misconduct_count = 0
    if not suspensions_only:
        corrections = db.get_name_corrections(conn, game_id)
        misconducts = parse_misconduct_table(soup)
        for m in misconducts:
            db.insert_misconduct(
                conn, game_pk,
                m["player_name"], m["player_number"],
                m["team"], m["minute"],
                m["reason"], m["card_type"],
                corrections=corrections,
            )
        misconduct_count = len(misconducts)

    # Suspensions served (on this gamesheet)
    served = parse_suspensions_served(soup)
    for s in served:
        db.insert_suspension_served(conn, game_pk, s["player_name"], s["team"])

    db.mark_game_scraped(conn, game_id)
    conn.commit()

    if suspensions_only:
        print(f"OK ({len(served)} suspensions)")
    else:
        print(f"OK ({misconduct_count} misconducts, {len(served)} suspensions)")


def scrape_division(conn, division_id: int, force: bool = False) -> None:
    info = DIVISIONS.get(division_id, {})
    name = info.get("name", str(division_id))
    print(f"\n[Division {division_id}] {name}")

    games = fetch_games_for_division(division_id)
    print(f"  Found {len(games)} games via API.")

    for game in games:
        gid = game["game_id"]
        game_pk = db.upsert_game(
            conn, gid, division_id,
            game["game_number"], game["game_date"],
            game["location"], game["home_team"], game["away_team"],
        )
        conn.commit()

        if not force and db.game_already_scraped(conn, gid):
            print(f"    Game {gid} already scraped — skipping.")
            continue

        if not game["gamesheet_link"]:
            print(f"    Game {gid} has no gamesheet link — skipping.")
            continue

        scrape_gamesheet(conn, game_pk, gid, division_id, force)


# ---------------------------------------------------------------------------
# Targeted suspension rescrape
# ---------------------------------------------------------------------------

def cmd_rescrape_suspensions(conn) -> None:
    """
    Re-scrape only the 'Completed Suspensions' section for games that
    could contain suspension-served entries — i.e. games whose RAMP game_id
    is >= the earliest game where any player triggered a threshold.

    Misconduct data already in the DB is left untouched; only
    suspensions_served rows are refreshed.
    """
    from collections import defaultdict

    # Load all yellows per player ordered by game_id (proxy for chronology)
    rows = conn.execute("""
        SELECT m.player_name, g.game_id
        FROM misconducts m
        JOIN games g ON m.game_id = g.id
        WHERE m.card_type = 'Yellow'
        ORDER BY m.player_name, g.game_id ASC
    """).fetchall()

    if not rows:
        print("No yellow card data in DB — run a full scrape first.")
        return

    # Find the first game_id where each player hit a threshold (3, 5, 7+)
    player_games: dict[str, list[int]] = defaultdict(list)
    for row in rows:
        player_games[row["player_name"]].append(row["game_id"])

    trigger_game_ids: list[int] = []
    for name, game_ids in player_games.items():
        for i, gid in enumerate(game_ids):
            count = i + 1
            if count == 3 or count == 5 or count >= 7:
                trigger_game_ids.append(gid)
                break  # Only need the first trigger per player

    if not trigger_game_ids:
        print("No players have hit a suspension threshold yet.")
        return

    min_trigger_gid = min(trigger_game_ids)
    print(f"Earliest suspension trigger at RAMP game_id {min_trigger_gid}.")

    # All games at or after that trigger
    games = conn.execute("""
        SELECT g.id AS pk, g.game_id, d.division_id AS ext_div_id
        FROM games g
        JOIN divisions d ON g.division_id = d.id
        WHERE g.game_id >= ?
        ORDER BY g.game_id ASC
    """, (min_trigger_gid,)).fetchall()

    print(f"Games to check: {len(games)} (out of {conn.execute('SELECT COUNT(*) FROM games').fetchone()[0]} total)")

    for game in games:
        db.clear_suspension_data(conn, game["pk"])
        conn.commit()
        scrape_gamesheet(
            conn,
            game["pk"],
            game["game_id"],
            game["ext_div_id"],
            force=False,
            suspensions_only=True,
        )

    print("\nSuspension rescrape complete.")
    cmd_status()


# ---------------------------------------------------------------------------
# Stale-game updater
# ---------------------------------------------------------------------------

def cmd_update_stale(conn) -> None:
    """
    Re-scrape games that were scraped before their game date — i.e., games
    fetched when they were still future fixtures, so their gamesheets were
    empty.  Clears existing (empty) data and re-scrapes each one.
    """
    stale = conn.execute("""
        SELECT g.id AS pk, g.game_id, d.division_id AS ext_div_id,
               g.game_date, g.scraped_at
        FROM games g
        JOIN divisions d ON g.division_id = d.id
        WHERE g.scraped_at IS NOT NULL
          AND date(g.game_date) <= date('now')
          AND date(g.scraped_at) < date(g.game_date)
        ORDER BY g.game_date ASC
    """).fetchall()

    if not stale:
        print("No stale games found — all scraped games were scraped on or after their game date.")
        return

    print(f"Found {len(stale)} game(s) scraped before their game date. Re-scraping...\n")
    for game in stale:
        print(f"  [{game['game_date']}] game_id={game['game_id']} (was scraped {game['scraped_at'][:10]})")
        db.delete_game_data(conn, game["pk"])
        conn.commit()
        scrape_gamesheet(conn, game["pk"], game["game_id"], game["ext_div_id"], force=False)

    print("\nUpdate complete.")
    cmd_status()


# ---------------------------------------------------------------------------
# Date-range rescrape
# ---------------------------------------------------------------------------

def cmd_rescrape_since(conn, since_date: str) -> None:
    """
    Re-scrape all games with game_date >= since_date.
    Clears existing misconduct + suspension data and re-scrapes each gamesheet.
    """
    games = conn.execute("""
        SELECT g.id AS pk, g.game_id, d.division_id AS ext_div_id,
               g.game_date, g.scraped_at
        FROM games g
        JOIN divisions d ON g.division_id = d.id
        WHERE date(g.game_date) >= ?
          AND date(g.game_date) <= date('now')
        ORDER BY g.game_date ASC
    """, (since_date,)).fetchall()

    if not games:
        print(f"No games found on or after {since_date}.")
        return

    print(f"Re-scraping {len(games)} game(s) from {since_date} onwards...\n")
    for game in games:
        db.delete_game_data(conn, game["pk"])
        conn.commit()
        scrape_gamesheet(conn, game["pk"], game["game_id"], game["ext_div_id"], force=False)

    print("\nRescrape complete.")
    cmd_status()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def cmd_status() -> None:
    conn = db.get_connection()
    stats = db.get_stats(conn)
    conn.close()
    print("\n=== Misconduct DB Status ===")
    for k, v in stats.items():
        print(f"  {k:25s}: {v}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Indoor Soccer League Misconduct Scraper")
    parser.add_argument("--full", action="store_true", help="Force re-scrape all games")
    parser.add_argument("--division", type=int, metavar="DIV_ID", help="Scrape a single division")
    parser.add_argument("--status", action="store_true", help="Show DB stats and exit")
    parser.add_argument(
        "--update", action="store_true",
        help="Re-scrape games that were scraped before their game date (stale future fixtures)",
    )
    parser.add_argument(
        "--rescrape-suspensions", action="store_true",
        help="Re-scrape only the suspensions section for games at/after the earliest "
             "suspension trigger. Much faster than --full; use after fixing the parser.",
    )
    parser.add_argument(
        "--rescrape-since", metavar="DATE",
        help="Re-scrape all games on or after DATE (YYYY-MM-DD). Clears and re-fetches "
             "misconduct and suspension data for every matching game.",
    )
    args = parser.parse_args()

    db.init_db()

    if args.status:
        cmd_status()
        return

    conn = db.get_connection()

    try:
        if args.update:
            cmd_update_stale(conn)
        elif args.rescrape_since:
            cmd_rescrape_since(conn, args.rescrape_since)
        elif args.rescrape_suspensions:
            cmd_rescrape_suspensions(conn)
        elif args.division:
            if args.division not in DIVISIONS:
                print(f"Unknown division ID {args.division}. Valid IDs: {list(DIVISIONS)}")
                sys.exit(1)
            scrape_division(conn, args.division, force=args.full)
        else:
            for div_id in DIVISIONS:
                scrape_division(conn, div_id, force=args.full)
    finally:
        conn.close()

    print("\nDone.")
    cmd_status()


if __name__ == "__main__":
    main()
