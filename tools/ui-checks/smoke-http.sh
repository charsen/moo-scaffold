#!/usr/bin/env bash
# HTTP smoke：每个 URL 返回 2xx/3xx 且不含 Laravel exception 页面特征
#
# 用法：HOST=http://localhost ./smoke-http.sh
#       或：./smoke-http.sh http://localhost
set -e

HOST="${1:-${HOST:-http://localhost}}"
DIR="$(cd "$(dirname "$0")" && pwd)"
PAGES_FILE="$DIR/pages.txt"
TMP_FILE="$(mktemp -t scaffold-smoke.XXXXXX.html)"
trap "rm -f '$TMP_FILE'" EXIT

if [[ ! -f "$PAGES_FILE" ]]; then
    echo "❌ pages.txt not found: $PAGES_FILE"
    exit 1
fi

FAIL=0
PASS_COUNT=0
FAIL_COUNT=0

echo "HTTP smoke check against $HOST"
echo "==============================="

while IFS= read -r path; do
    # 跳过空行 + 注释
    [[ -z "$path" || "$path" == \#* ]] && continue

    response=$(curl -s -o "$TMP_FILE" -w "%{http_code}" --max-time 10 "${HOST}${path}" || echo "000")

    # Laravel 异常页面特征：Whoops / Exception trace / Ignition 等
    if grep -qE "Whoops|class=\"trace-details\"|illuminate.view.viewException|Symfony\\\\Component\\\\HttpKernel\\\\Exception" "$TMP_FILE" 2>/dev/null; then
        echo "❌ $path → $response  (Laravel exception page detected)"
        ((FAIL_COUNT++)) || true
        FAIL=1
    elif [[ "$response" =~ ^[23] ]]; then
        echo "✅ $path → $response"
        ((PASS_COUNT++)) || true
    else
        echo "❌ $path → $response"
        ((FAIL_COUNT++)) || true
        FAIL=1
    fi
done < "$PAGES_FILE"

echo "==============================="
echo "Pass: $PASS_COUNT   Fail: $FAIL_COUNT"

exit $FAIL
