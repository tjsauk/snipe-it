#!/usr/bin/env bash
# Pull latest changes from the standalone calendar repo into this snipe-it integration.
#
# Usage: bash calendar/pull-upstream.sh
#
# After running this, review the diff and re-apply any snipe-it-specific customizations:
#   - calendarTypes.ts:  lockStart, initialAnchorDate in CalendarInput; noBlock in ExistingReservation; displayColumnKey in DraftGroup
#   - calendarUtils.ts:  blockerOverlapExists respects noBlock; splitPeriodForWeek and periodToDisplayRect must be present
#   - AssetCalendar.tsx: clampEditPeriodWithinAsset skips own-user periods entirely (not just by index) and skips noBlock
#                        anchorDate useState uses input.initialAnchorDate
#                        showTopHandle uses !input.lockStart in edit mode
#                        onMouseDown beginDrag checks !(mode === 'edit' && input.lockStart)

set -e

REMOTE=calendar-upstream
REMOTE_URL=https://github.com/tjsauk/asset-calendar-standalone.git

# Add remote if missing
if ! git remote get-url $REMOTE &>/dev/null; then
  echo "Adding remote $REMOTE..."
  git remote add $REMOTE "$REMOTE_URL"
fi

echo "Fetching $REMOTE..."
git fetch $REMOTE

echo ""
echo "Changes since last sync:"
git log --oneline HEAD..${REMOTE}/main -- 2>/dev/null || true
git log --oneline calendar-upstream/main -5

echo ""
echo "Copying files..."
git show ${REMOTE}/main:src/calendar/AssetCalendar.tsx  > calendar/src/calendar/AssetCalendar.tsx
git show ${REMOTE}/main:src/calendar/calendarUtils.ts   > calendar/src/calendar/calendarUtils.ts
git show ${REMOTE}/main:src/calendar/calendarTypes.ts   > calendar/src/calendar/calendarTypes.ts

# Copy other files that don't need customization
git show ${REMOTE}/main:src/calendar/global.d.ts        > calendar/src/calendar/global.d.ts 2>/dev/null || true

echo ""
echo "Done. Files updated from upstream."
echo ""
echo "IMPORTANT: Re-apply snipe-it customizations before building:"
echo "  See comments at the top of this script for the list."
echo ""
echo "Build with: cd calendar && npm run build:snipeit"
