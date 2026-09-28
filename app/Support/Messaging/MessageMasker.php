<?php

namespace App\Support\Messaging;

use App\Exceptions\MessageMaskingFailedException;
use IntlChar;
use Normalizer;

/**
 * R134: the one place a message body is masked. Pure — no database, no clock, no I/O — so its fixture
 * table is the whole specification. Before a pair's first completed lesson (invariant #8) emails, URLs
 * (with or without a scheme, bare domains included) and phone numbers are replaced by
 * `PLACEHOLDER`; the caller stores the result and never the original.
 *
 * R143 (cycle 08 r2): rewritten from a pattern-by-pattern list of separator lookalikes (which round 3
 * of the 8b review kept finding gaps in) to a "skeleton" approach — advisor-consulted design, logged in
 * CYCLE-LOG.md, deviations from R143's literal wording disclosed there and in ADR-019:
 *
 *  - The text is first put through NFKC, invisible-format-character stripping (unchanged from the
 *    previous version, except the zero-width joiner is now spared — see below), and then a digit fold
 *    (`foldEnclosedDigits`) that turns single-digit dingbat/double-circled symbols such as "❶"/"➊"/"⓵"
 *    into plain "1" using `IntlChar::getNumericValue()` rather than an enumerated table, so it is not
 *    limited to the handful of code points a reviewer happened to try. Multi-digit forms ("➉" = ten) and
 *    non-integer numeric symbols ("½", Roman numerals) are left alone — a disclosed limit.
 *  - Email/URL/domain detection no longer enumerates "a dot, or one of these lookalikes, or up to five
 *    spaces": it matches on a run of letters/digits, a *general* gap of "whatever is not a letter or a
 *    digit", and another run of letters/digits — so no specific separator character needs to be listed
 *    for the pattern to see through it. Two gap shapes exist:
 *      - EMAIL/MAIL_PROVIDER: once an `@` is present the gap on either side of it, and between domain
 *        labels, may be any non-alnum junk (spaces, brackets, quotes, doubled/bracketed dots) with a
 *        bounded length — the `@` itself is the strong signal, exactly as before, just generalised.
 *        A run of `@` characters collapses to one, so a doubled `@@` (a plain typo or an attempt to
 *        break the local-part/domain boundary) does not defeat the match.
 *      - Bare domains (no `@`): a *loose* gap (whitespace allowed around the non-alnum separator) is
 *        only accepted before a non-word ending (com, net, org, edu, gov, mil, int, info, biz, app, dev,
 *        xyz, pro, mobi, link, page) — endings that are not ordinary English words. A *tight* gap (no
 *        whitespace at all) is required before a word-like ending (online, school, shop, team, chat…)
 *        and before any two-letter country code, exactly as the previous version already required for
 *        country codes. Without this split, "loose" bare-domain matching plus a fully flattened skeleton
 *        would mask ordinary sentences that happen to end in a dictionary word already on the list
 *        ("book shop", "join our team", "have a chat") — the advisor flagged this and it is the reason
 *        the skeleton is not a single fully-flattened string for the whole message. It also resolves
 *        the fixture conflict between "visit mysite . com now" (must mask — R143/round 3) and "Ok.
 *        Online lessons are fine." (must not mask — pre-existing pin): "com" is non-word so the loose,
 *        spaced dot before it matches; "Online" is word-like so it needs a tight dot, and ". " is not
 *        tight.
 *  - Phone-number separators: still "digits, then up to six separator units, then digits", but a unit
 *    is now one Unicode extended grapheme cluster (`\X`) instead of one code point, so a family emoji or
 *    a flag (several code points, one grapheme) spends one unit of the budget instead of several. This
 *    is why the zero-width joiner is no longer stripped before this stage — stripping it first would
 *    pull a ZWJ-sequence emoji apart into several ungrouped code points, each spending its own unit, and
 *    silently re-introduce the round-3 finding. It is otherwise inert (invisible, and excluded from the
 *    separator/letter distinction below either way) so leaving it in the stored text is harmless. A
 *    unit is disqualified — does not count as separator at all, so the run stops — if it is `:` (a time
 *    range such as 16:00-17:00 must survive) or an ordinary letter (`\p{L}` that is not a modifier
 *    letter `\p{Lm}`; modifier letters such as the Arabic tatweel or the katakana prolonged sound mark
 *    are still accepted as separators, as before).
 *
 * Order matters: emails first (so the digits and dots inside an address are consumed with it), then
 * URLs and bare domains, then phone runs. Invalid UTF-8 is scrubbed first, so it cannot be used to slip
 * a number past the patterns. Digits are matched as `\p{Nd}`, which covers Arabic-Indic and Persian
 * digits — PCRE's `\d` does not, even with `/u`.
 *
 * R144 (cycle 08 r3): round 4 of the 8b review found that every junk gap above had a character-count cap
 * (`LOOSE_DOT_JUNK` `{1,3}`, `LABEL_GAP`/`TIGHT_JUNK` `{1,6}`, `AT_GAP` `{0,8}`) — once real punctuation in
 * the message exceeded the cap, the pattern failed to match *at all*, so the address or domain passed
 * through **fully unmasked** rather than over- or under-masked at an edge (`sara@name.......com`, seven
 * dots, was untouched; six dots masked correctly). Every one of those gaps is now unbounded: a junk run of
 * any length still matches, so a gap can never fail open purely because it is long — repeating a separator
 * already present in a gap can only ever widen what gets masked, never defeat the match. This did not by
 * itself need a literal-skeleton rewrite (R143's DEVIATION of 09:15, accepted by R144): the gap patterns
 * already matched "junk between letter/digit runs" without enumerating separators; only their length caps
 * were the bug. Removing a cap on a repeated character class reopens exactly the superlinear-backtracking
 * shape the round-4 review's Finding 2 flagged (`str_repeat('a.', n)`-shaped input against the *unbounded*
 * `NAMED_DOMAIN_LOOSE`/`_TIGHT`/`COUNTRY_DOMAIN` patterns), so every *gap* (`JUNK++`, `\s*+`, `AT_GAP`) is
 * possessive throughout: within a gap nothing overlaps (junk is never whitespace, `@` is excluded from
 * both), so committing to one possessive reading of a gap never throws away a match a greedy reading would
 * have found, and it forecloses the internal, nested-quantifier-shaped backtracking that made a single gap
 * costly to fail on its own.
 *
 * TWO further DEVIATIONs from the design first disclosed in the 15:39 ADVISOR entry, both found empirically
 * (fixture failures, not reasoned in advance — see CYCLE-LOG) rather than by construction, which is why this
 * paragraph states what actually ships rather than what was planned:
 *
 * 1. Domain **label** runs (`[\p{L}\p{Nd}\-]+`) stay greedy, not possessive, in `EMAIL`,
 * `NAMED_DOMAIN_LOOSE`, `NAMED_DOMAIN_TIGHT` and `COUNTRY_DOMAIN`. The label class includes `-`, and so does
 * `JUNK` (a dash is a valid separator, not just valid inside a label — "sara@gmail-com" masking "gmail-com"
 * as one address, dash standing in for the dot, is a fixture (`dash for the dot`), not an edge case). A
 * possessive label run swallows a trailing dash that the *following* gap needed, and can never give it back:
 * `EMAIL` then fails to match the whole address, and a narrower pattern (`MAIL_PROVIDER`) matches only
 * "sara@gmail", leaving "-com" as plain text. Reverting the label run to greedy restores that one-character,
 * non-catastrophic backtrack (a fixed-class run backtracking against a single following gap is linear, not
 * exponential — the class has no internal repeated ambiguity of its own) without reopening Finding 2, whose
 * cost came from the *outer* group's iteration count, not from backtracking inside one label.
 * (R149(b): `NAMED_DOMAIN_LOOSE`, `NAMED_DOMAIN_TIGHT` and `COUNTRY_DOMAIN` no longer share this label class
 * with `JUNK` — see `BARE_DOMAIN_LABEL` below and the R149(b) docblock paragraph further down. Only `EMAIL`
 * keeps `-` in its label class today; the "gmail-com" fixture above is still `EMAIL`'s, unaffected by
 * R149(b), which touches none of `EMAIL`'s constants. The label run stays greedy, not possessive, in all
 * four patterns regardless — R149(b) changed the label *class*, not this quantifier choice.)
 * 2. The local part (`[\p{L}\p{Nd}._%+\-]++`) and the `@` run (`@++`) stay possessive as planned: nothing
 * after them shares their class the way a label shares `-` with `JUNK`, so there is no separator to steal.
 *
 * The *outer* `(?:gap label)*`/`+` group in `NAMED_DOMAIN_LOOSE`, `NAMED_DOMAIN_TIGHT` and `COUNTRY_DOMAIN`
 * also stays greedy, not possessive, for the reason the ADVISOR entry gave: the ending alternation (`com`,
 * `online`, `[a-z]{2}`) shares the letter class with an ordinary label, so a possessive outer group can
 * commit to reading the final "gap label" as just one more iteration and leave nothing for the required
 * trailing gap+ending to match — "mysite.com" would go through fully unmasked. (`EMAIL` and
 * `MAIL_PROVIDER`'s outer groups stay possessive: nothing follows them, so there is no ending to be
 * swallowed.) A lookahead-guarded possessive outer group was also tried, to avoid this backtracking, and
 * reverted (third DEVIATION, empirically found): guarding each iteration with "don't take this label if the
 * text ahead already looks like a complete ending" breaks a domain whose *first* label is itself short
 * enough to read as one ("wa.me/971501234567" — the guard sees "me" could be a final code and refuses to
 * let the loop consume "wa" as a mid-chain label, so the whole match fails even though "wa.me" is a longer,
 * correct match). Reproducing greedy longest-match with no new risk of under- or unmasking needs real
 * backtracking, so these three patterns are left fully backtracking (their pre-R144(b) "second pass" form)
 * and measured at up to ~180ms on `str_repeat('a.', 1000)` — over R144(b)'s 50ms budget on their own.
 * Finding 2's budget is instead met by `hasCandidate()`: before running one of these three patterns'
 * `replace()` at all, a cheap, non-backtracking presence check (fixed-length lookbehind/lookahead, no
 * nested quantifiers, so linear in message length) proves whether that pattern's ending token could appear
 * anywhere in the message — the literal `NON_WORD_ENDINGS`/`WORD_ENDINGS` alternatives for the two named-
 * domain patterns, a bounded, non-alnum-delimited two-letter run for `COUNTRY_DOMAIN`. A literal substring
 * the ending token requires is a necessary condition for the full pattern to match anywhere, so skipping
 * the expensive pattern when it is absent can never turn a match into a non-match; it only skips inputs
 * that were already guaranteed not to match. `str_repeat('a.', n)` contains none of the three tokens (no
 * letter is ever followed immediately by another letter), so all three patterns are skipped and the whole
 * call is linear in that specific shape. This is a presence gate, not a cost bound: `hasCandidate()` only
 * tells the caller whether the ending token is absent everywhere; once it is present anywhere in the body,
 * the full backtracking pattern still runs over the whole message, and `-` sits in both the label class and
 * the junk class, so `(?:label junk)+` is the classic `(a+)+` shape once the gate is open. Measured (not
 * merely theoretical, see VERIFICATION/DEVIATION in CYCLE-LOG): a benign-looking `str_repeat('a-', 20).'
 * ok'` (43 chars) already exhausts the default `pcre.backtrack_limit` and throws — failing the message
 * closed (never unmasked), but refusing to deliver a legitimate short message. R144(b)'s four required
 * shapes all happen to contain no gate token, so they measure the gate working, not the cost once it is
 * open; that gap is disclosed rather than fixed here (R144(d) pre-ruled outcome — see ADR-019 and
 * docs/reports/8b.md) because fixing it needs a real per-position cost bound (e.g. capping label-run
 * *iterations*, not junk-gap width, and removing the `-` overlap between LABEL and JUNK), which is a new
 * structural change, not a third revert/patch inside this already twice-reverted design. A PCRE engine
 * failure in either the gate or the full pattern (backtrack limit, bad UTF-8) still fails the message
 * closed, via `hasCandidate()`/`orFail()` respectively — confirmed for the gate-open dash-run case above.
 *
 * R149(b) (cycle 09 r1): closes the gate-open gap the previous paragraph disclosed, exactly the way it
 * named — removing the `-` overlap between LABEL and JUNK, and capping label-run *iterations* — for
 * NAMED_DOMAIN_LOOSE, NAMED_DOMAIN_TIGHT and COUNTRY_DOMAIN only (`JUNK`, `EMAIL` and `MAIL_PROVIDER` are
 * untouched: see BARE_DOMAIN_LABEL/MAX_BARE_DOMAIN_LABELS above for why EMAIL never had this shape). These
 * three patterns' label runs now use `BARE_DOMAIN_LABEL` (`[\p{L}\p{Nd}]+`, no `-`) instead of the shared
 * `[\p{L}\p{Nd}\-]+`, so letter/digit and junk are disjoint character classes for them — a label and its
 * following gap can no longer both claim the same character, which forecloses the `(a+)+` shape rather than
 * bounding its cost. The outer `(?:gap label)` group is additionally capped at `MAX_BARE_DOMAIN_LABELS`
 * iterations, belt-and-suspenders against an unbounded chain of one-character labels once the overlap is
 * gone. `str_repeat('a-', 20).' ok'`, the exact repro above, now completes well inside the 50ms budget (see
 * the "gate-open" timing fixtures added alongside this paragraph); so does a domain built from more labels
 * than the cap allows, which now matches its last `MAX_BARE_DOMAIN_LABELS` labels and the ending rather than
 * failing the message closed or passing through unmasked.
 *
 * Known limits, disclosed in ADR-019: numbers and addresses spelled out in words with plain spaces and
 * no punctuation at all ("zero five zero…", "sara at gmail dot com"), digits split by whole words or
 * replaced by lookalike letters, more than six separator *graphemes* between digits (so seven dashes, or
 * four family emoji, get through — phone-gap limits are unchanged by R144, disclosed as out of scope),
 * a `:` between digits, an address whose provider is not on the short MAIL_PROVIDER list and whose domain
 * has no recognised ending, a bare word-like ending reached only by whitespace and no punctuation at all
 * is deliberately NOT caught (see above), a multi-digit or fractional enclosed-number symbol ("➉", "½",
 * Roman numerals), and social handles are not caught. The safe side is over-masking: dates (27-09-2026,
 * 27/09/2026), "Year 10, 11, 12, 13", 10,000,000, "@ school, then", a file name such as solution.py, a
 * bare domain whose ending is not on the NAMED_DOMAIN list (".art") once any junk punctuation separates
 * its labels, "(at)"/"(dot)" written with any punctuation around them ("sara (at) gmail (dot) com"), and
 * now (R144) an arbitrarily long run of junk punctuation between labels (fifty or five hundred dots, or a
 * mixed junk run) are masked too — a label is not distinguished from a spelled-out separator word,
 * deliberately, or a real bare domain built the same way ("mysite.dot.com") would leak instead; the
 * variation selectors and every format character (bar the ZWJ, see above) are stripped from the stored
 * text; 16:00-17:00 and ordinary sentence ends are left alone.
 */
