#!/usr/bin/env python3
"""
generate_report.py — Executive Discipline PDF for FC Regina Indoor Soccer

Usage:
    python generate_report.py [--division_id 35378] [--output path/to/file.pdf]
"""

import argparse
import io
import os
import sqlite3
import sys
from datetime import date, datetime

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
import numpy as np

from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.pdfgen import canvas as rl_canvas
from reportlab.lib.utils import ImageReader

# ── Paths ──────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH    = os.path.join(SCRIPT_DIR, '..', 'data', 'cards.db')

# ── Page geometry ──────────────────────────────────────────────────────────
PAGE_W, PAGE_H = A4          # 595.27 × 841.89 pt
MARGIN = 36                   # 12.7 mm
CW = PAGE_W - 2 * MARGIN     # ≈ 523 pt content width

# ── Brand colours (ReportLab) ──────────────────────────────────────────────
C_PRIMARY   = colors.HexColor('#2d6a4f')
C_ACCENT    = colors.HexColor('#52b788')
C_AMBER     = colors.HexColor('#d97706')
C_RED       = colors.HexColor('#dc2626')
C_GREEN     = colors.HexColor('#22c55e')
C_INDIGO    = colors.HexColor('#6366f1')
C_ORANGE    = colors.HexColor('#f97316')
C_LGRAY     = colors.HexColor('#f3f4f6')
C_MGRAY     = colors.HexColor('#9ca3af')
C_DGRAY     = colors.HexColor('#374151')
C_WHITE     = colors.white
C_BLACK     = colors.HexColor('#111827')
C_AMBER_BG  = colors.HexColor('#fef3c7')
C_RED_BG    = colors.HexColor('#fee2e2')
C_GREEN_BG  = colors.HexColor('#f0fdf4')
C_ORANGE_BG = colors.HexColor('#fff7ed')
C_RULE      = colors.HexColor('#e5e7eb')

# ── Offense category → colour mapping ─────────────────────────────────────
CATEGORY_COLORS = {
    'Dissent':                 C_AMBER,
    'Unsporting Behaviour':    C_ORANGE,
    'Two-Yellow Ejection':     C_AMBER,
    'Violent Conduct':         C_RED,
    'Serious Foul Play':       C_RED,
    'Spitting':                C_RED,
    'Abuse of Official':       C_RED,
    'Direct Red':              C_RED,
    'DOGSO':                   C_ORANGE,
    'Procedural':              C_MGRAY,
    'Persistent Infringement': C_MGRAY,
}

# ── Matplotlib hex strings ─────────────────────────────────────────────────
MPL = {
    'primary': '#2d6a4f', 'accent':  '#52b788',
    'amber':   '#d97706', 'red':     '#dc2626',
    'green':   '#22c55e', 'indigo':  '#6366f1',
    'orange':  '#f97316', 'lgray':   '#f3f4f6',
    'dgray':   '#374151', 'mgray':   '#9ca3af',
}

plt.rcParams.update({
    'font.family':     'sans-serif',
    'font.sans-serif': ['DejaVu Sans', 'Arial', 'Helvetica'],
    'figure.facecolor': 'white',
    'axes.facecolor':   'white',
    'axes.spines.top':    False,
    'axes.spines.right':  False,
    'axes.spines.left':   False,
    'axes.grid':          False,
    'xtick.color': MPL['mgray'],
    'ytick.color': MPL['dgray'],
})


# ── Discipline logic ───────────────────────────────────────────────────────

def card_weight(reason: str, card_type: str, player_name: str = '') -> float:
    if card_type == 'Yellow':
        if   'Dissent'    in reason: w = 2.5
        elif 'Unsporting' in reason: w = 2.0
        elif 'Persistent' in reason: w = 1.5
        else:                        w = 1.0
    else:
        if   'Category A'      in reason or 'Violent'          in reason: w = 9.0
        elif 'Spitting'        in reason:                                  w = 7.5
        elif 'Category D'      in reason or 'Foul and Abusive' in reason \
          or 'Abuse'           in reason:                                  w = 7.0
        elif 'Serious Foul'    in reason:                                  w = 6.0
        elif 'Denying Obvious' in reason or 'DOGSO'             in reason: w = 4.5
        elif 'Second Caution'  in reason:                                  w = 3.0
        else:                                                               w = 4.0
    if player_name == 'Bench Penalty':
        w *= 1.5
    return w


def disc_color_mpl(score: float) -> str:
    if score > 2.5:  return MPL['red']
    if score >= 1.0: return MPL['amber']
    return MPL['green']


def disc_color_rl(score: float):
    if score > 2.5:  return C_RED
    if score >= 1.0: return C_AMBER
    return C_GREEN


def yellow_status(yellows: int) -> str:
    if yellows >= 7: return 'Triggered (R7.3)'
    if yellows >= 5: return 'Triggered (R7.2)'
    if yellows >= 3: return 'Triggered (R7.1)'
    if yellows == 2: return 'Warning'
    return 'Clean'


def categorize_reason(reason: str, card_type: str) -> str:
    if card_type == 'Yellow':
        if 'Dissent'    in reason: return 'Dissent'
        if 'Unsporting' in reason: return 'Unsporting Behaviour'
        if 'Persistent' in reason: return 'Persistent Infringement'
        return 'Procedural'
    else:
        if 'Second Caution'   in reason:                                 return 'Two-Yellow Ejection'
        if 'Violent'          in reason or 'Category A' in reason:       return 'Violent Conduct'
        if 'Serious Foul'     in reason:                                 return 'Serious Foul Play'
        if 'Denying Obvious'  in reason or 'DOGSO'      in reason:       return 'DOGSO'
        if 'Spitting'         in reason:                                 return 'Spitting'
        if 'Foul and Abusive' in reason or 'Abuse' in reason \
          or 'Category D'     in reason:                                 return 'Abuse of Official'
        return 'Direct Red'


def fmt_game_date(raw: str) -> str:
    try:
        return datetime.fromisoformat(raw).strftime('%b %d, %Y')
    except Exception:
        return str(raw)[:10]


# ── Database queries ───────────────────────────────────────────────────────

