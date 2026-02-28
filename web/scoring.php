<?php
$page_title = 'Scoring Guide — CSDC Discipline Index';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
<div class="mb-6">
    <h1 class="text-3xl font-bold text-primary">Discipline Scoring Guide</h1>
    <p class="mt-2 text-gray-600">How the FC Regina Misconduct Tracker calculates the CSDC-based Discipline Index for teams and players.</p>
</div>

<!-- Overview -->
<section class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-3">What is the Discipline Index?</h2>
    <p class="text-gray-700 mb-3">
        The <strong>Discipline Index</strong> is a weighted misconduct score that accounts for the <em>severity</em>
        of each card, not just the count. A procedural yellow for "Delaying the Restart" is fundamentally different
        from a red card for "Violent Conduct" — the Discipline Index reflects that distinction.
    </p>
    <p class="text-gray-700">
        For <strong>teams</strong>, the score is expressed as weighted points per game, enabling fair comparison
        across teams that have played different numbers of matches. For <strong>individual players</strong>,
        the score is the cumulative total weight of all their cards.
    </p>
</section>

<!-- Weight table -->
<section class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Canadian Soccer Disciplinary Code — Card Weights</h2>
    <p class="text-sm text-gray-500 mb-5">
        The CSDC classifies misconduct into categories A (most severe) through E (yellow cards).
        These weights are calibrated to that framework, with higher scores reflecting greater risk
        to player safety and league culture.
    </p>

    <!-- Yellow cards -->
    <h3 class="text-base font-semibold text-amber-700 mb-2 flex items-center gap-2">
        <span class="inline-block w-4 h-4 bg-amber-400 rounded-sm shrink-0"></span>
        Yellow Cards — Category E
    </h3>
    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="bg-amber-50">
                    <th class="px-4 py-2 text-left border border-amber-200 font-semibold">Reason</th>
                    <th class="px-4 py-2 text-center border border-amber-200 font-semibold w-20">Weight</th>
                    <th class="px-4 py-2 text-left border border-amber-200 font-semibold hidden sm:table-cell">Rationale</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-4 py-2.5 border border-amber-100 font-medium">Dissent by word or action</td>
                    <td class="px-4 py-2.5 text-center border border-amber-100">
                        <span class="text-xl font-bold text-amber-700">2.5</span>
                    </td>
                    <td class="px-4 py-2.5 border border-amber-100 text-gray-500 text-xs hidden sm:table-cell">
                        Challenges official authority; most serious Category E offence
                    </td>
                </tr>
                <tr class="bg-amber-50/40">
                    <td class="px-4 py-2.5 border border-amber-100 font-medium">Unsporting Behavior</td>
                    <td class="px-4 py-2.5 text-center border border-amber-100">
                        <span class="text-xl font-bold text-amber-600">2.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-amber-100 text-gray-500 text-xs hidden sm:table-cell">
                        Simulation, taunting, handling — broad behavior violation
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2.5 border border-amber-100 font-medium">Persistent Infringement</td>
                    <td class="px-4 py-2.5 text-center border border-amber-100">
                        <span class="text-xl font-bold text-amber-500">1.5</span>
                    </td>
                    <td class="px-4 py-2.5 border border-amber-100 text-gray-500 text-xs hidden sm:table-cell">
                        Pattern/tactical fouling; deliberate repeated infringement
                    </td>
                </tr>
                <tr class="bg-amber-50/40">
                    <td class="px-4 py-2.5 border border-amber-100 font-medium">Procedural (Delay, Distance, Entry)</td>
                    <td class="px-4 py-2.5 text-center border border-amber-100">
                        <span class="text-xl font-bold text-gray-500">1.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-amber-100 text-gray-500 text-xs hidden sm:table-cell">
                        Game-management only; no threat to safety or culture
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Red cards -->
    <h3 class="text-base font-semibold text-red-700 mb-2 flex items-center gap-2">
        <span class="inline-block w-4 h-4 bg-red-600 rounded-sm shrink-0"></span>
        Red Cards — Categories A–D
    </h3>
    <div class="overflow-x-auto mb-5">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="bg-red-50">
                    <th class="px-4 py-2 text-left border border-red-200 font-semibold">Reason</th>
                    <th class="px-4 py-2 text-center border border-red-200 font-semibold w-20">Weight</th>
                    <th class="px-4 py-2 text-left border border-red-200 font-semibold hidden sm:table-cell">CSDC Category</th>
                </tr>
            </thead>
            <tbody>
                <tr class="bg-red-50/60">
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Violent Conduct</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-red-700">9.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Category A — endangers others; maximum severity
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Spitting at a Person</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-red-700">7.5</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Category A variant — morally reprehensible
                    </td>
                </tr>
                <tr class="bg-red-50/60">
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Foul &amp; Abusive Language / Abuse of Official</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-red-600">7.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Category D — undermines official authority
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Serious Foul Play</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-red-600">6.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Category B — dangerous challenge; threat to player safety
                    </td>
                </tr>
                <tr class="bg-red-50/60">
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Denying Obvious Goal-Scoring Opportunity (DOGSO)</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-orange-600">4.5</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Category C — tactical/professional foul
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-2.5 border border-red-100 font-medium">Two-Yellow Ejection (Second Caution)</td>
                    <td class="px-4 py-2.5 text-center border border-red-100">
                        <span class="text-xl font-bold text-orange-500">3.0</span>
                    </td>
                    <td class="px-4 py-2.5 border border-red-100 text-gray-500 text-xs hidden sm:table-cell">
                        Accumulated cautions; lowest red card severity
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bench multiplier callout -->
    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
        <div class="font-semibold text-orange-700 mb-1">Bench Penalty Multiplier: ×1.5</div>
        <p class="text-sm text-gray-600">
            Cards issued to the bench (coach, staff, or sideline conduct) carry a 1.5× multiplier.
            The bench being carded signals a team-culture failure — not an individual lapse on the pitch —
            and carries greater organizational risk than an equivalent on-field incident.
        </p>
    </div>
