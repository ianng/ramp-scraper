# Multi-club configuration registry.
# Each club entry contains all RAMP-specific identifiers needed for scraping.

CLUBS = {
    "regina": {
        "name": "FC Regina",
        "base_url": "https://fcregina.com",
        "org_id": 1973,
        "seasons": [12622],  # 2025/26 Indoor
        "categories": {
            3935: {
                35372: {"name": "Coed 1",          "type": "coed",   "level": 1},
                35373: {"name": "Coed 2",          "type": "coed",   "level": 2},
                35374: {"name": "Coed 3",          "type": "coed",   "level": 3},
                35375: {"name": "Coed 4",          "type": "coed",   "level": 4},
                35376: {"name": "Coed 5",          "type": "coed",   "level": 5},
                35377: {"name": "Mens 1",          "type": "mens",   "level": 1},
                35378: {"name": "Mens 2",          "type": "mens",   "level": 2},
                35379: {"name": "Mens 3",          "type": "mens",   "level": 3},
                35380: {"name": "Mens 4",          "type": "mens",   "level": 4},
                35381: {"name": "Mens 5",          "type": "mens",   "level": 5},
                35382: {"name": "Mens 6",          "type": "mens",   "level": 6},
                35383: {"name": "Mens 7",          "type": "mens",   "level": 7},
                35384: {"name": "Mens Masters",    "type": "mens",   "level": 8},
                35385: {"name": "Women 1",         "type": "womens", "level": 1},
                35386: {"name": "Women 2",         "type": "womens", "level": 2},
                35387: {"name": "Women Community", "type": "womens", "level": 3},
            },
        },
    },
    "saskatoon": {
        "name": "Saskatoon Adult Soccer",
        "base_url": "https://saskatoonadultsoccer.com",
        "org_id": 1996,
        "seasons": [12630],  # Indoor 2025-2026
        "api_extra_segment": True,  # API URL has extra /0 at end
        "categories": {
            889: {
                24847: {"name": "Coed Boarded 1",       "type": "coed",   "level": 1},
                11300: {"name": "Coed Boarded 2",       "type": "coed",   "level": 2},
                11301: {"name": "Coed Boarded 3",       "type": "coed",   "level": 3},
                10214: {"name": "Coed Boarded 4",       "type": "coed",   "level": 4},
                10215: {"name": "Coed Boarded 5",       "type": "coed",   "level": 5},
                11302: {"name": "Coed Boarded 6",       "type": "coed",   "level": 6},
                11305: {"name": "Coed Boarded 7",       "type": "coed",   "level": 7},
                24848: {"name": "Coed Legends Turf 1",  "type": "coed",   "level": 8},
                30205: {"name": "Coed Masters Turf 2",  "type": "coed",   "level": 9},
            },
            1472: {
                13441: {"name": "Mens Turf 1",          "type": "mens",   "level": 1},
                14010: {"name": "Mens Turf 2",          "type": "mens",   "level": 2},
                14013: {"name": "Mens Turf 3",          "type": "mens",   "level": 3},
                14014: {"name": "Mens Turf 4",          "type": "mens",   "level": 4},
                14015: {"name": "Mens Turf 5",          "type": "mens",   "level": 5},
                17533: {"name": "Mens Legends Turf 1",  "type": "mens",   "level": 6},
                24956: {"name": "Mens Masters Boarded",  "type": "mens",   "level": 7},
                24955: {"name": "Mens Boarded 1",       "type": "mens",   "level": 8},
            },
            1473: {
                13447: {"name": "Womens Boarded 1",     "type": "womens", "level": 1},
                13448: {"name": "Womens Boarded 2",     "type": "womens", "level": 2},
                13449: {"name": "Womens Boarded 3",     "type": "womens", "level": 3},
                34999: {"name": "Womens Boarded 4",     "type": "womens", "level": 4},
                10220: {"name": "Womens Masters Boarded","type": "womens", "level": 5},
                34995: {"name": "Womens Boarded 5",     "type": "womens", "level": 6},
                14019: {"name": "Womens Turf 1",        "type": "womens", "level": 7},
                24957: {"name": "Womens Legends Turf 1", "type": "womens", "level": 8},
            },
            3973: {
                35862: {"name": "Mens Futsal 1",        "type": "mens",   "level": 9},
                35863: {"name": "Womens Futsal 1",      "type": "womens", "level": 9},
            },
        },
    },
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


def get_club(slug: str) -> dict:
    """Return club config dict, raising error if not found."""
    if slug not in CLUBS:
        raise ValueError(f"Unknown club '{slug}'. Valid: {list(CLUBS)}")
    return CLUBS[slug]


def get_all_divisions(club: dict) -> dict:
    """Flatten categories → divisions into {div_id: {name, type, level, catid}}."""
    result = {}
    for catid, divisions in club["categories"].items():
        for div_id, div_info in divisions.items():
            result[div_id] = {**div_info, "catid": catid}
    return result