def query_data(div_ext_id: int, club_slug: str | None = None) -> dict:
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row

    div = db.execute("SELECT * FROM divisions WHERE division_id=?", [div_ext_id]).fetchone()
    if not div:
        print(f"ERROR: Division {div_ext_id} not found.", file=sys.stderr)
        sys.exit(1)
    div_pk = div['id']

    # ── KPIs ──────────────────────────────────────────────────────────────
    total_games = db.execute(
        "SELECT COUNT(*) FROM games WHERE division_id=?", [div_pk]
    ).fetchone()[0]

    div_misc = db.execute("""
        SELECT m.team, m.player_name, m.card_type, m.reason,
               g.game_id, g.game_date, g.home_team, g.away_team
        FROM misconducts m JOIN games g ON m.game_id = g.id
        WHERE g.division_id = ?
    """, [div_pk]).fetchall()

    yellows     = sum(1 for m in div_misc if m['card_type'] == 'Yellow')
    reds        = sum(1 for m in div_misc if m['card_type'] == 'Red')
    total_cards = len(div_misc)
    cards_per_game = round(total_cards / max(1, total_games), 2)

    # ── All-division scoring (Chart 1) ────────────────────────────────────
    if club_slug:
        all_divs = db.execute("""
            SELECT d.* FROM divisions d
            JOIN organizations o ON d.org_id = o.id
            WHERE o.slug = ?
            ORDER BY d.type, d.name
        """, [club_slug]).fetchall()
    else:
        # Default: same club as the selected division
        all_divs = db.execute("""
            SELECT d2.* FROM divisions d2
            WHERE d2.org_id = (SELECT org_id FROM divisions WHERE division_id = ?)
            ORDER BY d2.type, d2.name
        """, [div_ext_id]).fetchall()
    div_scores = []
    for d in all_divs:
        gp = db.execute(
            "SELECT COUNT(*) FROM games WHERE division_id=?", [d['id']]
        ).fetchone()[0]
        misc = db.execute("""
            SELECT reason, card_type, player_name FROM misconducts m
            JOIN games g ON m.game_id=g.id WHERE g.division_id=?
        """, [d['id']]).fetchall()
        tw = sum(card_weight(m['reason'], m['card_type'], m['player_name']) for m in misc)
        div_scores.append({
            'name':   d['name'],
            'ext_id': d['division_id'],
            'score':  round(tw / max(1, gp), 2),
            'games':  gp,
        })
    div_scores.sort(key=lambda x: x['score'], reverse=True)
    league_avg = round(
        sum(d['score'] for d in div_scores) / len(div_scores), 2
    ) if div_scores else 0

    # ── Team rankings (Chart 2) ───────────────────────────────────────────
    gp_map: dict = {}
    for row in db.execute("""
        SELECT home_team team, COUNT(*) n FROM games WHERE division_id=? GROUP BY home_team
        UNION ALL
        SELECT away_team, COUNT(*) FROM games WHERE division_id=? GROUP BY away_team
    """, [div_pk, div_pk]).fetchall():
        gp_map[row['team']] = gp_map.get(row['team'], 0) + row['n']

    carded    = {m['team'] for m in div_misc}
    real_teams = carded | {t for t, gp in gp_map.items() if gp >= 5}

    misc_by_team: dict = {}
    for m in div_misc:
        misc_by_team.setdefault(m['team'], []).append(m)

    team_rankings = []
    for team in real_teams:
        gp = max(1, gp_map.get(team, 1))
        ml = misc_by_team.get(team, [])
        tw = sum(card_weight(m['reason'], m['card_type'], m['player_name']) for m in ml)
        team_rankings.append({
            'team':        team,
            'games_played': gp,
            'yellows':     sum(1 for m in ml if m['card_type'] == 'Yellow'),
            'reds':        sum(1 for m in ml if m['card_type'] == 'Red'),
            'total_cards': len(ml),
            'score':       round(tw / gp, 2),
        })
    team_rankings.sort(key=lambda x: x['score'], reverse=True)
    div_avg = round(
        sum(t['score'] for t in team_rankings) / len(team_rankings), 2
    ) if team_rankings else 0

    # ── Worst team offense breakdowns ─────────────────────────────────────
    team_offense_breakdown = {}
    for t in team_rankings[:5]:
        cat_counts: dict = {}
        for m in misc_by_team.get(t['team'], []):
            cat = categorize_reason(m['reason'], m['card_type'])
            cat_counts[cat] = cat_counts.get(cat, 0) + 1
        team_offense_breakdown[t['team']] = sorted(
            cat_counts.items(), key=lambda x: x[1], reverse=True
        )[:3]

    # ── Concern players (with cross-div enrichment) ───────────────────────
    raw_concern = db.execute("""
        SELECT m.player_name, m.team,
               SUM(CASE WHEN m.card_type='Yellow'
                        AND NOT EXISTS(SELECT 1 FROM misconducts m2
                            WHERE m2.game_id=m.game_id
                              AND m2.player_name=m.player_name
                              AND m2.card_type='Red')
                   THEN 1 ELSE 0 END) yellows,
               SUM(CASE WHEN m.card_type='Red' THEN 1 ELSE 0 END) reds
        FROM misconducts m JOIN games g ON m.game_id=g.id
        WHERE g.division_id=? AND m.player_name!='Bench Penalty'
        GROUP BY m.player_name, m.team
        HAVING yellows>=3 OR reds>=1
        ORDER BY reds DESC, yellows DESC
    """, [div_pk]).fetchall()

    concern_players = []
    if raw_concern:
        names = [r['player_name'] for r in raw_concern]
        ph    = ','.join(['?'] * len(names))

        misc_by_player: dict = {}
        for m in div_misc:
            misc_by_player.setdefault(m['player_name'], []).append(m)

        all_misc = db.execute(f"""
            SELECT m.player_name, m.reason, m.card_type, g.division_id,
                   EXISTS(SELECT 1 FROM misconducts m2
                          WHERE m2.game_id=m.game_id
                            AND m2.player_name=m.player_name
                            AND m2.card_type='Red') has_red_in_game
            FROM misconducts m JOIN games g ON m.game_id=g.id
            WHERE m.player_name IN ({ph}) AND m.player_name!='Bench Penalty'
        """, names).fetchall()

        all_misc_by_player: dict = {}
        for m in all_misc:
            all_misc_by_player.setdefault(m['player_name'], []).append(m)

        for r in raw_concern:
            n  = r['player_name']
            dw = sum(card_weight(m['reason'], m['card_type'], m['player_name'])
                     for m in misc_by_player.get(n, []))
            tw = sum(card_weight(m['reason'], m['card_type'], m['player_name'])
                     for m in all_misc_by_player.get(n, []))
            all_y = sum(
                1 for m in all_misc_by_player.get(n, [])
                if m['card_type'] == 'Yellow' and not m['has_red_in_game']
            )
            all_r = sum(
                1 for m in all_misc_by_player.get(n, []) if m['card_type'] == 'Red'
            )
            div_count = len({m['division_id'] for m in all_misc_by_player.get(n, [])})
            concern_players.append({
                'player_name':   n,
                'team':          r['team'],
                'yellows':       r['yellows'],
                'reds':          r['reds'],
                'div_weight':    round(dw, 1),
                'other_weight':  round(max(0.0, tw - dw), 1),
                'total_yellows': all_y,
                'total_reds':    all_r,
                'div_count':     div_count,
            })
        concern_players.sort(
            key=lambda x: x['div_weight'] + x['other_weight'], reverse=True
        )

    # ── Top 10 volatile games ─────────────────────────────────────────────
    volatile = db.execute("""
        SELECT g.id AS int_id, g.game_id, g.game_date, g.home_team, g.away_team,
               SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) yellows,
               SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) reds,
               COUNT(m.id) total
        FROM games g JOIN misconducts m ON m.game_id=g.id
        WHERE g.division_id=?
        GROUP BY g.id ORDER BY total DESC, reds DESC LIMIT 10
    """, [div_pk]).fetchall()
    volatile = [dict(v) for v in volatile]

    # Which teams accumulate the most cards across those top-10 games?
    volatile_teams = []
    if volatile:
        int_ids = [v['int_id'] for v in volatile]          # games.id (internal PK)
        ph_v = ','.join(['?'] * len(int_ids))
        rows = db.execute(f"""
            SELECT m.team,
                   COUNT(*) total_cards,
                   SUM(CASE WHEN m.card_type='Yellow' THEN 1 ELSE 0 END) yellows,
                   SUM(CASE WHEN m.card_type='Red'    THEN 1 ELSE 0 END) reds
            FROM misconducts m
            WHERE m.game_id IN ({ph_v})
            GROUP BY m.team ORDER BY total_cards DESC LIMIT 10
        """, int_ids).fetchall()
        volatile_teams = [dict(r) for r in rows]

    db.close()

    return {
        'div':                    dict(div),
        'total_games':            total_games,
        'card_stats':             {'yellows': yellows, 'reds': reds, 'total': total_cards},
        'cards_per_game':         cards_per_game,
        'div_scores':             div_scores,
        'league_avg':             league_avg,
        'team_rankings':          team_rankings,
        'div_avg':                div_avg,
        'team_offense_breakdown': team_offense_breakdown,
        'concern_players':        concern_players,
        'volatile':               volatile,
        'volatile_teams':         volatile_teams,
    }


