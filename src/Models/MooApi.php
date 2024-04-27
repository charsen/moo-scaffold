<?php

namespace Mooeen\Scaffold\Models;

use Illuminate\Database\Eloquent\Model;

class MooApi extends Model
{
    /**
     * 表格名称
     *
     * @var string
     */
    protected $table = 'moo_scaffold_apis';

    /**
     * 指定字段默认值
     *
     * @var array
     */
    protected $attributes = [
        'app_name'        => 'admin',
        'x_updated_times' => 0,
    ];

    /**
     * 属性转换
     *
     * @var array
     */
    protected $casts = [
        'api_parameters' => 'json',
        'x_created_at'   => 'datetime:Y-m-d H:i:s',
        'x_updated_at'   => 'datetime:Y-m-d H:i:s',
    ];

    protected $fillable = [
        'app_name',
        'api_operation_id',
        'api_name',
        'api_summary',
        'api_description',
        'api_request_method',
        'api_uri',
        'api_route_name',
        'api_controller',
        'api_action',
        'api_parameters',
        'api_status',
        'x_version',
        'x_import_format',
        'x_status',
        'x_folder',
        'x_run_rul',
        'x_created_at',
        'x_updated_at',
        'x_updated_times',
    ];
}
