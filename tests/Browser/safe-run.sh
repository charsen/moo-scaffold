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
# ⚠ 为什么清理段**不许静默失败**（2026-09-20 实测教训，改这条之前先读）：
#   旧版第一步是 `git -C "$HOST_DB_PATH" checkout . >/dev/null 2>&1`。实测一轮 e2e 后宿主的
#   `engine/scaffold/database/Platform.yaml` 与 `.snapshots/Platform.yaml` 仍留在 ` M`，
#   而脚本照常打「已清理本次新增的未跟踪产物 3 项」——**完全看不出 checkout 没生效**
#   （stderr 被 2>&1 吞了；手工重跑同一条命令立刻成功，`Updated 2 paths from the index`）。
#   ⇒ 于是这一版做两件事：① 回滚失败时把 git 的原话与后果打出来；② 跑完主动做**差集自证**，
#   把「已还原」从一句口号变成可核对的结论。
#
# 用法（package.json 的 test:e2e:safe 会调它，也可直接 bash 调）：
#   E2E_HOST_SCAFFOLD_DB_PATH=<宿主>/engine/scaffold/database npm run test:e2e:safe [-- <playwright 参数>]
#
# 注意 E2E_HOST_SCAFFOLD_DB_PATH 指的是宿主 **`scaffold/database/`** 那一级（不是 `scaffold/`），
# 因为 designer.spec 会直接 `path.resolve(dbDir, '<Schema>.yaml')` 去清理自己造的 yaml。
set -uo pipefail

HOST_DB_PATH="${E2E_HOST_SCAFFOLD_DB_PATH:-}"
REPO_ROOT=""
BASELINE=""          # 宿主仓**未跟踪**文件基线：跑完只删「不在基线里」的那些
DB_BASELINE=""       # 宿主 scaffold/database 那一层**跑前就已脏**的清单：跑完自证拿它做差集
DB_REL=""            # 上面那一层相对**仓根**的路径（git pathspec 走 cwd 相对语义，必须换过来）

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

            # 相对**仓根**的路径。自己拼前缀会被 macOS 的 /tmp → /private/tmp 软链坑到
            # （实测 $REPO_ROOT=/private/tmp/... 而 $HOST_DB_PATH=/tmp/... ⇒ 前缀匹配不上），
            # 所以交给 git 算：`--show-prefix` 给的就是「相对仓根 + 结尾斜杠」。
            DB_REL="$(git -C "$HOST_DB_PATH" rev-parse --show-prefix 2>/dev/null || true)"
            DB_REL="${DB_REL%/}"
            # 兜底：万一拿不到（异常仓 / 老 git），退回原样路径 —— git 也接受仓内的绝对 pathspec。
            [ -n "$DB_REL" ] || DB_REL="$HOST_DB_PATH"

            # 跑前那一层的脏清单。**为什么必须有**：自证要能区分「本次 e2e 的残留」与
            # 「开发者本来就有的未提交改动」，否则每跑一次都误报一次，报多了就没人看了。
            DB_BASELINE="$(mktemp)"
            git -C "$REPO_ROOT" status --porcelain=v1 -- "$DB_REL" 2>/dev/null \
                | awk '{print substr($0,4)}' > "$DB_BASELINE"

            # 注意 ${...} 花括号不能省：变量名紧挨全角标点时，bash 会把多字节字符的首字节
            # 吞进变量名（`$REPO_ROOT；` → 未定义变量 REPO_ROOT<乱码>），set -u 下直接报错。
            echo "[e2e:safe] 宿主仓 ${REPO_ROOT}；基线未跟踪文件 $(wc -l < "$BASELINE" | tr -d ' ') 项（跑完只清理新增的）"
            echo "[e2e:safe] 还原层 ${DB_REL}；跑前已有改动 $(grep -c . "$DB_BASELINE" || true) 项（跑完据此判残留）"
        fi
    fi
fi

cleanup() {
    local rc=$?
    local checkout_err residue

    if [ -n "$HOST_DB_PATH" ] && [ -n "$REPO_ROOT" ]; then
        # 1) 已跟踪文件：只回滚 scaffold/database 那一层（刻意**不** checkout 整个宿主仓 ——
        #    开发者手头常有别的未提交改动，别替他做主回滚）
        #
        #    成功照旧静默（不给每轮 e2e 加噪音），失败必须说话 —— 见文件头「不许静默失败」。
        if ! checkout_err="$(git -C "$HOST_DB_PATH" checkout . 2>&1)"; then
            echo "[e2e:safe] ⚠ 回滚已跟踪文件失败：$HOST_DB_PATH" >&2
            [ -n "$checkout_err" ] && echo "[e2e:safe]   git: $checkout_err" >&2
        fi

        # 2) 未跟踪产物：只删本次新出现的
        local removed=0 path rm_err
        while IFS= read -r path; do
            case "$path" in '' | '.' | '..' | /*) continue ;; esac      # 防御：空值 / 绝对路径不碰
            if [ -s "$BASELINE" ] && grep -Fxq -- "$path" "$BASELINE"; then
                continue                                                # 跑之前就有 → 不是我们造的
            fi
            if rm_err="$(rm -rf -- "$REPO_ROOT/$path" 2>&1)"; then
                removed=$((removed + 1))
                echo "[e2e:safe]   - 清理 $path"
            else
                # 同样不静默：删不掉要单独说，否则会和下面「已清理 N 项」一起被读成清理成功了。
                # 注意 rm 的报错**没有结尾换行**，直接让它写 stderr 会把下一行输出挤到同一行上。
                echo "[e2e:safe] ⚠ 清理失败：$path" >&2
                [ -n "$rm_err" ] && echo "[e2e:safe]   $rm_err" >&2
            fi
        done < <(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all 2>/dev/null | awk '/^\?\? /{print substr($0,4)}')

        if [ "$removed" -eq 0 ]; then
            echo "[e2e:safe] 无新增未跟踪产物"
        else
            echo "[e2e:safe] 已清理本次新增的未跟踪产物 ${removed} 项"
        fi

        # 3) 差集自证：跑完这一层不该再留下「基线里没有」的改动。
        #    上面两步都是「打一句话就当中立」，而 2026-09-20 实测过它会说谎；这里把结论变成
        #    可核对的差集。只报基线外的路径 ⇒ 开发者本来就有的未提交改动不会触发误报。
        residue="$(git -C "$REPO_ROOT" status --porcelain=v1 -- "$DB_REL" 2>/dev/null \
            | awk '{print substr($0,4)}' \
            | while IFS= read -r p; do
                  [ -n "$p" ] || continue
                  grep -Fxq -- "$p" "$DB_BASELINE" 2>/dev/null || printf '%s\n' "$p"
              done)"
        if [ -n "$residue" ]; then
            echo "[e2e:safe] ⚠ 宿主 ${DB_REL} 仍有未还原的改动（本次 e2e 的 churn 没清干净）：" >&2
            while IFS= read -r p; do
                echo "[e2e:safe]   - $p" >&2
            done <<< "$residue"
            echo "[e2e:safe]   ⇒ 手动核对：git -C \"${REPO_ROOT}\" status -- \"${DB_REL}\"" >&2
        fi
    fi

    [ -n "$BASELINE" ] && rm -f "$BASELINE"
    [ -n "$DB_BASELINE" ] && rm -f "$DB_BASELINE"

    exit "$rc"
}

trap cleanup EXIT

playwright test "$@"