# ── Matplotlib chart generators ────────────────────────────────────────────

def chart_to_png(fig) -> bytes:
    buf = io.BytesIO()
    fig.savefig(buf, format='png', dpi=140, bbox_inches='tight',
                facecolor='white', edgecolor='none')
    plt.close(fig)
    buf.seek(0)
    return buf.read()


def make_chart1(div_scores, highlighted_ext_id, league_avg) -> bytes:
    """All-division scoring index — horizontal bar."""
    labels = [d['name'] for d in div_scores]
    scores = [d['score'] for d in div_scores]
    bar_colors = [
        MPL['indigo'] if d['ext_id'] == highlighted_ext_id else disc_color_mpl(d['score'])
        for d in div_scores
    ]

    fig, ax = plt.subplots(figsize=(9.0, 4.5))
    bars = ax.barh(labels, scores, color=bar_colors, height=0.62, zorder=3)
    for bar, score in zip(bars, scores):
        ax.text(score + 0.03, bar.get_y() + bar.get_height() / 2,
                f'{score:.2f}', va='center', ha='left', fontsize=8,
                color=MPL['dgray'])
    if league_avg > 0:
        ax.axvline(league_avg, color=MPL['mgray'], linestyle='--', linewidth=1.4, zorder=4)
        ax.text(league_avg + 0.04, len(labels) - 0.5,
                f'League avg {league_avg:.2f}', fontsize=7.5, color=MPL['mgray'], va='top')
    ax.set_xlim(0, max(scores) * 1.3 + 0.3 if scores else 1)
    ax.invert_yaxis()
    ax.set_xlabel('Discipline score (pts / game)', fontsize=8.5, color=MPL['mgray'])
    ax.tick_params(axis='y', labelsize=9, pad=4)
    ax.tick_params(axis='x', labelsize=8)
    ax.spines['bottom'].set_color(MPL['lgray'])
    ax.grid(axis='x', color=MPL['lgray'], linewidth=0.8, zorder=0)
    ax.set_axisbelow(True)
    plt.tight_layout(pad=0.5)
    return chart_to_png(fig)