class MessageMasker
{
    public const PLACEHOLDER = '[hidden until after your first lesson]';

    private const MIN_PHONE_DIGITS = 7;

    private const MAX_PHONE_GAP_GRAPHEMES = 6;

    /**
     * Every format character (\p{Cf}: zero-width, direction marks, word joiners, the soft hyphen, the BOM, tag
     * characters) EXCEPT the zero-width joiner U+200D, the invisible Hangul and Khmer fillers, blank Braille and
     * the variation selectors. The ZWJ is spared so an emoji ZWJ-sequence still counts as one grapheme cluster
     * when a phone-number gap is measured (R143 — see the class docblock); it is otherwise invisible and inert.
     */
    private const INVISIBLE = '~(?:(?!\x{200D})\p{Cf})|\x{034F}|\x{115F}|\x{1160}|\x{17B4}|\x{17B5}|\x{180E}|\x{2800}|\x{3164}|[\x{FE00}-\x{FE0F}]|\x{FFA0}|[\x{E0100}-\x{E01EF}]~u';

    /**
     * Any single symbol that is not a letter, digit, whitespace or `@` — one Unicode code point, generalised so no
     * specific lookalike needs listing. `%` is excluded too: percent-encoding ("sara%40gmail.com") must stay a
     * single local-part token, not a junk separator, or the label chain bridges "sara" and "40gmail" into a bogus
     * multi-label bare domain and over-masks the "sara%" prefix that should be left alone.
     */
    private const JUNK = '[^\p{L}\p{Nd}\s@%]';

