<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 角色
 *
 * @description 存储授权角色信息
 *
 * @author Charsen <https://github.com/charsen>
 *
 * @date   2024-02-28 17:28:43
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('moo_scaffold_apis', static function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 16)->default('admin')->comment('APP');
            $table->string('api_operation_id', 96)->comment('操作ID，全局唯一');
            $table->string('api_name', 96)->comment('接口名称');
            $table->string('api_summary', 96)->nullable()->comment('接口概述');
            $table->string('api_description', 256)->nullable()->comment('接口详细描述');
            $table->string('api_request_method', 64)->comment('请求方式');
            $table->string('api_uri', 192)->comment('URI');
            $table->string('api_route_name', 96)->nullable()->comment('路由名称');
            $table->string('api_controller', 192)->comment('控制器');
            $table->string('api_action', 192)->comment('动作');
            $table->json('api_parameters')->nullable()->comment('请求参数');
            $table->string('api_status', 16)->nullable()->comment('本地状态');
            $table->string('x_version', 16)->nullable()->comment('ApiFox版本');
            $table->string('x_import_format', 16)->nullable()->comment('导入格式');
            $table->string('x_status', 16)->nullable()->comment('接口状态');
            $table->string('x_folder', 96)->nullable()->comment('所在目录');
            $table->string('x_run_rul', 192)->nullable()->comment('运行网址');
            $table->timestamp('x_created_at')->nullable()->comment('创建时间');
            $table->timestamp('x_updated_at')->nullable()->comment('更新时间');
            $table->unsignedInteger('x_updated_times')->default(0)->comment('更新次数');
            $table->timestamps();

            $table->index('app_name', 'app_name');
            $table->index('api_operation_id', 'api_operation_id');
            $table->index('api_request_method', 'api_request_method');
            $table->index('api_route_name', 'api_route_name');
            $table->index('x_status', 'x_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moo_scaffold_apis');
    }
};