def make_chart2(team_rankings, div_avg) -> bytes:
    """Team discipline scores — horizontal bar."""
    labels = [t['team'] for t in team_rankings]
    scores = [t['score'] for t in team_rankings]
    bar_colors = [disc_color_mpl(s) for s in scores]

    fig_h = max(2.5, len(labels) * 0.42 + 0.8)
    fig, ax = plt.subplots(figsize=(9.0, fig_h))
    bars = ax.barh(labels, scores, color=bar_colors, height=0.58, zorder=3)
    for bar, score in zip(bars, scores):
        ax.text(score + 0.03, bar.get_y() + bar.get_height() / 2,
                f'{score:.2f}', va='center', ha='left', fontsize=8,
                color=MPL['dgray'])
    if div_avg > 0:
        ax.axvline(div_avg, color=MPL['mgray'], linestyle='--', linewidth=1.4, zorder=4)
        ax.text(div_avg + 0.04, len(labels) - 0.5,
                f'Div avg {div_avg:.2f}', fontsize=7.5, color=MPL['mgray'], va='top')
    ax.set_xlim(0, max(scores) * 1.3 + 0.2 if scores else 1)
    ax.invert_yaxis()
    ax.set_xlabel('Discipline score (pts / game)', fontsize=8.5, color=MPL['mgray'])
    ax.tick_params(axis='y', labelsize=9, pad=4)
    ax.tick_params(axis='x', labelsize=8)
    ax.spines['bottom'].set_color(MPL['lgray'])
    ax.grid(axis='x', color=MPL['lgray'], linewidth=0.8, zorder=0)
    ax.set_axisbelow(True)
    plt.tight_layout(pad=0.5)
    return chart_to_png(fig)


def make_chart3(concern_players, div_name, max_players=12) -> bytes:
    """Player risk — stacked horizontal bar (div pts + other-div pts)."""
    players  = concern_players[:max_players]
    # Label: "Player Name (Team)" — truncated for readability
    labels   = [f"{p['player_name'][:18]} ({p['team'][:12]})" for p in players]
    div_pts  = [p['div_weight']   for p in players]
    oth_pts  = [p['other_weight'] for p in players]

    fig_h = max(3.0, len(players) * 0.42 + 0.9)
    fig, ax = plt.subplots(figsize=(8.4, fig_h))

    ax.barh(labels, div_pts, color=MPL['indigo'], height=0.58,
            label=f'{div_name}', zorder=3)
    ax.barh(labels, oth_pts, left=div_pts, color=MPL['orange'], height=0.58,
            label='Other divisions', zorder=3)

    for i, (dp, op) in enumerate(zip(div_pts, oth_pts)):
        total = dp + op
        if total > 0:
            ax.text(total + 0.1, i, f'{total:.1f}', va='center', ha='left',
                    fontsize=8, color=MPL['dgray'])

    ax.invert_yaxis()
    ax.set_xlabel('Weighted discipline points  (see Appendix, page 3)',
                  fontsize=8.5, color=MPL['mgray'])
    ax.tick_params(axis='y', labelsize=9, pad=4)
    ax.tick_params(axis='x', labelsize=8)
    ax.spines['bottom'].set_color(MPL['lgray'])
    ax.grid(axis='x', color=MPL['lgray'], linewidth=0.8, zorder=0)
    ax.set_axisbelow(True)
    ax.legend(loc='lower right', fontsize=8.5, framealpha=0.92,
              edgecolor=MPL['lgray'], handlelength=1.1, handletextpad=0.5)
    plt.tight_layout(pad=0.5)
    return chart_to_png(fig)


def make_volatile_teams_chart(volatile_teams) -> bytes:
    """Stacked horizontal bar: cards per team across the top-10 volatile games."""
    if not volatile_teams:
        return None

    teams   = [t['team'][:22] for t in volatile_teams]
    yellows = [t['yellows']    for t in volatile_teams]
    reds    = [t['reds']       for t in volatile_teams]

    fig_h = max(2.8, len(teams) * 0.38 + 0.8)
    fig, ax = plt.subplots(figsize=(4.2, fig_h))

    ax.barh(teams, yellows, color=MPL['amber'], height=0.58, label='Yellow cards', zorder=3)
    ax.barh(teams, reds, left=yellows, color=MPL['red'], height=0.58,
            label='Red cards', zorder=3)

    for i, (y_val, r_val) in enumerate(zip(yellows, reds)):
        total = y_val + r_val
        ax.text(total + 0.15, i, str(total), va='center', ha='left',
                fontsize=8, color=MPL['dgray'])

    ax.invert_yaxis()
    ax.set_xlabel('Cards accumulated in volatile games', fontsize=8, color=MPL['mgray'])
    ax.tick_params(axis='y', labelsize=9, pad=4)
    ax.tick_params(axis='x', labelsize=8)
    ax.spines['bottom'].set_color(MPL['lgray'])
    ax.grid(axis='x', color=MPL['lgray'], linewidth=0.8, zorder=0)
    ax.set_axisbelow(True)
    ax.legend(loc='lower right', fontsize=8, framealpha=0.92,
              edgecolor=MPL['lgray'], handlelength=1)
    plt.tight_layout(pad=0.5)
    return chart_to_png(fig)


# ── ReportLab drawing helpers ──────────────────────────────────────────────

def embed_chart(c, png_bytes: bytes, x, y_bottom, w, h, stretch=False):
    img = ImageReader(io.BytesIO(png_bytes))
    c.drawImage(img, x, y_bottom, width=w, height=h,
                preserveAspectRatio=not stretch, anchor='nw', mask='auto')


def draw_header(c, div_name: str, is_compact: bool = False) -> float:
    """Full-bleed green header. Returns y of the bottom edge."""
    h = 40 if is_compact else 74

    c.setFillColor(C_PRIMARY)
    c.rect(0, PAGE_H - h, PAGE_W, h, fill=1, stroke=0)

    c.setFillColor(C_ACCENT)
    c.rect(0, PAGE_H - h, 5, h, fill=1, stroke=0)

    c.setFillColor(C_WHITE)
    if is_compact:
        c.setFont('Helvetica-Bold', 10.5)
        c.drawString(MARGIN, PAGE_H - 25, 'FC Regina Indoor Soccer')
        c.setFont('Helvetica', 9.5)
        c.setFillColor(colors.HexColor('#a7f3d0'))
        c.drawString(MARGIN + 168, PAGE_H - 25, f'  ·  {div_name} — Discipline Report')
        c.setFont('Helvetica', 7.5)
        c.setFillColor(colors.HexColor('#6ee7b7'))
        c.drawRightString(PAGE_W - MARGIN, PAGE_H - 25,
                          date.today().strftime('%B %d, %Y'))
    else:
        c.setFont('Helvetica-Bold', 19)
        c.drawString(MARGIN + 8, PAGE_H - 32, 'FC Regina Indoor Soccer')
        c.setFont('Helvetica', 12)
        c.setFillColor(colors.HexColor('#a7f3d0'))
        c.drawString(MARGIN + 8, PAGE_H - 50, f'{div_name}  ·  Season Discipline Report')
        c.setFont('Helvetica', 8.5)
        c.setFillColor(colors.HexColor('#6ee7b7'))
        c.drawRightString(PAGE_W - MARGIN, PAGE_H - 30,
                          f'Season 2025/26  ·  {date.today().strftime("%B %d, %Y")}')

    return PAGE_H - h