    /**
     * Whitespace and/or junk freely mixed, possibly empty — used only immediately touching an `@`, where the `@`
     * itself is already the strong signal, so unlike a bare domain no particular punctuation is required: pure
     * spaces ("sara  @  gmail"), pure junk ("sara"@gmail, sara!@gmail) or a mix are all accepted. Unbounded and
     * possessive (R144): a gap must never fail to match purely because it is long, and since JUNK/whitespace/`@`
     * never overlap with a letter or digit there is nothing for possessive matching to wrongly commit to.
     */
    private const AT_GAP = '[^\p{L}\p{Nd}@]*+';

    /**
     * Junk and whitespace freely interspersed, but at least one junk character somewhere in the run — used between
     * domain/local-part labels once `@` has already anchored the match. Unbounded and possessive (R144): both the
     * spacing and the count of junk repetitions are uncapped, so a gap of any length or shape (all-dots, all-spaces
     * around one dot, or dots and spaces mixed, "sara@name . , . com") still matches; the leading/trailing/interior
     * `\s*+` and the required `JUNK` inside each iteration of the group never overlap each other or a letter/digit,
     * so possessive matching commits to nothing a greedy match would have found instead.
     */
    private const LABEL_GAP = '\s*+(?:'.self::JUNK.'\s*+)++';

