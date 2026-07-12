<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Concerns;

/**
 * 雪花算法生成 bigint 主键（string 类型避免 JS 精度丢失）。
 *
 * plan 38：moo 系扩展包三件套之一，上移 scaffold 共享（原各包自持复制，单例名 moo-trail/moo-attachment/radar.snowflake 各异）。
 * 取用 scaffold 注册的**共享单例** `scaffold.snowflake`（ScaffoldProvider::register）——所有包共一实例，
 * 反正同源 `SNOW_FLAKE_*` env（同 data_center/worker/start_time），id 空间一致、唯一性靠序列号，本就该一套。
 * 此处只取用，不每次 creating 重建实例 / 重读 config。
 *
 * 配置 config('scaffold.snowflake.*') 复用 host 已有的 SNOW_FLAKE_* env：
 *   SNOW_FLAKE_DATA_CENTER_ID 默认 1 / SNOW_FLAKE_WORKER_ID 默认 1 / SNOW_FLAKE_START_TIME 默认 '2021-10-10'
 * 【生产必须用跨进程共享 cache（Redis）】保证机内同毫秒序列号防撞。
 */
trait UsingSnowFlakePrimaryKey
{
    public static function bootUsingSnowFlakePrimaryKey(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) app('scaffold.snowflake')->id();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
