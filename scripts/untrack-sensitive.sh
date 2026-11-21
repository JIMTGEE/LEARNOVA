#!/usr/bin/env bash
# Makes git stop tracking files that should never be in a repository: learner uploads,
# database dumps and logs. Files STAY on your disk; only git forgets them.
#
# This does not remove them from history. See "Scrubbing history" in README.md.
#
#   ./scripts/untrack-sensitive.sh      then:  git commit -m "Stop tracking uploads, dumps and logs"
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

for p in Uploads backups logs edusync.sql learnova.sql tables.txt SELECT.txt .phcode.json; do
  git rm -r --cached --ignore-unmatch -q -- "$p"
done

# every log file, at any depth
git ls-files -z -- '*.log' '*error_log' '*error_log.txt' | xargs -0 -r git rm --cached -q --

echo "No longer tracked (files kept on disk):"
git status --short | grep '^D ' | sed 's/^D  //' | cut -d/ -f1 | sort | uniq -c | sort -rn
echo
echo "Next: git commit -m 'Stop tracking uploads, dumps and logs'"
