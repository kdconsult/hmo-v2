#!/bin/bash
# HMO Project — Session Start Context Injection
# This script runs at the start of every Claude Code session and injects
# current project state into the conversation context.

echo "=============================="
echo " HMO ERP — Session Context"
echo "=============================="
echo ""

# Current phase status from STATUS.md
echo "[ Phase Status ]"
grep -m1 "^\*\*Phase" docs/STATUS.md 2>/dev/null || echo "(no phase line found)"
echo ""

# Last git commit
echo "[ Last Commit ]"
git log -1 --format='%h %s (%cr)' 2>/dev/null || echo "(not a git repo)"
echo ""

# Uncommitted changes
CHANGES=$(git status --short 2>/dev/null | wc -l | tr -d ' ')
echo "[ Uncommitted Changes: $CHANGES ]"
if [ "$CHANGES" -gt 0 ]; then
    git status --short 2>/dev/null | head -10
fi
echo ""

# Test count from STATUS.md
echo "[ Last Known Test Count ]"
grep -m1 "tests pass" docs/STATUS.md 2>/dev/null || grep "Phase.*complete" docs/STATUS.md | tail -1
echo ""

# Pending this-phase items from backlog
PENDING=$(grep -c "DO THIS PHASE" tasks/backlog.md 2>/dev/null || echo 0)
if [ "$PENDING" -gt 0 ]; then
    echo "[ Pending This-Phase Items ($PENDING) ]"
    grep -B2 "DO THIS PHASE" tasks/backlog.md 2>/dev/null | grep "^###" | sed 's/### /  - /'
    echo ""
fi

# Active phase task file
if ls tasks/phase-2.5.md &>/dev/null; then
    DONE=$(grep -c "\- \[x\]" tasks/phase-2.5.md 2>/dev/null || echo 0)
    TODO=$(grep -c "\- \[ \]" tasks/phase-2.5.md 2>/dev/null || echo 0)
    echo "[ Phase 2.5 Progress: $DONE done / $TODO remaining ]"
    echo ""
fi

echo "Read docs/STATUS.md for full context. Check tasks/ for active work."
echo "=============================="