    /** No whitespace at all — used for bare-domain word-like endings and every two-letter country code. Unbounded and possessive (R144, see LABEL_GAP above); whitespace-free is the defining property, so it stays a single possessive run. */
    private const TIGHT_JUNK = self::JUNK.'++';

    /** Junk and whitespace freely interspersed, at least one junk character — used for bare-domain non-word endings only. Unbounded and possessive (R144, see LABEL_GAP above); structurally identical to LABEL_GAP now that both caps are gone. */
    private const LOOSE_DOT_JUNK = '\s*+(?:'.self::JUNK.'\s*+)++';

    /**
     * R149(b): the bare-domain label used only by NAMED_DOMAIN_LOOSE, NAMED_DOMAIN_TIGHT and
     * COUNTRY_DOMAIN. Unlike EMAIL's label class, this one drops `-`: `JUNK` (above) already accepts `-`
     * as a separator, and while the label shared it too, a label and the gap that followed it could each
     * read the same dash run in more than one way once the pattern's ending token made a match possible at
     * all (`hasCandidate()`'s gate open) — the classic `(a+)+` shape, catastrophic only once the gate is
     * open (see the class docblock's R144 paragraphs). Letter/digit and junk are now fully disjoint
     * character classes for these three patterns, so a label and the gap after it can never both claim the
     * same character: every position in the subject belongs to exactly one side of that boundary, which
     * forecloses the ambiguity rather than merely bounding its cost. `EMAIL` keeps `-` in its label class
     * (see DEVIATION 1 below) because, unlike these three, it is anchored by a leading `@`, possessive
     * throughout, and never fully backtracking — it was never the shape Finding 2 described.
     */
    private const BARE_DOMAIN_LABEL = '[\p{L}\p{Nd}]+';

