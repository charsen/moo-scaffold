#!/usr/bin/env bash
# 一键串行跑所有 ui-checks 脚本
#
# 用法：HOST=http://localhost ./run-all.sh
set +e   # 不要 exit on error，要跑完所有再汇总

DIR="$(cd "$(dirname "$0")" && pwd)"
HOST="${1:-${HOST:-http://localhost}}"

OVERALL=0
declare -a RESULTS

run_check() {
    local name="$1"
    local cmd="$2"
    echo ""
    echo "######################################"
    echo "## $name"
    echo "######################################"
    if eval "$cmd"; then
        RESULTS+=("✅ $name")
    else
        RESULTS+=("❌ $name")
        OVERALL=1
    fi
}

run_check "smoke-http"     "HOST=$HOST $DIR/smoke-http.sh"
run_check "static-guards"  "$DIR/static-guards.sh"
run_check "asset-guards"   "$DIR/asset-guards.sh"
run_check "css-budget"     "$DIR/css-budget.sh"

echo ""
echo "######################################"
echo "## SUMMARY"
echo "######################################"
for r in "${RESULTS[@]}"; do
    echo "  $r"
done

if [[ $OVERALL -eq 0 ]]; then
    echo ""
    echo "🎉 All checks passed."
else
    echo ""
    echo "💥 Some checks failed."
fi

exit $OVERALL
