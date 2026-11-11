#!/bin/bash
# Static checks: shellcheck on our scripts, php -l on every PHP file (in Docker).
set -euo pipefail
cd "$(dirname "$0")/.."

status=0

echo "== shellcheck"
#bash 3 compatible (macOS); script names contain no spaces
# shellcheck disable=SC2046
shellcheck -x -P SCRIPTDIR $(git ls-files --cached --others --exclude-standard '*.sh') || status=1

echo "== php -l (php:8.4-cli)"
docker run --rm -v "$PWD:/src:ro" -w /src php:8.4-cli \
	sh -c 'rc=0; for f in $(find apps -name "*.php"); do php -l "$f" >/dev/null || rc=1; done; exit $rc' || status=1

echo "== python syntax"
find apps -path '*/sbin/*' -type f | while read -r f; do
	if head -1 "$f" | grep -q python; then
		docker run --rm -v "$PWD:/src:ro" -w /src python:3-slim python -c "import ast,sys; ast.parse(open(sys.argv[1]).read())" "$f" || exit 1
	fi
done || status=1

exit $status