    /**
     * R149(b): bounds how many labels NAMED_DOMAIN_LOOSE/_TIGHT/COUNTRY_DOMAIN's outer group can iterate,
     * on top of BARE_DOMAIN_LABEL above — belt and suspenders, not a second fix for the same hole: disjoint
     * label/junk classes remove the exponential shape, but an unbounded outer group is still needless work
     * against a message built from thousands of one-character labels ("a.a.a.a…com"), which is linear-cost
     * but still worth bounding once nothing legitimate is that long. A real domain name is very rarely more
     * than five or six labels; 16 leaves generous headroom (subdomains, a long marketing domain) without
     * leaving the cap effectively unbounded. A domain with more labels than this is not left unmasked: each
     * pattern is anchored at the ending, not at the first label, so `preg_replace` still matches starting
     * from a later label — the earliest labels fall outside the match, never the whole address leaking
     * unmasked (see the "more labels than the cap" fixture).
     */
    private const MAX_BARE_DOMAIN_LABELS = 16;

    private const NON_WORD_ENDINGS = 'com|net|org|edu|gov|mil|int|info|biz|app|dev|xyz|pro|mobi|link|page';

    private const WORD_ENDINGS = 'online|site|tech|club|shop|store|blog|live|news|cloud|academy|wiki|name|email|school|chat|space|world|education|ninja|network|agency|digital|media|life|today|tutor|tutors|zone|team|group';

