# Set these in local_config.py (gitignored) — do not hardcode here
try:
    from local_config import BASE_URL, CATID, ORG_ID, SEASON_IDS
except ImportError:
    BASE_URL = "https://your-league-site.com"
    CATID = 0
    ORG_ID = 0
    SEASON_IDS = []  # list of season IDs to scrape

DIVISIONS = {
    35372: {"name": "Coed 1",           "type": "coed",   "level": 1},
    35373: {"name": "Coed 2",           "type": "coed",   "level": 2},
    35374: {"name": "Coed 3",           "type": "coed",   "level": 3},
    35375: {"name": "Coed 4",           "type": "coed",   "level": 4},
    35376: {"name": "Coed 5",           "type": "coed",   "level": 5},
    35377: {"name": "Mens 1",           "type": "mens",   "level": 1},
    35378: {"name": "Mens 2",           "type": "mens",   "level": 2},
    35379: {"name": "Mens 3",           "type": "mens",   "level": 3},
    35380: {"name": "Mens 4",           "type": "mens",   "level": 4},
    35381: {"name": "Mens 5",           "type": "mens",   "level": 5},
    35382: {"name": "Mens 6",           "type": "mens",   "level": 6},
    35383: {"name": "Mens 7",           "type": "mens",   "level": 7},
    35384: {"name": "Mens Masters",     "type": "mens",   "level": 8},
    35385: {"name": "Women 1",          "type": "womens", "level": 1},
    35386: {"name": "Women 2",          "type": "womens", "level": 2},
    35387: {"name": "Women Community",  "type": "womens", "level": 3},
}

# Request headers to avoid bot detection
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/120.0.0.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.5",
}

REQUEST_DELAY = 0.5  # seconds between requests
DB_PATH = "../data/cards.db"
