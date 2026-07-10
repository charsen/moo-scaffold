# Changelog

## 2.1.1

- Extension-package controllers now generate and reference an in-package `HandlesResourceActions` trait, removing the package-to-host base-trait dependency.
- Request traits can delegate to package-owned tables (`scaffold.package_request_traits`).
- Internal consistency refactor with no behavior change: unified tri-state file-write reporting, added an `isForced()` command helper, extracted the API debugger's HTTP proxy into its own controller, and decoupled the utility layer from the schema loader.
- Expanded test coverage and hardened a configuration test against persistent test base paths.

## 2.1.0

- Initial public release based on the 2.x line.
- Includes the current schema-driven code generator, scaffold admin UI, database designer, API debugger, ACL tooling, configuration UI, docs center, and moo-monitor-laravel integration.
- Removes non-public workflow notes and handoff material from the public package.