    /**
     * Local part, an `@` (a run of one or more collapses to one, so "sara@@gmail.com" still matches), loose junk
     * either side of the `@`, then two or more letter/digit labels joined by loose junk. The `@` itself is the
     * strong signal, so unlike a bare domain any junk (not just a dot) is accepted between labels here.
     */
    private const EMAIL = '~(?<![\p{L}\p{Nd}._%+\-])[\p{L}\p{Nd}._%+\-]++'.self::AT_GAP.'@++'.self::AT_GAP.'[\p{L}\p{Nd}\-]+(?:'.self::LABEL_GAP.'[\p{L}\p{Nd}\-]+)++~u';

    /** A well-known mail provider after an `@` needs no domain ending at all: "sara@gmail com" and "sara@gmail" are addresses. */
    private const MAIL_PROVIDER = '~(?<![\p{L}\p{Nd}._%+\-])[\p{L}\p{Nd}._%+\-]++'.self::AT_GAP.'@++'.self::AT_GAP.'(?:gmail|googlemail|hotmail|outlook|yahoo|icloud|proton(?:mail)?|aol|msn)(?![\p{L}\p{Nd}])~iu';

    private const URL_WITH_SCHEME = '~\b[a-z][a-z0-9+.\-]{1,15}://\S+~iu';

    private const URL_WWW = '~(?<![\p{L}\p{Nd}])www\.\S+~iu';

    /**
     * A non-word ending after a loose, dot-shaped gap: "mysite . com", "mysite[.]com", "mysite,,com" all mask. The
     * outer `(?:gap label)` group and the label runs stay greedy, not possessive (R144, see class docblock): a
     * lookahead-guarded possessive outer group was tried and reverted (DEVIATION, see CYCLE-LOG) — it breaks a
     * short first label that itself looks like a complete ending ("wa.me/971501234567" stopped matching, because
     * the lookahead refused to let the loop consume "wa" once it saw "me" could be read as the final code — but
     * "wa" was never meant to be final here, there was more domain after it). Reproducing greedy longest-match
     * exactly, with no risk of a new under- or unmask, needs real backtracking; Finding 2's cost is instead bounded
     * two ways — `hasCandidate()` below skips this pattern entirely when none of its ending words appear anywhere
     * in the message, and (R149(b)) the label class no longer overlaps `JUNK` and the outer group is capped at
     * `MAX_BARE_DOMAIN_LABELS` iterations, so even once the gate is open the match is linear, not exponential, in
     * the gap-open case the gate alone cannot bound (see BARE_DOMAIN_LABEL/MAX_BARE_DOMAIN_LABELS above).
     */
    private const NAMED_DOMAIN_LOOSE = '~(?<![\p{L}\p{Nd}])'.self::BARE_DOMAIN_LABEL.'(?:'.self::LOOSE_DOT_JUNK.self::BARE_DOMAIN_LABEL.'){0,'.self::MAX_BARE_DOMAIN_LABELS.'}'.self::LOOSE_DOT_JUNK.'(?:'.self::NON_WORD_ENDINGS.')(?![\p{L}\p{Nd}])(?:[/:?#]\S*+)?~iu';

