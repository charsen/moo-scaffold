<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use RuntimeException;

/**
 * AccountStore 写入被环境拒绝时抛出（plan 18 §二决策 #9）。
 *
 * 触发条件（任一）：
 *   - APP_ENV=production
 *   - config('scaffold.config_ui.readonly') = true
 *
 * Controller 应捕获后返回 403 + 横幅。
 */
class AccountWriteForbiddenException extends RuntimeException {}
