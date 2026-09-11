#!/usr/bin/env bash
#
# `npm run test:e2e:safe` 的实现：跑 e2e，跑完把**宿主**还原到跑之前的样子。
#
# 为什么不能只 `git -C <dir> checkout .`（旧实现）：
#   那**只回滚已跟踪文件**。designer 的「真写」类用例会新建
#     - scaffold/database/<新 schema>.yaml
#     - scaffold/database/.snapshots/<Schema>.yaml
#     - database/migrations/*_create_*_table.php
#   这些都是**未跟踪**文件，`git checkout .` 一个都不删 —— 实测一轮留下 8 个 migration
#   + 1 个 schema yaml + 1 个 snapshot。它们会污染宿主仓的 `git status`，且下次 diff 会误报。
#
# 为什么也不能直接 `git clean -fd`：
#   那会连宿主**本来就有的**未跟踪文件一起删掉 —— 例如本地自己写的 `scaffold/accounts.yaml`
#   （账号文件常是未跟踪的本地资产），删了等于把宿主登不进去。所以用**差值法**：
#   跑之前记一份未跟踪清单，跑完只删「本次新出现的」，基线里已有的绝不触碰。
#
# 用法（package.json 的 test:e2e:safe 会调它，也可直接 bash 调）：
#   E2E_HOST_SCAFFOLD_DB_PATH=<宿主>/engine/scaffold/database npm run test:e2e:safe [-- <playwright 参数>]
#
# 注意 E2E_HOST_SCAFFOLD_DB_PATH 指的是宿主 **`scaffold/database/`** 那一级（不是 `scaffold/`），
# 因为 designer.spec 会直接 `path.resolve(dbDir, '<Schema>.yaml')` 去清理自己造的 yaml。
set -uo pipefail

HOST_DB_PATH="${E2E_HOST_SCAFFOLD_DB_PATH:-}"
REPO_ROOT=""
BASELINE=""

if [ -n "$HOST_DB_PATH" ]; then
    if [ ! -d "$HOST_DB_PATH" ]; then
        echo "[e2e:safe] ⚠ E2E_HOST_SCAFFOLD_DB_PATH 不是目录：$HOST_DB_PATH" >&2
        echo "[e2e:safe]   注意它要指向宿主的 scaffold/database/（例如 <host>/engine/scaffold/database），不是 scaffold/。" >&2
        HOST_DB_PATH=""
    else
        REPO_ROOT="$(git -C "$HOST_DB_PATH" rev-parse --show-toplevel 2>/dev/null || true)"
        if [ -z "$REPO_ROOT" ]; then
            echo "[e2e:safe] ⚠ $HOST_DB_PATH 不在任何 git 仓库里，跳过后置还原。" >&2
            HOST_DB_PATH=""
        else
            BASELINE="$(mktemp)"
            git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null \
                | awk '/^\?\? /{print substr($0,4)}' > "$BASELINE"
            # 注意 ${...} 花括号不能省：变量名紧挨全角标点时，bash 会把多字节字符的首字节
            # 吞进变量名（`$REPO_ROOT；` → 未定义变量 REPO_ROOT<乱码>），set -u 下直接报错。
            echo "[e2e:safe] 宿主仓 ${REPO_ROOT}；基线未跟踪文件 $(wc -l < "$BASELINE" | tr -d ' ') 项（跑完只清理新增的）"
        fi
    fi
fi

cleanup() {
    local rc=$?

    if [ -n "$HOST_DB_PATH" ] && [ -n "$REPO_ROOT" ]; then
        # 1) 已跟踪文件：只回滚 scaffold/database 那一层（刻意**不** checkout 整个宿主仓 ——
        #    开发者手头常有别的未提交改动，别替他做主回滚）
        git -C "$HOST_DB_PATH" checkout . >/dev/null 2>&1

        # 2) 未跟踪产物：只删本次新出现的
        local removed=0 path
        while IFS= read -r path; do
            case "$path" in '' | '.' | '..' | /*) continue ;; esac      # 防御：空值 / 绝对路径不碰
            if [ -s "$BASELINE" ] && grep -Fxq -- "$path" "$BASELINE"; then
                continue                                                # 跑之前就有 → 不是我们造的
            fi
            if rm -rf -- "$REPO_ROOT/$path"; then
                removed=$((removed + 1))
                echo "[e2e:safe]   - 清理 $path"
            fi
        done < <(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null | awk '/^\?\? /{print substr($0,4)}')

        if [ "$removed" -eq 0 ]; then
            echo "[e2e:safe] 无新增未跟踪产物"
        else
            echo "[e2e:safe] 已清理本次新增的未跟踪产物 ${removed} 项"
        fi
    fi

    [ -n "$BASELINE" ] && rm -f "$BASELINE"

    exit "$rc"
}

trap cleanup EXIT

playwright test "$@"
