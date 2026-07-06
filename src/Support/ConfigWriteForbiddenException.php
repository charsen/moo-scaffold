<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use RuntimeException;

/**
 * ConfigManager 写入被环境拒绝时抛出（plan 18 §二决策 #9）。
 *
 * 触发条件（任一）：
 *   - APP_ENV=production
 *   - config('scaffold.config_ui.readonly') = true
 *
 * Controller 应捕获后返回 flash error，UI 顶部展示。
 */
class ConfigWriteForbiddenException extends RuntimeException {}