    /** A word-like ending needs a tight gap — no whitespace at all — or "Ok. Online lessons are fine." would mask. Greedy throughout, same reasoning and same R149(b) overlap/iteration bound as NAMED_DOMAIN_LOOSE above; gated the same way by `hasCandidate()`. */
    private const NAMED_DOMAIN_TIGHT = '~(?<![\p{L}\p{Nd}])'.self::BARE_DOMAIN_LABEL.'(?:'.self::TIGHT_JUNK.self::BARE_DOMAIN_LABEL.'){0,'.self::MAX_BARE_DOMAIN_LABELS.'}'.self::TIGHT_JUNK.'(?:'.self::WORD_ENDINGS.')(?![\p{L}\p{Nd}])(?:[/:?#]\S*+)?~iu';

    /** Any two-letter country code after a tight-junk label; tight only, or "see you. Me too" would be hidden. Greedy throughout, same reasoning and same R149(b) overlap/iteration bound as NAMED_DOMAIN_LOOSE above; gated by `hasCandidate()` on a bare `[a-z]{2}` presence check (see HAS_COUNTRY_ENDING below — not `[\p{L}]{2}`; the gate is ASCII-only, matching this pattern's own ending). */
    private const COUNTRY_DOMAIN = '~(?<![\p{L}\p{Nd}])(?:'.self::BARE_DOMAIN_LABEL.self::TIGHT_JUNK.'){1,'.self::MAX_BARE_DOMAIN_LABELS.'}[a-z]{2}(?![\p{L}\p{Nd}])(?:[/:?#]\S*+)?~iu';

    /**
     * Presence gates for NAMED_DOMAIN_LOOSE/TIGHT/COUNTRY_DOMAIN (R144(b), see class docblock): each is a fixed,
     * non-backtracking check (no nested quantifiers — a literal alternation and/or a fixed-length lookaround) for
     * whether that pattern's ending token could occur anywhere in the message at all. A literal ending token is a
     * necessary condition for the full pattern to match, so `hasCandidate()` returning false is proof the full
     * pattern cannot match anywhere and its `replace()` call can be skipped outright.
     */
    private const HAS_NON_WORD_ENDING = '~(?:'.self::NON_WORD_ENDINGS.')(?![\p{L}\p{Nd}])~iu';

    private const HAS_WORD_ENDING = '~(?:'.self::WORD_ENDINGS.')(?![\p{L}\p{Nd}])~iu';

    /** Mirrors COUNTRY_DOMAIN's own ending: a bounded two-letter run, preceded by junk (never a bare letter/digit) and not followed by another letter/digit — "word"/"text" (4 letters, no isolated 2-letter token) do not count. */
    private const HAS_COUNTRY_ENDING = '~(?<![\p{L}\p{Nd}])[a-z]{2}(?![\p{L}\p{Nd}])~iu';

    /**
     * Digits with up to six separator *graphemes* between them (`\X`, not a code point — see the class docblock),
     * a grapheme disqualified from counting as a separator (and so ending the run) if it is `:` or an ordinary
     * letter (a modifier letter such as the Arabic tatweel is still accepted).
     */
    private const PHONE_RUN = '~[+(\[]{0,2}\p{Nd}(?:(?:(?!:)(?!(?=\p{L})(?!\p{Lm}))\X){0,'.self::MAX_PHONE_GAP_GRAPHEMES.'}\p{Nd})+\p{M}*~u';

