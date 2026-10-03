#!/usr/bin/env bash
# Check the markdown documentation before it is merged.
#
# docs/ is plain markdown browsed on GitHub: docs/README.md is the index and pages
# link to each other as [Text](Page-Name.md). This fails when
#   - docs/README.md is missing,
#   - a page, or the repository README.md, links to a docs page that does not exist.
# Usage: scripts/check-docs.sh [docs-dir] [readme]
set -euo pipefail

DOCS="${1:-docs}"
README="${2:-README.md}"
status=0

[[ -f "$DOCS/README.md" ]] || { echo "check-docs: $DOCS/README.md is missing" >&2; exit 1; }

check_links() {  # check_links <file> <link pattern> <prefix to strip>
  local file="$1" pattern="$2" strip="$3" target
  while IFS= read -r target; do
    target="${target%%#*}"
    [[ -z "$target" ]] && continue
    [[ -f "$DOCS/$target" ]] || { echo "check-docs: $file links to missing docs page '$target'" >&2; status=1; }
  done < <(grep -oE "\]\(${pattern}(#[A-Za-z0-9_-]+)?\)" "$file" | sed -E "s#^\]\(${strip}##; s#\)\$##" || true)
}

shopt -s nullglob
for page in "$DOCS"/*.md; do
  # Between pages: [Text](Page-Name.md) with no directory part.
  check_links "$page" '[A-Za-z0-9_-]+\.md' ''
done
# From the repository README: [Text](docs/Page-Name.md).
[[ -f "$README" ]] && check_links "$README" 'docs/[A-Za-z0-9_-]+\.md' 'docs/'

exit "$status"
