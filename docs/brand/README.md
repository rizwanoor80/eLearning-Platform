# TrusTutor — Brand Pack

Logo, icons and theme tokens for the TrusTutor website and admin panel.
Locked spec: horizontal ruled lockup (C) primary, stacked (D) secondary, tagline **Tutoring, Electrified.**

---

## 1. What is in here

    wordmark/    trusTutor wordmark, transparent PNG, 4 colourways
    symbol/      the leading t + dot, transparent PNG, 3 colourways
    icons/       favicons, apple-touch, app icon, maskable, avatar
    social/      Open Graph image 1200x630
    theme/       colors.css, tokens.json, tailwind.colors.js
    lockup.html  copy-paste markup for header lockups (desktop + mobile)

**These are raster (PNG).** A vector redraw is still outstanding — see section 7.
Until it lands, never scale a PNG above the sizes supplied here.

## 2. Which lockup to use

| Situation | Use |
|---|---|
| 250px wide or more, horizontal space | Lockup C — wordmark, 1px rule, tagline right |
| Tall or square space, mobile header | Lockup D / mobile — tagline stacked **below** the wordmark |
| Below 250px wide, or tagline already in the copy | Wordmark alone, minimum 90px wide |
| Favicon, app icon, avatar, app nav | Symbol alone, minimum 16px tall |

**Mobile rule:** below 768px the tagline never sits beside the wordmark. It drops
underneath, left-aligned to the dot, at 0.34 of the wordmark height, with a gap of
0.30 of the wordmark height. The vertical rule is dropped entirely — no rule in the
stacked lockup. Below 480px, drop the tagline and use the wordmark alone.

## 3. Clear space and minimum sizes

Clear space = X, the diameter of the dot (0.38 of wordmark height). Keep X clear on
all four sides, measured from the dot and from the outer edge of the tagline block.

- Lockup C: minimum 250px wide
- Wordmark alone: minimum 90px wide
- Symbol: minimum 16px tall (use the small cut once it exists)

## 4. Colour versions

Full colour on white (default) · full colour on blush #FFF1F0 · white knockout on
dark grounds and photos · one-colour maroon #64011D · one-colour black.
Never place the full-colour logo on coral — use the white knockout.

## 5. Type

- Headings and UI: **Schibsted Grotesk** (400 / 500 / 700)
- Tagline only: **Quicksand SemiBold 600** — never reset in another face
- Labels, data, code: **IBM Plex Mono** (400 / 500)

## 6. Web install snippet

    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32">
    <link rel="icon" href="/icons/favicon-16.png" sizes="16x16">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
    <meta property="og:image" content="/social/og-image-1200x630.png">

Replace favicon-*.png with favicon.svg as soon as the vector master is delivered.

## 7. Outstanding — ask the designer for

1. Vector redraw of the wordmark (everything here is raster)
2. Tagline converted to outlines in the master file
3. Heavier symbol cut for 16-32px use
4. Longer crossbar on the leading **t**
5. Per lockup, per colourway: SVG outlined transparent, CMYK PDF, PNG @1x/@2x/@3x
