#!/usr/bin/env bash
# tools/format-scaffold-yaml.sh — 一次性 normalize scaffold yaml (历史遗留入口)
#
# **默认情况下不需要跑这个脚本。** 算法已下沉到:
#   src/Designer/YamlFormatter::dumpPreservingComments()
#
# Designer save (SchemaLoader::saveModule) 每次写盘都走 YamlFormatter,
# GUI dump 风格跟人工手写格式自动一致,存量 yaml 不会再漂移。
# 也就是说:不存在「需要手动跑 normalize」的日常场景了。
#
# 仍留这个脚本只是为了一种极少数情况:
#   import 一批外部老 yaml(从别处拷来 / 历史快照恢复 / 别的工具产的),
#   想一次性对齐到 scaffold 当前风格。
#
# 这种场景下:
#   1. 自己写个 ~50 行 PHP 脚本调 YamlFormatter::dumpPreservingComments() 跑一遍,
#      参考算法: docs/yaml-style.md
#   2. 跑前 git commit 保证 dirty=clean,跑完 git diff 自己 review
#
# 历史:旧 moo:format-yaml Artisan 命令已经移除。
# 砍的理由:GUI dump 跟手写已对齐,「批量 normalize」命令的使命已完。
# 备份/快照类工具能走 git 就走 git。

echo "本脚本默认空跑。具体说明见脚本头部注释和 docs/yaml-style.md。"
echo "(算法仍在 src/Designer/YamlFormatter.php,Designer save 自动调用。)"
exit 0
