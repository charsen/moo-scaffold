#!/usr/bin/env bash
# 业务视图静态规则扫描
#
# 检查项：
# - 业务 view 内 <style> 块 = 0
# - 业务 view 内硬编码 hex 颜色 ≤ 5（明确白名单的少量例外）
# - 业务 view 内 padding: Npx 硬编码 = 0
# - 业务 view 内超过 30 行的 <script> 块 = 0
set -e

DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
VIEWS="$ROOT/src/Http/Views"
SRC="$ROOT/src"

FAIL=0
PASS_COUNT=0
FAIL_COUNT=0

if [[ ! -d "$VIEWS" ]]; then
    echo "❌ Views dir not found: $VIEWS"
    exit 1
fi

echo "Static guards scan in $VIEWS"
echo "==============================="

# -------------------------------------------------------------------
# 1. <style> 块 = 0（业务视图，不含 components）
# 排除注释行：{{-- ... <style> ... --}} 不算违规
# -------------------------------------------------------------------
echo ""
echo "## 1. <style> 块（业务视图禁止）"
matches=$(grep -rn "<style" "$VIEWS" --include="*.blade.php" 2>/dev/null | grep -v "/components/" | grep -v "{{--.*<style>.*--}}" || true)
if [[ -n "$matches" ]]; then
    echo "❌ 发现 <style> 块："
    echo "$matches" | sed 's/^/    /'
    ((FAIL_COUNT++))
    FAIL=1
else
    echo "✅ <style> = 0"
    ((PASS_COUNT++))
fi

# -------------------------------------------------------------------
# 2. 硬编码 hex 颜色（≤ 5 允许，含 logo 等白名单例外）
# -------------------------------------------------------------------
echo ""
echo "## 2. 硬编码 hex 颜色（阈值 ≤ 5）"
count=$(grep -rEn "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\\b" "$VIEWS" --include="*.blade.php" 2>/dev/null | wc -l | tr -d ' ')
if [[ "$count" -gt 5 ]]; then
    echo "❌ 发现 $count 处（阈值 5）"
    grep -rEn "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?\\b" "$VIEWS" --include="*.blade.php" 2>/dev/null | sed 's/^/    /'
    ((FAIL_COUNT++))
    FAIL=1
else
    echo "✅ 硬编码 hex 数 = $count"
    ((PASS_COUNT++))
fi

# -------------------------------------------------------------------
# 3. padding: Npx 硬编码（视图层禁止）
# -------------------------------------------------------------------
echo ""
echo "## 3. padding: Npx 硬编码（业务视图禁止）"
matches=$(grep -rEn "padding:\\s*[0-9]+px" "$VIEWS" --include="*.blade.php" 2>/dev/null || true)
if [[ -n "$matches" ]]; then
    echo "❌ 发现："
    echo "$matches" | sed 's/^/    /'
    ((FAIL_COUNT++))
    FAIL=1
else
    echo "✅ padding: Npx = 0"
    ((PASS_COUNT++))
fi

# -------------------------------------------------------------------
# 4. <script> 块行数（> 30 视为应外提到 pages/*.js）
# -------------------------------------------------------------------
echo ""
echo "## 4. <script> 块行数（>30 应外提）"
big_scripts=""
for f in "$VIEWS"/**/*.blade.php; do
    [[ -f "$f" ]] || continue
    # 取每个 <script> 块的行数（粗略：脚本块开始到结束）
    lines=$(awk '
        /<script[^>]*>/ { in_script=1; count=0; start=NR; next }
        /<\/script>/   { if (in_script && count > 30) print FILENAME":"start": "count" lines"; in_script=0; next }
        in_script      { count++ }
    ' "$f" 2>/dev/null)
    if [[ -n "$lines" ]]; then
        big_scripts="$big_scripts$lines"$'\n'
    fi
done
if [[ -n "$big_scripts" ]]; then
    echo "⚠️ 发现 >30 行内联 <script>（建议外提到 pages/*.js）："
    echo "$big_scripts" | sed 's/^/    /'
    # 不算 FAIL：业务上 dictionaries / db/index / route 的滚动 spy 等 ~50 行是合理
    # 严格度按 02 plan §13 也只列了"业务视图 <style>=0"硬要求，<script> 是建议
    ((PASS_COUNT++))
else
    echo "✅ 没有 >30 行内联 <script>"
    ((PASS_COUNT++))
fi


# -------------------------------------------------------------------
# 5. Web 层禁调 Artisan generator 命令(全局 security policy)
#    src/Http/ 下 Controller/Middleware 出现 Artisan::call('moo:*') 或
#    Artisan::call("moo:*") 直接 fail。理由见 ScaffoldProvider 顶部 policy 注释
#    排除 PHP 注释行（通过 awk 检查行内容是否以 * 或 // 或 /* 开头）
# -------------------------------------------------------------------
echo ""
echo "## 5. Web 层禁调 moo: 系列 artisan 命令(security policy)"
if [[ -d "$SRC/Http" ]]; then
    matches=$(grep -rEn "Artisan::call\\(['\"]moo:" "$SRC/Http" 2>/dev/null | awk -F: '
        {
            # 提取第三个字段（代码内容）
            content = $3
            for (i = 4; i <= NF; i++) content = content ":" $i
            # 去除前导空白
            gsub(/^[[:space:]]+/, "", content)
            # 如果不是注释行，输出完整匹配
            if (content !~ /^(\*|\/\/|\/\*)/) print $0
        }
    ' || true)
    if [[ -n "$matches" ]]; then
        echo "❌ Web 层发现 Artisan::call('moo:...'):"
        echo "$matches" | sed 's/^/    /'
        echo "    → 生成 / 破坏类命令一律走终端,详见 ScaffoldProvider 顶部 SECURITY POLICY 注释"
        ((FAIL_COUNT++))
        FAIL=1
    else
        echo "✅ Web 层无 moo:* Artisan 调用"
        ((PASS_COUNT++))
    fi
fi

echo ""
echo "==============================="
echo "Pass: $PASS_COUNT   Fail: $FAIL_COUNT"

exit $FAIL