def draw_section_heading(c, text: str, y: float) -> float:
    """Bold section label + rule. Returns y below."""
    c.setFont('Helvetica-Bold', 10.5)
    c.setFillColor(C_DGRAY)
    c.drawString(MARGIN, y, text)
    tw = c.stringWidth(text, 'Helvetica-Bold', 10.5)
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.5)
    c.line(MARGIN + tw + 7, y + 4, MARGIN + CW, y + 4)
    return y - 14


def draw_appendix_note(c, y: float) -> float:
    """Small italic note pointing to appendix. Returns y below."""
    c.setFont('Helvetica-Oblique', 7.5)
    c.setFillColor(C_MGRAY)
    c.drawString(MARGIN, y, 'Scoring methodology defined in Appendix (page 3).')
    return y - 13


def draw_footer(c, page_num: int):
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.5)
    c.line(MARGIN, 28, MARGIN + CW, 28)
    c.setFillColor(C_MGRAY)
    c.setFont('Helvetica', 7.5)
    c.drawString(MARGIN, 16, 'FC Regina Indoor Soccer  ·  Season 2025/26 Discipline Report')
    c.drawRightString(MARGIN + CW, 16, f'Page {page_num}')


def draw_kpi_strip(c, total_games, card_stats, cards_per_game, y_top: float) -> float:
    GAP   = 8
    box_w = (CW - 3 * GAP) / 4
    box_h = 62
    y     = y_top - 14

    metrics = [
        ('Games Played', str(total_games),          colors.HexColor('#3b82f6'), colors.HexColor('#eff6ff')),
        ('Cards / Game', str(cards_per_game),        C_PRIMARY,                 colors.HexColor('#f0fdf4')),
        ('Yellow Cards', str(card_stats['yellows']), C_AMBER,                   C_AMBER_BG),
        ('Red Cards',    str(card_stats['reds']),    C_RED,                     C_RED_BG),
    ]

    for i, (label, value, border_col, bg_col) in enumerate(metrics):
        x = MARGIN + i * (box_w + GAP)
        c.setFillColor(bg_col)
        c.setStrokeColor(C_RULE)
        c.setLineWidth(0.5)
        c.roundRect(x, y - box_h, box_w, box_h, 4, fill=1, stroke=1)
        c.setFillColor(border_col)
        c.rect(x, y - box_h, 4, box_h, fill=1, stroke=0)
        c.setFont('Helvetica-Bold', 26)
        c.drawString(x + 10, y - box_h + 34, value)
        c.setFillColor(C_MGRAY)
        c.setFont('Helvetica', 8)
        c.drawString(x + 10, y - box_h + 19, label)

    return y - box_h


def draw_volatile_section(c, volatile, teams_chart_png, y_top: float) -> float:
    """
    2-column layout:
      Left  — stacked bar chart of teams by cards in volatile games
      Right — ranked textual list of the top-10 most violent games
    """
    if not volatile:
        c.setFont('Helvetica-Oblique', 9)
        c.setFillColor(C_MGRAY)
        c.drawString(MARGIN, y_top - 14, 'No multi-card games recorded in this division.')
        return y_top - 30

    # Size the section to fit the textual list
    ROW_H    = 17
    LIST_HDR = 14
    list_h   = LIST_HDR + len(volatile) * ROW_H + 4
    chart_h  = list_h  # match heights so columns align

    chart_w = CW * 0.44
    list_w  = CW - chart_w - 10
    list_x  = MARGIN + chart_w + 10

    # ── Left: teams chart ─────────────────────────────────────────────────
    if teams_chart_png:
        c.setFont('Helvetica-Bold', 7.5)
        c.setFillColor(C_MGRAY)
        c.drawString(MARGIN, y_top - 2, 'MOST CARDED TEAMS (TOP-10 VOLATILE GAMES)')
        embed_chart(c, teams_chart_png, MARGIN, y_top - chart_h, chart_w, chart_h - LIST_HDR)

    # ── Right: ranked game list ───────────────────────────────────────────
    c.setFont('Helvetica-Bold', 7.5)
    c.setFillColor(C_MGRAY)
    c.drawString(list_x, y_top - 2, f'TOP {len(volatile)} GAMES BY CARD COUNT')

    # Column x-positions within the right panel
    cx_rank  = list_x
    cx_date  = list_x + 18
    cx_teams = list_x + 74
    cx_y     = list_x + list_w - 50
    cx_r     = list_x + list_w - 26

    # Column headers
    y_row = y_top - LIST_HDR
    c.setFont('Helvetica-Bold', 7)
    c.setFillColor(C_MGRAY)
    c.drawString(cx_date,  y_row, 'Date')
    c.drawString(cx_teams, y_row, 'Matchup')
    c.drawString(cx_y,     y_row, 'Y')
    c.drawString(cx_r,     y_row, 'R')
    y_row -= 2
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.4)
    c.line(list_x, y_row, list_x + list_w, y_row)
    y_row -= ROW_H + 2

    for i, g in enumerate(volatile):
        # Alternating row tint
        if i % 2 == 0:
            c.setFillColor(colors.HexColor('#f9fafb'))
            c.rect(list_x - 2, y_row - 1, list_w + 4, ROW_H, fill=1, stroke=0)

        y_text = y_row + ROW_H - 12

        # Rank
        c.setFillColor(C_MGRAY)
        c.setFont('Helvetica-Bold', 7.5)
        c.drawString(cx_rank, y_text, f'{i+1}.')

        # Date
        c.setFillColor(C_DGRAY)
        c.setFont('Helvetica', 7.5)
        c.drawString(cx_date, y_text, fmt_game_date(g['game_date']))

        # Teams — truncated to fit
        home = g['home_team'][:17]
        away = g['away_team'][:17]
        matchup = f'{home} vs {away}'
        avail_w = cx_y - cx_teams - 4
        while (c.stringWidth(matchup, 'Helvetica-Bold', 8) > avail_w
               and len(home) + len(away) > 8):
            if len(home) >= len(away):
                home = home[:-1]
            else:
                away = away[:-1]
            matchup = f'{home}… vs {away}…'
        c.setFillColor(C_BLACK)
        c.setFont('Helvetica-Bold', 8)
        c.drawString(cx_teams, y_text, f'{home} vs {away}')

        # Y / R counts with colour
        c.setFillColor(C_AMBER)
        c.setFont('Helvetica-Bold', 8)
        c.drawString(cx_y, y_text, str(g['yellows']))
        c.setFillColor(C_RED)
        c.drawString(cx_r, y_text, str(g['reds']))

        y_row -= ROW_H

    return y_top - chart_h - 8


