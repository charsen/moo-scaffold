# 对比度核对（WCAG AA）

> 每次调色 token 后手工核对几个关键对比项。

## 工具

- https://webaim.org/resources/contrastchecker/
- Chrome DevTools → Inspect → 颜色拾取器（自动显示对比度）
- Lighthouse Accessibility 报告

## 阈值

- 正文 / 重要文字：WCAG AA = **4.5:1** 以上
- 大字（18pt+ / 14pt+ bold）：WCAG AA = **3:1** 以上

## 已知风险 / 历史检查

| 组合 | 对比度 | 是否达标 | 备注 |
|---|---|---|---|
| `--text-light: #98a2b3` on `--bg-card: #fff` | 2.85:1 | ❌ | 已修（v1 阶段 5） |
| `--accent-text: #b45309` on `--accent-bg: #fff7ed` | 6.1:1 | ✅ | dict 模块 icon-box 用 |
| `--text-body` on `--bg-card` | （需核对） | ? | 默认正文 |
| `--text-desc` on `--bg-card` | （需核对） | ? | 副文字 |
| `--accent-hover-border` on `--bg-card-gradient` | （需核对） | ? | hover 边框 |

## 暗色主题专项

dark 主题色 token 跟 light 不同，需要独立核对：

| 组合 | dark 对比度 | 备注 |
|---|---|---|
| `--text-body` on `--bg-card` | （需核对） | dark 默认正文 |
| `--text-desc` on `--bg-card` | （需核对） | dark 副文字 |
| `--accent-text` on `--accent-bg` | （需核对） | dark 主题色 |

## 历史记录

| 日期 | 调整项 | 对比度结果 |
|---|---|---|
| 2026-05-13 | 对比度审计初版（commit `c5a4cc6`） | `--text-light` 修复达 AA |

后续每次调色都在此追加一行。
