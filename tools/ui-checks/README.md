# UI Checks 轻量回归门禁

> 用 shell 脚本 + 手工截图清单做轻量 UI 门禁，覆盖 HTTP 冒烟、静态规则、资源引用和 CSS 体积预算。

## 用法

```bash
cd ./tools/ui-checks

# 1. HTTP smoke：每页返回 2xx/3xx，不爆 Laravel exception page
HOST=http://localhost ./smoke-http.sh

# 2. 静态规则扫描：业务视图 <style>=0 / 硬编码 hex=0 / padding Npx=0
./static-guards.sh

# 3. 资源引用检查：CSS/JS 文件存在
./asset-guards.sh

# 4. CSS 体积预算：gzip ≤ 60 KB
./css-budget.sh

# 一键全跑：
./run-all.sh
```

## 文件清单

- `pages.txt` —— smoke-http.sh 用的 URL 清单
- `smoke-http.sh` —— curl 跑每个 URL，检查 HTTP 状态码 + 是否含 Laravel 异常页特征
- `static-guards.sh` —— 业务视图静态规则扫描
- `asset-guards.sh` —— 引用的 CSS / JS 资源文件是否存在
- `css-budget.sh` —— `index.css` gzip 体积预算
- `run-all.sh` —— 一键串行跑所有上述脚本
- `screenshots.md` —— 手工截图基线矩阵（人工填写）
- `contrast-notes.md` —— 人工对比度核对记录（人工填写）

## 退出码

每个脚本退出码：
- `0` 全部通过
- `1` 任一项不通过

`run-all.sh` 任何一个子脚本失败即整体失败，方便接入 CI。

## 不覆盖的范围

- Console error / JS runtime error（脚本无法跨浏览器静态检测，留给手工截图矩阵的 F12 检查项）
- 像素级 visual diff（用 Playwright 才行；本套门禁明确不做）
- 跨设备 / 跨浏览器兼容（scaffold 桌面端最小 1024px，本套不测移动端）

## 与发布门禁的对应

| 检查项 | 对应脚本 |
|---|---|
| `tools/ui-checks/smoke-http.sh` 可重复运行 | `smoke-http.sh` ✓ |
| `tools/ui-checks/static-guards.sh` 可重复运行 | `static-guards.sh` ✓ |
| `tools/ui-checks/css-budget.sh` 可重复运行 | `css-budget.sh` ✓ |
| `tools/ui-checks/screenshots.md` 手工截图基线 | `screenshots.md` ✓（人工填） |