def draw_worst_team_callouts(c, team_rankings: list, team_offense_breakdown: dict,
                             y_top: float) -> float:
    """
    Grid of up to 5 cards (3 per row): each shows rank, team name, score,
    raw card counts, and top-3 offense categories with coloured bullet dots.
    Returns y below the section.
    """
    teams = team_rankings[:5]
    if not teams:
        return y_top

    CARD_W   = (CW - 8 * 2) / 3   # ≈ 169 pt
    CARD_H   = 70
    CARD_GAP = 8
    ROW_GAP  = 8
    ACCENT_W = 4

    def draw_card(cx, cy, rank, team_data):
        score = team_data['score']
        accent_col = disc_color_rl(score)

        # Card background + border
        c.setFillColor(C_LGRAY)
        c.setStrokeColor(C_RULE)
        c.setLineWidth(0.4)
        c.roundRect(cx, cy - CARD_H, CARD_W, CARD_H, 3, fill=1, stroke=1)

        # Left accent bar
        c.setFillColor(accent_col)
        c.rect(cx, cy - CARD_H, ACCENT_W, CARD_H, fill=1, stroke=0)

        ix = cx + ACCENT_W + 6   # content left edge
        content_w = CARD_W - ACCENT_W - 10

        # ── Rank ──────────────────────────────────────────────────────────
        c.setFont('Helvetica', 7)
        c.setFillColor(C_MGRAY)
        c.drawString(ix, cy - 10, f'#{rank}')

        # ── Team name ─────────────────────────────────────────────────────
        name = team_data['team']
        c.setFont('Helvetica-Bold', 8.5)
        max_name_w = content_w - 4
        while c.stringWidth(name, 'Helvetica-Bold', 8.5) > max_name_w and len(name) > 4:
            name = name[:-1]
        if name != team_data['team']:
            name = name[:-1] + '…'
        c.setFillColor(C_BLACK)
        c.drawString(ix, cy - 21, name)

        # ── Score + raw counts ────────────────────────────────────────────
        score_str = f'{score:.2f}'
        c.setFont('Helvetica-Bold', 8)
        c.setFillColor(accent_col)
        c.drawString(ix, cy - 32, score_str)
        score_w = c.stringWidth(score_str, 'Helvetica-Bold', 8)

        c.setFont('Helvetica', 7)
        c.setFillColor(C_MGRAY)
        raw_str = f"  {team_data['yellows']}Y  {team_data['reds']}R"
        c.drawString(ix + score_w, cy - 32, raw_str)

        # ── Thin rule ─────────────────────────────────────────────────────
        rule_y = cy - 37
        c.setStrokeColor(C_RULE)
        c.setLineWidth(0.4)
        c.line(ix, rule_y, cx + CARD_W - 6, rule_y)

        # ── Offense categories ────────────────────────────────────────────
        cats = team_offense_breakdown.get(team_data['team'], [])
        cat_y = rule_y - 9
        for cat_name, cat_count in cats:
            dot_col = CATEGORY_COLORS.get(cat_name, C_MGRAY)
            # Coloured bullet
            c.setFillColor(dot_col)
            c.setFont('Helvetica-Bold', 9)
            c.drawString(ix, cat_y, '●')
            # Category name
            c.setFont('Helvetica', 7)
            c.setFillColor(C_DGRAY)
            c.drawString(ix + 9, cat_y, cat_name)
            # Right-aligned count
            c.setFont('Helvetica-Bold', 7)
            c.setFillColor(C_DGRAY)
            c.drawRightString(cx + CARD_W - 6, cat_y, str(cat_count))
            cat_y -= 10

    # Row 1: teams 0-2
    row1_teams = teams[:3]
    row1_y = y_top
    for i, t in enumerate(row1_teams):
        cx = MARGIN + i * (CARD_W + CARD_GAP)
        draw_card(cx, row1_y, i + 1, t)

    y_after_row1 = row1_y - CARD_H - ROW_GAP

    # Row 2: teams 3-4 (centred)
    row2_teams = teams[3:]
    if row2_teams:
        total_row2_w = len(row2_teams) * CARD_W + (len(row2_teams) - 1) * CARD_GAP
        row2_start_x = MARGIN + (CW - total_row2_w) / 2
        row2_y = y_after_row1
        for i, t in enumerate(row2_teams):
            cx = row2_start_x + i * (CARD_W + CARD_GAP)
            draw_card(cx, row2_y, 4 + i, t)
        return row2_y - CARD_H

    return y_after_row1


