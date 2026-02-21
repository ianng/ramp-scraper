#!/usr/bin/env python3
"""
Indoor Soccer League Misconduct Scraper
Usage:
    python scrape.py                     # Incremental scrape all divisions
    python scrape.py --full              # Force re-scrape every game
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
                "game_date": g.get("sDateString") or "",
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
    r"^(.+?)at\s+(\d{1,2}:\d{2})\s+-\s*#(\d+)\s+(.+?)for\s+(.+?)\s+\[(Yellow|Red)\]$",
    re.IGNORECASE,
)


def _parse_misconduct_line(text: str) -> Optional[dict]:
    m = _MISCONDUCT_RE.match(text)
    if not m:
        return None
    return {
        "team":          m.group(1).strip(),
        "minute":        m.group(2).strip(),
        "player_number": m.group(3).strip(),
        "player_name":   m.group(4).strip(),
        "reason":        m.group(5).strip(),
        "card_type":     m.group(6).capitalize(),
    }


def parse_suspensions_served(soup: BeautifulSoup) -> list[dict]:
    """
    Parse the Completed Suspensions table from a RAMP gamesheet page.
    Returns list of {player_name, team}.

    The last table on the page is headed "No Completed Suspensions" (empty)
    or contains player rows with Name and Team columns.
    """
    suspensions = []

    for table in soup.find_all("table"):
        first_row = table.find("tr")
        if not first_row:
            continue
        header_text = first_row.get_text(strip=True).lower()
        if "suspend" not in header_text:
            continue
        if "no completed" in header_text or "no suspension" in header_text:
            break  # Explicitly empty — done
        # Has suspension rows; header columns are Name, Team (or similar)
        rows = table.find_all("tr")
        header_cells = [c.get_text(strip=True).lower() for c in rows[0].find_all(["th", "td"])]
        name_col = next((i for i, h in enumerate(header_cells) if "name" in h or "player" in h), 0)
        team_col = next((i for i, h in enumerate(header_cells) if "team" in h), 1)
        for row in rows[1:]:
            cells = [td.get_text(strip=True) for td in row.find_all(["td", "th"])]
            if not cells or all(c == "" for c in cells):
                continue
            player_name = cells[name_col] if name_col < len(cells) else ""
            team = cells[team_col] if team_col < len(cells) else ""
            if player_name:
                suspensions.append({"player_name": player_name, "team": team})
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
) -> None:
    """Scrape a single gamesheet and store results in DB."""
    url = gamesheet_url(division_id, game_id)
    print(f"    Fetching gamesheet {game_id} …", end=" ", flush=True)

    soup = fetch(url)
    sleep()
    if not soup:
        print("SKIP (fetch failed)")
        return

    if force:
        db.delete_game_data(conn, game_pk)

    # Misconducts
    misconducts = parse_misconduct_table(soup)
    for m in misconducts:
        db.insert_misconduct(
            conn, game_pk,
            m["player_name"], m["player_number"],
            m["team"], m["minute"],
            m["reason"], m["card_type"],
        )

    # Suspensions served (on this gamesheet)
    served = parse_suspensions_served(soup)
    for s in served:
        db.insert_suspension_served(conn, game_pk, s["player_name"], s["team"])

    # Printable gamesheet
    print_url = find_printable_url(soup, url)
    if print_url:
        print_soup = fetch(print_url)
        sleep()
        if print_soup:
            printable = parse_printable_gamesheet(print_soup)
            for p in printable:
                db.insert_printable_suspension(conn, game_pk, p["player_name"], p["team"])

    db.mark_game_scraped(conn, game_id)
    conn.commit()

    card_counts = f"{len(misconducts)} misconducts, {len(served)} suspensions"
    print(f"OK ({card_counts})")


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
    args = parser.parse_args()

    db.init_db()

    if args.status:
        cmd_status()
        return

    conn = db.get_connection()

    try:
        if args.division:
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