</section>

<!-- Formulas -->
<section class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Formulas</h2>

    <div class="grid md:grid-cols-2 gap-5">
        <div>
            <h3 class="font-semibold text-gray-700 mb-2">Team Discipline Index (per game)</h3>
            <div class="bg-gray-50 border border-gray-200 rounded p-4 font-mono text-sm leading-relaxed">
                <div class="text-gray-400 text-xs mb-1">// Per card:</div>
                <div>w = base_weight(reason, card_type)</div>
                <div>w *= 1.5 <span class="text-gray-400">// if Bench Penalty</span></div>
                <div class="mt-2 text-gray-400 text-xs mb-1">// Team score:</div>
                <div>Index = Σ(w) ÷ games_played</div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                Dividing by games played ensures teams with more matches aren't unfairly
                penalized — the score reflects misconduct <em>rate</em>, not volume.
            </p>
        </div>
        <div>
            <h3 class="font-semibold text-gray-700 mb-2">Player Danger Score (cumulative)</h3>
            <div class="bg-gray-50 border border-gray-200 rounded p-4 font-mono text-sm leading-relaxed">
                <div class="text-gray-400 text-xs mb-1">// Per card:</div>
                <div>w = base_weight(reason, card_type)</div>
                <div class="mt-2 text-gray-400 text-xs mb-1">// Player score:</div>
                <div>Danger = Σ(w)</div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                No game normalization for players — we only track misconduct games,
                not total appearances. A violent conduct red (9.0) correctly outranks
                five procedural yellows (5.0), reflecting qualitative severity.
            </p>
        </div>
    </div>
</section>

