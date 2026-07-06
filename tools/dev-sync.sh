#!/usr/bin/env bash
#
# dev-sync.sh —— 本地开发回路:编译 SCSS + 把包内 public/* 同步进宿主 engine,一条命令。
#
# 改 SCSS/JS 后的固定税是「npm run build:css(包内)→ cd engine → vendor:publish(engine 内)」
# 两步跨两个目录,容易漏掉 publish 那步。这个脚本把它合成一条。
#
#   用法:  SCAFFOLD_DEV_ENGINE=/path/to/host/engine ./tools/dev-sync.sh
#   或在 ~/.zshrc 里  export SCAFFOLD_DEV_ENGINE=/path/to/host/engine
#   然后直接        ./tools/dev-sync.sh
#
# ⚠ 这是【本地预览】辅助,只同步到你自己指定的一个 engine,供浏览器看 /scaffold 效果。
#   不碰 git、不推任何下游 —— 跟历史上被砍掉的 publish:vendor 自动推流程(footgun)是两回事。
#   下游业务仓的更新照旧走它们自己的 pull.sh,与本脚本无关。
#
set -euo pipefail

ENGINE="${SCAFFOLD_DEV_ENGINE:-}"
if [ -z "$ENGINE" ]; then
    echo "✗ 先设 SCAFFOLD_DEV_ENGINE 指向宿主 engine 根目录(含 artisan 的那层)" >&2
    echo "  例:SCAFFOLD_DEV_ENGINE=/path/to/host/engine $0" >&2
    exit 1
fi
if [ ! -f "$ENGINE/artisan" ]; then
    echo "✗ $ENGINE 下找不到 artisan,不像 Laravel engine 根目录" >&2
    exit 1
fi

PKG="$(cd "$(dirname "$0")/.." && pwd)"

echo "→ [1/3] build:css(包内 SCSS → public/css/index.css)"
( cd "$PKG" && npm run build:css >/dev/null )

echo "→ [2/3] vendor:publish(包 public/* → engine/public/vendor/scaffold/)"
( cd "$ENGINE" && php artisan vendor:publish --tag=public --force >/dev/null )

echo "→ [3/3] view:clear(让 Blade 改动即时生效)"
( cd "$ENGINE" && php artisan view:clear >/dev/null 2>&1 || true )

echo "✓ 同步完成。普通刷新浏览器即可(资产引用都带 ?v={filemtime})。"
