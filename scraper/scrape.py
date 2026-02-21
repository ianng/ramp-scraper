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
from config import BASE_URL, CATID, DIVISIONS, HEADERS, REQUEST_DELAY


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
# Parsing: games list
# ---------------------------------------------------------------------------

def parse_games_list(soup: BeautifulSoup, division_id: int) -> list[dict]:
    """
    Parse the /division/.../games page.
    Returns list of dicts with keys:
        game_id, game_number, game_date, location, home_team, away_team, gamesheet_link
    """
    games = []

    # Find all tables that might contain game rows
    tables = soup.find_all("table")
    for table in tables:
        rows = table.find_all("tr")
        for row in rows:
            cells = row.find_all(["td", "th"])
            if not cells:
                continue

            # Look for a row that has a gamesheet link — that means it's a game row
            gamesheet_link = None
            for cell in cells:
                a = cell.find("a", href=re.compile(r"/gamesheet/", re.I))
                if a:
                    gamesheet_link = urljoin(BASE_URL, a["href"])
                    # Extract game_id from URL like /division/3935/35372/gamesheet/12345
                    m = re.search(r"/gamesheet/(\d+)", a["href"])
                    if m:
                        game_id = int(m.group(1))
                    break

            if not gamesheet_link:
                continue

            text_cells = [c.get_text(strip=True) for c in cells]

            # Try to extract fields from cells — layout varies, so be flexible
            game_number = ""
            game_date = ""
            location = ""
            home_team = ""
            away_team = ""

            for i, txt in enumerate(text_cells):
                # Date: looks like "Jan 15" or "2024-01-15" or "January 15, 2025"
                if re.search(r"\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b", txt, re.I) or re.match(r"\d{4}-\d{2}-\d{2}", txt):
                    if not game_date:
                        game_date = txt
                    continue
                # Game number: "#123" or just a short number
                if re.match(r"^#?\d{1,5}$", txt) and not game_number:
                    game_number = txt
                    continue

            # Try to find team names from "Team A vs Team B" or adjacent cells
            full_text = " ".join(text_cells)
            vs_match = re.search(r"(.+?)\s+(?:vs\.?|@)\s+(.+?)(?:\s|$)", full_text, re.I)
            if vs_match:
                home_team = vs_match.group(1).strip()
                away_team = vs_match.group(2).strip()

            # Location: look for "Arena" or "Pad" or similar
            for txt in text_cells:
                if any(kw in txt.lower() for kw in ["arena", "pad", "centre", "center", "complex", "rink"]):
                    location = txt
                    break

            games.append({
                "game_id": game_id,
                "game_number": game_number,
                "game_date": game_date,
                "location": location,
                "home_team": home_team,
                "away_team": away_team,
                "gamesheet_link": gamesheet_link,
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
    Parse the Misconduct Summary table from a gamesheet page.
    Expected columns (may vary): #, Player, Team, Minute, Reason, Card Type

    Also handles inline format like:
      "Continental FC at 00:00 - #14 Omid Mzaffari for Unsporting Behavior [Yellow]"
    """
    misconducts = []

    # Strategy 1: Find a table with a heading containing "Misconduct"
    for heading in soup.find_all(re.compile(r"^h[1-6]$|^th$|^td$|^caption$")):
        if "misconduct" in heading.get_text(strip=True).lower():
            # Walk up to find the containing table
            table = heading.find_parent("table") or heading.find_next("table")
            if table:
                misconducts.extend(_parse_misconduct_table_rows(table))
                break

    # Strategy 2: Find any table whose header row contains card-related columns
    if not misconducts:
        for table in soup.find_all("table"):
            headers = [th.get_text(strip=True).lower() for th in table.find_all("th")]
            if any(h in ("yellow", "red", "card", "misconduct", "caution") for h in headers) \
               or any("yellow" in h or "card" in h or "miscond" in h for h in headers):
                misconducts.extend(_parse_misconduct_table_rows(table))
                if misconducts:
                    break

    # Strategy 3: Scan all text for inline misconduct patterns
    if not misconducts:
        text = soup.get_text(separator="\n")
        for line in text.splitlines():
            m = re.search(
                r"#(\d+)\s+([A-Z][a-zA-Z '-]+?)\s+for\s+(.+?)\s+\[(Yellow|Red)\]",
                line,
            )
            if m:
                team_match = re.match(r"^(.+?)\s+at\s+", line)
                team = team_match.group(1).strip() if team_match else ""
                min_match = re.search(r"at\s+(\d{2}:\d{2})", line)
                minute = min_match.group(1) if min_match else ""
                misconducts.append({
                    "player_number": m.group(1),
                    "player_name": m.group(2).strip(),
                    "team": team,
                    "minute": minute,
                    "reason": m.group(3).strip(),
                    "card_type": m.group(4),
                })

    return misconducts


def _parse_misconduct_table_rows(table) -> list[dict]:
    rows = table.find_all("tr")
    if not rows:
        return []

    # Detect header row
    header_cells = [th.get_text(strip=True).lower() for th in rows[0].find_all(["th", "td"])]

    # Map column indices
    col_map = {}
    for i, h in enumerate(header_cells):
        if "player" in h and "name" not in col_map.get("player_name", ""):
            col_map.setdefault("player_name", i)
        if "#" == h or "num" in h or "number" in h:
            col_map["player_number"] = i
        if "team" in h:
            col_map["team"] = i
        if "min" in h or "time" in h:
            col_map["minute"] = i
        if "reason" in h or "offence" in h or "infraction" in h or "description" in h:
            col_map["reason"] = i
        if "card" in h or "type" in h or "yellow" in h or "red" in h:
            col_map["card_type"] = i

    results = []
    for row in rows[1:]:
        cells = [td.get_text(strip=True) for td in row.find_all(["td", "th"])]
        if not cells or all(c == "" for c in cells):
            continue

        def get(key, default=""):
            idx = col_map.get(key)
            if idx is not None and idx < len(cells):
                return cells[idx]
            return default

        # Infer card type from colour if not in a dedicated column
        card_type = get("card_type")
        if not card_type:
            row_html = str(row).lower()
            if "yellow" in row_html:
                card_type = "Yellow"
            elif "red" in row_html:
                card_type = "Red"
        else:
            # Normalise
            if "yellow" in card_type.lower():
                card_type = "Yellow"
            elif "red" in card_type.lower():
                card_type = "Red"

        if not card_type:
            continue  # Skip rows that clearly aren't misconduct entries

        results.append({
            "player_number": get("player_number"),
            "player_name": get("player_name"),
            "team": get("team"),
            "minute": get("minute"),
            "reason": get("reason"),
            "card_type": card_type,
        })

    return results


def parse_suspensions_served(soup: BeautifulSoup) -> list[dict]:
    """
    Parse the Completed Suspensions table from the gamesheet page.
    Returns list of {player_name, team}.
    """
    suspensions = []

    # Look for a section/heading labelled "Completed Suspensions" or "Suspensions"
    for heading in soup.find_all(re.compile(r"^h[1-6]$|^td$|^th$|^caption$")):
        text = heading.get_text(strip=True).lower()
        if "complet" in text and "suspend" in text or "suspension" in text:
            table = heading.find_parent("table") or heading.find_next("table")
            if table:
                suspensions.extend(_parse_suspension_rows(table))
                break

    if not suspensions:
        for table in soup.find_all("table"):
            header_text = " ".join(th.get_text(strip=True).lower() for th in table.find_all("th"))
            if "suspend" in header_text:
                rows = table.find_all("tr")
                suspensions.extend(_parse_suspension_rows(table))
                if suspensions:
                    break

    return suspensions


def _parse_suspension_rows(table) -> list[dict]:
    rows = table.find_all("tr")
    if not rows:
        return []
    header_cells = [c.get_text(strip=True).lower() for c in rows[0].find_all(["th", "td"])]
    player_col = next((i for i, h in enumerate(header_cells) if "player" in h or "name" in h), 0)
    team_col = next((i for i, h in enumerate(header_cells) if "team" in h), 1)

    results = []
    for row in rows[1:]:
        cells = [td.get_text(strip=True) for td in row.find_all(["td", "th"])]
        if not cells or all(c == "" for c in cells):
            continue
        player_name = cells[player_col] if player_col < len(cells) else ""
        team = cells[team_col] if team_col < len(cells) else ""
        if player_name:
            results.append({"player_name": player_name, "team": team})
    return results


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

    url = games_url(division_id)
    soup = fetch(url)
    sleep()
    if not soup:
        print("  Could not fetch games list — skipping.")
        return

    games = parse_games_list(soup, division_id)
    print(f"  Found {len(games)} games on page.")

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