def draw_appendix(c, y_top: float) -> float:
    """
    Expanded scoring methodology appendix.
    Two columns: yellow-card weights left, red-card weights + thresholds right.
    Bottom strip: risk bands + suspension rules.
    """
    box_h = 162

    c.setFillColor(colors.HexColor('#f8fafc'))
    c.setStrokeColor(colors.HexColor('#e2e8f0'))
    c.setLineWidth(0.5)
    c.roundRect(MARGIN, y_top - box_h, CW, box_h, 4, fill=1, stroke=1)

    # ── Heading ───────────────────────────────────────────────────────────
    c.setFillColor(C_DGRAY)
    c.setFont('Helvetica-Bold', 9)
    c.drawString(MARGIN + 8, y_top - 14, 'HOW THE DISCIPLINE SCORE IS CALCULATED')

    c.setFont('Helvetica', 7.5)
    c.setFillColor(C_MGRAY)
    c.drawString(MARGIN + 8, y_top - 26,
                 'Each misconduct earns severity points (Canadian Soccer Disciplinary Code). '
                 'Total points ÷ games played = Discipline Score (lower is better).')
    c.drawString(MARGIN + 8, y_top - 37,
                 'Bench Penalties carry a 1.5× multiplier — a bench card indicates a team '
                 'culture issue, not a single individual.')

    # Divider between narrative and tables
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.4)
    c.line(MARGIN + 8, y_top - 43, MARGIN + CW - 8, y_top - 43)

    col_mid = MARGIN + CW / 2 + 4
    y_tbl   = y_top - 55

    # ── Left column: Yellow card weights ──────────────────────────────────
    c.setFont('Helvetica-Bold', 8)
    c.setFillColor(C_DGRAY)
    c.drawString(MARGIN + 8, y_tbl, 'Yellow Card Weights')
    y_tbl -= 13

    yellow_rows = [
        ('Delay of restart / required distance / entry without permission', '1.0'),
        ('Persistent infringement (tactical fouling)',                      '1.5'),
        ('Unsporting behaviour',                                            '2.0'),
        ('Dissent by word or action',                                       '2.5'),
    ]
    c.setFont('Helvetica', 7.5)
    for lbl, pts in yellow_rows:
        c.setFillColor(C_DGRAY)
        c.drawString(MARGIN + 8, y_tbl, lbl)
        c.setFillColor(C_AMBER)
        c.setFont('Helvetica-Bold', 7.5)
        c.drawRightString(col_mid - 14, y_tbl, pts)
        c.setFont('Helvetica', 7.5)
        y_tbl -= 12

    # ── Column divider ────────────────────────────────────────────────────
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.4)
    c.line(col_mid - 4, y_top - 50, col_mid - 4, y_top - box_h + 22)

    # ── Right column: Red card weights ────────────────────────────────────
    y_tbl_r = y_top - 55
    c.setFont('Helvetica-Bold', 8)
    c.setFillColor(C_DGRAY)
    c.drawString(col_mid, y_tbl_r, 'Direct Red Card Weights')
    y_tbl_r -= 13

    red_rows = [
        ('Two-Yellow ejection (accumulated cautions)',   '3.0'),
        ('DOGSO — Denying Obvious Goal-Scoring Opp. (Cat. C)', '4.5'),
        ('Serious Foul Play (Cat. B)',                   '6.0'),
        ('Abuse of an Official (Cat. D)',                '7.0'),
        ('Spitting at a person',                         '7.5'),
        ('Violent Conduct (Cat. A)',                     '9.0'),
    ]
    c.setFont('Helvetica', 7.5)
    for lbl, pts in red_rows:
        c.setFillColor(C_DGRAY)
        c.drawString(col_mid, y_tbl_r, lbl)
        c.setFillColor(C_RED)
        c.setFont('Helvetica-Bold', 7.5)
        c.drawRightString(MARGIN + CW - 8, y_tbl_r, pts)
        c.setFont('Helvetica', 7.5)
        y_tbl_r -= 12

    # ── Bottom strip: risk bands + suspension rules ───────────────────────
    strip_y = y_top - box_h + 8
    c.setStrokeColor(C_RULE)
    c.setLineWidth(0.4)
    c.line(MARGIN + 8, strip_y + 14, MARGIN + CW - 8, strip_y + 14)

    c.setFillColor(C_MGRAY)
    c.setFont('Helvetica', 7)
    c.drawString(MARGIN + 8, strip_y + 4,
                 'Suspensions:  3rd Y → 1 match (R7.1)  ·  5th Y → 1 match (R7.2)  '
                 '·  7th+ Y → 1/each (R7.3)  ·  Any red → 1 match automatic')

    return y_top - box_h - 8


# ── PDF composition ────────────────────────────────────────────────────────

