#!/usr/bin/env bash
# CSS 体积预算：index.css gzip 后 ≤ 60 KB
set -e

DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
CSS="$ROOT/public/css/index.css"
LIMIT_KB="${LIMIT_KB:-60}"

if [[ ! -f "$CSS" ]]; then
    echo "❌ CSS not found: $CSS"
    exit 1
fi

# gzip 后字节数
size_bytes=$(gzip -c "$CSS" | wc -c | tr -d ' ')
size_kb=$(awk "BEGIN { printf \"%.1f\", $size_bytes / 1024 }")
raw_kb=$(awk "BEGIN { printf \"%.1f\", $(wc -c <"$CSS" | tr -d ' ') / 1024 }")

echo "CSS budget"
echo "==============================="
echo "Raw  : ${raw_kb} KB"
echo "Gzip : ${size_kb} KB"
echo "Limit: ${LIMIT_KB} KB"
echo "==============================="

if (( $(awk "BEGIN { print ($size_kb <= $LIMIT_KB) }") )); then
    echo "✅ ${size_kb} KB ≤ ${LIMIT_KB} KB"
    exit 0
else
    echo "❌ ${size_kb} KB > ${LIMIT_KB} KB（超预算 $(awk "BEGIN { print $size_kb - $LIMIT_KB }") KB）"
    exit 1
fi
