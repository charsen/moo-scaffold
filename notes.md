# notes.md — AI 协作者工作笔记

> 长期记忆：踩过的坑、确认过的做法，一条一行，新的放上面。
> 本仓开源：不写内部项目名、内部域名、密钥。

- 包目录的 `vendor/` 独立于宿主且默认没装；跑 pint/pest 前先在包目录 `composer install`。
- 本仓没有 phpstan/larastan 等静态类型检查；类型问题靠 Pest 测试 + 评审兜底，别去找不存在的配置。
- e2e 一律用 `npm run test:e2e:safe`（结束自动回滚宿主 scaffold 数据）；裸 `test:e2e` 会把宿主数据跑脏。