def generate_pdf(data: dict, output_path: str):
    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)

    c = rl_canvas.Canvas(output_path, pagesize=A4)
    c.setTitle(f'{data["div"]["name"]} — FC Regina Discipline Report')
    c.setAuthor('FC Regina Indoor Soccer')
    c.setSubject('Season 2025/26 — Discipline Report')

    div_name = data['div']['name']

    # ── Pre-render all charts ──────────────────────────────────────────────
    print('  Generating charts …', flush=True)
    chart1 = make_chart1(data['div_scores'], data['div']['division_id'], data['league_avg'])
    chart2 = make_chart2(data['team_rankings'], data['div_avg']) if data['team_rankings'] else None
    chart3 = make_chart3(data['concern_players'], div_name)      if data['concern_players'] else None
    chart_vt = make_volatile_teams_chart(data['volatile_teams'])

    # ══════════════════════════════════════════════════════════════════════
    # PAGE 1 — Division overview
    # ══════════════════════════════════════════════════════════════════════
    print('  Drawing page 1 …', flush=True)

    y = draw_header(c, div_name, is_compact=False)
    y = draw_kpi_strip(c, data['total_games'], data['card_stats'], data['cards_per_game'], y)
    y -= 18

    y = draw_section_heading(c, 'Division Performance', y)
    y = draw_appendix_note(c, y)
    y -= 6

    # Two stacked charts — labels drawn above each chart
    # Natural heights: each chart fills the full width at its own aspect ratio.
    # figsize width is 9.0in for both; natural height (pt) = fig_h_in * CW / 9.0
    CHART_LABEL_H = 26  # pt reserved above each chart for title + subtitle
    CHART_GAP     = 14  # pt between the two charts
    FIG_W         = 9.0

    chart1_natural = int(4.5 * CW / FIG_W)   # chart1 figsize height = 4.5 in
    c2_fig_h       = max(2.5, len(data['team_rankings']) * 0.42 + 0.8)
    chart2_natural = int(c2_fig_h * CW / FIG_W)

    available = y - 50 - 2 * CHART_LABEL_H - CHART_GAP
    total_natural = chart1_natural + chart2_natural
    if total_natural > available:
        sf = available / total_natural
        chart1_h = max(120, int(chart1_natural * sf))
        chart2_h = max(120, available - chart1_h)
    else:
        chart1_h = chart1_natural
        chart2_h = chart2_natural

    if chart1:
        c.setFont('Helvetica-Bold', 8.5)
        c.setFillColor(C_DGRAY)
        c.drawString(MARGIN, y, 'Division Scoring Index')
        c.setFont('Helvetica', 7.5)
        c.setFillColor(C_MGRAY)
        c.drawString(MARGIN, y - 12, 'All 16 divisions — selected division in indigo  ·  Score = all cards from all players in every game ÷ games played')
        embed_chart(c, chart1, MARGIN, y - CHART_LABEL_H - chart1_h, CW, chart1_h)
        y -= CHART_LABEL_H + chart1_h + CHART_GAP

    if chart2:
        c.setFont('Helvetica-Bold', 8.5)
        c.setFillColor(C_DGRAY)
        c.drawString(MARGIN, y, f'Team Discipline Scores — {div_name}')
        c.setFont('Helvetica', 7.5)
        c.setFillColor(C_MGRAY)
        c.drawString(MARGIN, y - 12, f'Division avg: {data["div_avg"]:.2f} pts/game  ·  Score = that team\'s cards only ÷ games they played')
        embed_chart(c, chart2, MARGIN, y - CHART_LABEL_H - chart2_h, CW, chart2_h)

    draw_footer(c, 1)
    c.showPage()

    # ══════════════════════════════════════════════════════════════════════
    # PAGE 2 — Player risk + volatile games
    # ══════════════════════════════════════════════════════════════════════
    print('  Drawing page 2 …', flush=True)

    y = draw_header(c, div_name, is_compact=True)
    y -= 14

    # ── Worst Teams — Offense Profile ─────────────────────────────────────
    if data['team_offense_breakdown']:
        y = draw_section_heading(c, 'Worst Teams — Offense Profile', y)
        y -= 4
        y = draw_worst_team_callouts(
            c, data['team_rankings'], data['team_offense_breakdown'], y
        )
        y -= 10

    # ── Player Risk Index ──────────────────────────────────────────────────
    y = draw_section_heading(c, 'Player Risk Index', y)
    c.setFont('Helvetica', 7.5)
    c.setFillColor(C_MGRAY)
    c.drawString(MARGIN, y, 'Season total weighted discipline points per player (higher = more severe history). Blue = this division, orange = other divisions.')
    y -= 14

    if chart3:
        n_shown  = min(len(data['concern_players']), 12)
        # Natural height so chart fills full CW width (figsize width = 8.4 in)
        c3_fig_h = max(3.0, n_shown * 0.42 + 0.9)
        # Reserve space for volatile section + footer clearance (dynamic, not a fixed constant)
        volatile_reserve = 0
        if data['volatile']:
            vlist_h = 14 + len(data['volatile']) * 17 + 4   # header + rows + pad
            volatile_reserve = (
                14 + 4          # "Most Volatile Games" section heading + gap
                + vlist_h + 8   # volatile section content
                + 14            # gap applied after chart3 (y -= chart3_h + 14)
            )
        headroom = volatile_reserve + 40    # 40 pt footer clearance
        chart3_h = min(int(y - headroom), int(c3_fig_h * CW / 8.4))
        chart3_h = max(140, chart3_h)
        embed_chart(c, chart3, MARGIN, y - chart3_h, CW, chart3_h)
        y -= (chart3_h + 14)
    else:
        c.setFont('Helvetica-Oblique', 9)
        c.setFillColor(C_MGRAY)
        c.drawString(MARGIN, y - 14,
                     'No players of concern in this division (3+ yellows or 1+ red).')
        y -= 30

    # ── Most Volatile Games ────────────────────────────────────────────────
    if data['volatile']:
        y = draw_section_heading(c, 'Most Volatile Games', y)
        y -= 4
        y = draw_volatile_section(c, data['volatile'], chart_vt, y)

    draw_footer(c, 2)
    c.showPage()

    # ══════════════════════════════════════════════════════════════════════
    # PAGE 3 — Appendix
    # ══════════════════════════════════════════════════════════════════════
    print('  Drawing page 3 …', flush=True)

    y = draw_header(c, div_name, is_compact=True)
    y -= 14
    y = draw_section_heading(c, 'Appendix — Scoring Methodology', y)
    y -= 4
    draw_appendix(c, y)

    draw_footer(c, 3)
    c.showPage()

    c.save()
    print(f'  ✓ Saved → {output_path}', flush=True)


# ── Entry point ────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Generate executive discipline PDF')
    parser.add_argument('--club', type=str, default=None,
                        help='Club slug (e.g. regina, saskatoon). Used to scope the cross-division chart.')
    parser.add_argument('--division_id', type=int, default=35378,
                        help='Division external ID (default 35378 = Mens 2)')
    parser.add_argument('--output', type=str, default=None,
                        help='Output PDF path (default: web/reports/<div>_report.pdf)')
    args = parser.parse_args()

    if args.output is None:
        reports_dir = os.path.normpath(os.path.join(SCRIPT_DIR, '..', 'web', 'reports'))
        os.makedirs(reports_dir, exist_ok=True)
        args.output = os.path.join(reports_dir, '_report.pdf')

    print(f'Querying division {args.division_id} …', flush=True)
    data = query_data(args.division_id, club_slug=args.club)

    if os.path.basename(args.output) == '_report.pdf':
        slug = data['div']['name'].lower().replace(' ', '_')
        args.output = os.path.join(os.path.dirname(args.output), f'{slug}_report.pdf')

    print(f'Division : {data["div"]["name"]}')
    print(f'Games    : {data["total_games"]}')
    print(f'Cards    : {data["card_stats"]["yellows"]}Y  {data["card_stats"]["reds"]}R')
    print(f'Flagged  : {len(data["concern_players"])} players')
    print(f'Volatile : {len(data["volatile"])} games, {len(data["volatile_teams"])} teams')
    print(f'Output   : {args.output}')
    print()

    generate_pdf(data, args.output)


if __name__ == '__main__':
    main()