    public function mask(string $body): MaskedMessage
    {
        $text = mb_scrub($body, 'UTF-8');

        // ext-intl is a Filament requirement, so it is always there; a failure refuses the message.
        $text = $this->orFail(Normalizer::normalize($text, Normalizer::FORM_KC));
        $text = $this->replace(self::INVISIBLE, '', $text);
        $text = $this->foldEnclosedDigits($text);

        $hidden = 0;
        $masked = $text;

        foreach ([
            [self::EMAIL, null],
            [self::URL_WITH_SCHEME, null],
            [self::URL_WWW, null],
            // Gated (R144(b)): these three are the only patterns whose outer group is fully
            // backtracking (see class docblock), so each is skipped outright — no replace() call at
            // all — when a cheap, linear presence check proves its ending token cannot occur anywhere.
            [self::NAMED_DOMAIN_LOOSE, self::HAS_NON_WORD_ENDING],
            [self::NAMED_DOMAIN_TIGHT, self::HAS_WORD_ENDING],
            [self::COUNTRY_DOMAIN, self::HAS_COUNTRY_ENDING],
            // MAIL_PROVIDER runs last: run before the bare-domain patterns, a partial match (e.g.
            // "sara@gmail" alone) leaves real trailing text ("  com") directly against the freshly
            // inserted PLACEHOLDER's own "]", which NAMED_DOMAIN_LOOSE then spuriously bridges into a
            // second, nested placeholder. Running it last means EMAIL/the bare-domain patterns already
            // consumed every case MAIL_PROVIDER would otherwise partially match.
            [self::MAIL_PROVIDER, null],
        ] as [$pattern, $gate]) {
            if ($gate !== null && ! $this->hasCandidate($gate, $masked)) {
                continue;
            }
            $masked = $this->replace($pattern, self::PLACEHOLDER, $masked, $hidden);
        }

        $masked = $this->replaceCallback(
            self::PHONE_RUN,
            function (array $match) use (&$hidden): string {
                if (preg_match_all('~\p{Nd}~u', $match[0]) < self::MIN_PHONE_DIGITS) {
                    return $match[0];
                }
                $hidden++;

                return self::PLACEHOLDER;
            },
            $masked,
        );

        // "masked" means something was hidden, not that NFKC, digit-folding or the invisible-character strip changed the text.
        return new MaskedMessage($masked, $hidden > 0);
    }

    /**
     * Folds single-digit enclosed/dingbat number symbols ("❶", "➊", "⓵") to a plain ASCII digit using the
     * Unicode Numeric_Value property, so no specific code point needs to be enumerated (R143 — advisor-suggested
     * over a hand-built table). Only symbols whose numeric value is a whole number 0-9 are folded — multi-digit
     * forms ("➉" = ten) and non-integer values ("½") are left as-is, a disclosed limit. NFKC already handles the
     * plain circled digits ("①"-"⑨", "⓪") ahead of this step, via their own compatibility decomposition; this is
     * a no-op for anything NFKC already folded.
     */
    private function foldEnclosedDigits(string $text): string
    {
        return $this->orFail(preg_replace_callback('~\p{No}~u', static function (array $match): string {
            $value = IntlChar::getNumericValue($match[0]);

            // A single \p{No} code point always has a defined Numeric_Value in ICU, so
            // IntlChar::getNumericValue() cannot fail here; the sentinel for "no numeric value"
            // (U_NO_NUMERIC_VALUE, a large negative float) is excluded by the >= 0 bound below,
            // same as any other non-integer or out-of-range value ("½", "➉" = ten).
            if ($value === floor($value) && $value >= 0 && $value <= 9) {
                return (string) (int) $value;
            }

            return $match[0];
        }, $text));
    }

    private function replace(string $pattern, string $replacement, string $subject, int &$count = 0): string
    {
        $done = 0;
        $result = $this->orFail(preg_replace($pattern, $replacement, $subject, -1, $done));
        $count += $done;

        return $result;
    }

    private function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        return $this->orFail(preg_replace_callback($pattern, $callback, $subject));
    }

    /**
     * R144(b): the cheap presence gate in front of NAMED_DOMAIN_LOOSE/TIGHT/COUNTRY_DOMAIN (see class docblock and
     * the HAS_*_ENDING constants). A PCRE failure here (bad UTF-8, backtrack limit) is exactly the kind of engine
     * failure `orFail()` treats as fail-closed elsewhere, so it is treated the same way here: the message is
     * refused rather than let through on the assumption that "no match" meant "definitely not a candidate."
     */
    private function hasCandidate(string $gate, string $subject): bool
    {
        $result = preg_match($gate, $subject);

        if ($result === false) {
            throw new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE);
        }

        return $result === 1;
    }

    /**
     * A regex that errors (backtrack limit, bad UTF-8) must never let the original text through:
     * the message is refused instead.
     */
    private function orFail(string|false|null $result): string
    {
        if ($result === false || $result === null) {
            throw new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE);
        }

        return $result;
    }
}
