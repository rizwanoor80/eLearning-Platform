#!/usr/bin/env bash
set -euo pipefail

# CLAUDE.md's RTL-ready layout rule: resources/ must use Tailwind logical
# properties only (ms-/me-/ps-/pe-/start-/end-/text-start/text-end), never
# physical ones. Runs under Git Bash on Windows and under bash on Linux (CI).
PATTERN='\b(ml-|mr-|pl-|pr-|left-|right-|text-left|text-right)'

# R9 exclusion: the animate plugin only ships physical
# slide-in-from-{left,right,top,bottom} / slide-out-to-{left,right,top,bottom}
# keyframe utilities — there is no logical slide-in-from-start/end. These are
# deliberately paired with Radix's own physical-only data-side/data-motion
# attributes in the generated tooltip, select, dropdown-menu and
# navigation-menu "Content" components under resources/js/components/ui/, and
# cannot be converted. Strip just these tokens from each matched line before
# checking, so a genuine violation elsewhere on the same line still surfaces.
violations=$(grep -rInE "$PATTERN" resources/ \
    | sed -E 's/slide-(in-from|out-to)-(left|right)(-[0-9]+)?//g' \
    | grep -E "$PATTERN" || true)

if [ -n "$violations" ]; then
    echo "RTL check failed: physical-direction Tailwind utilities found (use ms-/me-/ps-/pe-/start-/end-/text-start/text-end instead):"
    echo "$violations"
    exit 1
fi

echo "RTL check passed: no physical-direction utilities found in resources/ (excluding the animate plugin's fixed slide-in-from-left/right keyframe names, R9)."
