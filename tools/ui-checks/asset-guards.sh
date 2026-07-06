#!/usr/bin/env bash
# 资源引用检查：CSS / JS 引用的文件是否存在
set -e

DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
PUBLIC="$ROOT/public"

FAIL=0
PASS_COUNT=0
FAIL_COUNT=0

ASSETS=(
    "css/index.css"
    "javascript/alpine-csp.min.js"
    "javascript/alpine-init.js"
    "javascript/clipboard.min.js"
    "javascript/designer.js"
    "javascript/jquery-3.7.1.min.js"
    "javascript/jsonFormat.js"
    "javascript/main.js"
    "javascript/pages/api-index.js"
    "javascript/pages/api-request-index.js"
    "javascript/pages/api-request.js"
)

echo "Asset guards in $PUBLIC"
echo "==============================="

for path in "${ASSETS[@]}"; do
    if [[ -f "$PUBLIC/$path" ]]; then
        size=$(wc -c <"$PUBLIC/$path" | tr -d ' ')
        echo "✅ $path  ($size B)"
        ((PASS_COUNT++)) || true
    else
        echo "❌ $path  (missing)"
        ((FAIL_COUNT++)) || true
        FAIL=1
    fi
done

echo "==============================="
echo "Pass: $PASS_COUNT   Fail: $FAIL_COUNT"

exit $FAIL