<!-- Thresholds -->
<section class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Score Thresholds</h2>

    <div class="grid md:grid-cols-2 gap-5">
        <!-- Team -->
        <div>
            <h3 class="font-semibold text-gray-600 text-sm uppercase tracking-wide mb-3">Team (per game)</h3>
            <div class="space-y-2">
                <div class="flex items-center gap-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-green-600">&lt; 1.0</span></div>
                    <div>
                        <div class="font-semibold text-green-700 text-sm">Clean</div>
                        <div class="text-xs text-gray-500">Infrequent or procedural cards only</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-amber-600">1–2.5</span></div>
                    <div>
                        <div class="font-semibold text-amber-700 text-sm">Elevated</div>
                        <div class="text-xs text-gray-500">Behavioral cards or moderate frequency</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-red-600">&gt; 2.5</span></div>
                    <div>
                        <div class="font-semibold text-red-700 text-sm">High Risk</div>
                        <div class="text-xs text-gray-500">Red cards, bench penalties, or persistent behavioral issues</div>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                Calibrated against league data: clean teams ≈ 0.5–1.0/game, behavioral teams 1.5–2.5/game, red card incidents 3.0+/game.
            </p>
        </div>
        <!-- Player -->
        <div>
            <h3 class="font-semibold text-gray-600 text-sm uppercase tracking-wide mb-3">Player (cumulative)</h3>
            <div class="space-y-2">
                <div class="flex items-center gap-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-green-600">&lt; 3.0</span></div>
                    <div>
                        <div class="font-semibold text-green-700 text-sm">Low Risk</div>
                        <div class="text-xs text-gray-500">1–2 minor cards, no serious misconduct</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-amber-600">3–7</span></div>
                    <div>
                        <div class="font-semibold text-amber-700 text-sm">Moderate Risk</div>
                        <div class="text-xs text-gray-500">Multiple or behavioral cards; suspension threshold likely triggered</div>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                    <div class="w-16 text-center shrink-0"><span class="text-lg font-bold text-red-600">&gt; 7.0</span></div>
                    <div>
                        <div class="font-semibold text-red-700 text-sm">High Risk</div>
                        <div class="text-xs text-gray-500">Red cards, persistent behavioral history, or high-severity incidents</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Why not just count cards? -->
<section class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-3">Why Not Just Count Cards?</h2>
    <p class="text-gray-700 mb-4">
        Raw card counts treat all misconducts as equivalent. Consider two teams, each with 5 cards:
    </p>
    <div class="grid md:grid-cols-2 gap-4 mb-4">
        <div class="border border-amber-200 rounded-lg overflow-hidden">
            <div class="bg-amber-50 px-4 py-2 font-semibold text-amber-700 text-sm">Team A — 5 cards</div>
            <div class="p-4 text-sm space-y-1.5">
                <div class="flex justify-between">
                    <span class="text-gray-600">3× Delaying Restart</span>
                    <span class="font-mono text-gray-500">3 × 1.0 = 3.0</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">2× Unsporting Behavior</span>
                    <span class="font-mono text-gray-500">2 × 2.0 = 4.0</span>
                </div>
                <div class="flex justify-between font-semibold border-t border-gray-100 pt-1.5 mt-1">
                    <span>Total weight</span>
                    <span class="text-amber-600">7.0</span>
                </div>
            </div>
        </div>
        <div class="border border-red-200 rounded-lg overflow-hidden">
            <div class="bg-red-50 px-4 py-2 font-semibold text-red-700 text-sm">Team B — 5 cards</div>
            <div class="p-4 text-sm space-y-1.5">
                <div class="flex justify-between">
                    <span class="text-gray-600">1× Violent Conduct red</span>
                    <span class="font-mono text-gray-500">1 × 9.0 = 9.0</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">1× Foul &amp; Abusive red</span>
                    <span class="font-mono text-gray-500">1 × 7.0 = 7.0</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">1× Bench Yellow</span>
                    <span class="font-mono text-gray-500">1.0 × 1.5 = 1.5</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">2× Dissent</span>
                    <span class="font-mono text-gray-500">2 × 2.5 = 5.0</span>
                </div>
                <div class="flex justify-between font-semibold border-t border-gray-100 pt-1.5 mt-1">
                    <span>Total weight</span>
                    <span class="text-red-600">22.5</span>
                </div>
            </div>
        </div>
    </div>
    <p class="text-sm text-gray-600">
        Same card count, very different risk profiles. The Discipline Index captures this distinction —
        Team B presents a significantly more serious threat to player safety and league culture.
    </p>
</section>

<div class="flex gap-4 text-sm mb-8">
    <a href="index.php?view=teams" class="text-primary hover:underline">&larr; Team Discipline Rankings</a>
    <a href="division.php" class="text-primary hover:underline">Division Analysis</a>
</div>

</div>
</main>
</body>
</html>
