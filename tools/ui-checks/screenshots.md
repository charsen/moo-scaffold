# 手工截图基线矩阵

> 用浏览器手工截图，建议保存到 `./docs/ui-baseline/{page}-{theme}-{width}.png`。
> 每次大改 UI 前先跑一遍当前态作"前"基线；改完后再跑一遍作"后"对照。

## 一、所有页面 light/dark × 1280/1440 矩阵

| 页面 | light × 1280 | light × 1440 | dark × 1280 | dark × 1440 |
|---|---|---|---|---|
| `/scaffold/` (dashboard) | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/db` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/dictionaries` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/acl?app=admin` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/api?app=admin` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/api/show?app=admin&f=...&c=...&a=...` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/api/request?app=admin&...` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/api/records?app=admin` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/routes?app=admin` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/runtime` | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/login` | ☐ | ☐ | ☐ | ☐ |

## 二、P0 页面追加 1024 + 1800 窄屏/宽屏

重点检查长 URL / 长 token / 大 JSON 不崩布局。

| 页面 | light × 1024 | light × 1800 | dark × 1024 | dark × 1800 |
|---|---|---|---|---|
| `/scaffold/api/request` （含长 URL / 长 token / 大 JSON 响应） | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/dictionaries` （长字段名 + 长枚举值） | ☐ | ☐ | ☐ | ☐ |
| `/scaffold/routes` （高密路由列表） | ☐ | ☐ | ☐ | ☐ |

## 三、手工交互校验清单

不能脚本化的交互，每次大改 UI 后过一遍：

- [ ] 主题切换：light / dark 来回切，所有页面无错位 / 无对比度问题
- [ ] aside 侧边栏：模块切换 hover / active 状态橙色边界正确
- [ ] dashboard 历史 Tab：URL `?history_app=` 切换 + 浏览器刷新保持 active
- [ ] db Tab：表 / 字典切换（dict 改独立路由后此项可能 N/A）
- [ ] api/request 接口选择：sidebar 点接口 → 参数表加载 → 自动 send（GET）/ 等手动
- [ ] api/request 发送按钮：loading 期间禁用避免重复点击
- [ ] api/request 复制按钮：复制响应到剪贴板有成功提示
- [ ] api/request 最近记录抽屉：发请求后开抽屉看到记录 → 点行回填 → 不自动 send
- [ ] route 搜索：输入关键词即时 filter，搜索按钮 focus + 重新 filter
- [ ] route 模块折叠：点 module header 折叠 / 展开，键盘 Enter/Space 同效
- [ ] route 锚点：点 sidebar 模块 → 滚动到对应章节
- [ ] dict 锚点 + scroll spy：滚动时 sidebar 当前模块高亮
- [ ] db/index 表名抽屉：点表名 → 抽屉显示 db.show 详情
- [ ] F12 console：每页打开无 JS error / 资源 404

## 四、Console error 抽查

F12 Console 应该完全 clean（除了第三方 CDN 拦截这类不可控）。每次大改前后过一遍 P0 页面。

## 五、当前态基线（人工填写）

| 日期 | 提交 hash | 截图路径 | 备注 |
|---|---|---|---|
| 2026-05-14 | （未建立） | （未建立） | 后续大改 UI 前补 |
