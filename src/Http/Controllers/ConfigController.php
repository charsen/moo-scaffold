<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\AiSettingStore;
use Mooeen\Scaffold\Support\ConfigManager;
use Mooeen\Scaffold\Support\ConfigWriteForbiddenException;
use Mooeen\Scaffold\Utility;

/**
 * Scaffold 配置 Web 管理（plan 18 §3.2.4）
 *
 * 路由：index / show / update / envMirror。
 * 历史回溯走 git（scaffold/ 入 git），不在 UI 提供 backup/restore。
 */
class ConfigController extends Controller
{
    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly ConfigManager $manager,
        private readonly AiSettingStore $aiStore,
    ) {
        parent::__construct($utility, $filesystem);

        // config_ui.enabled=false → 配置 UI 整体不可访问(字段描述如此承诺,之前是 dead 旋钮,
        // 2026-06-09 接上)。关掉后改 .env 的 SCAFFOLD_CONFIG_UI=true 或编辑 config/scaffold.php 重开。
        if (! config('scaffold.config_ui.enabled', true)) {
            abort(404);
        }
    }

    /**
     * 配置主页（单页 + 锚点）：左侧 TOC，右侧把每个分组的字段表依次铺开，
     * 每个分组独立 form，提交后 flash 回到 #group-{key} 锚点。
     * .env 镜像独立页（/config/env）。历史回溯走 git。
     */
    public function index(Request $request)
    {
        $groupDefs = $this->manager->groups();

        $groups  = [];
        $summary = [];
        foreach (array_keys($groupDefs) as $key) {
            $g            = $this->manager->read($key);
            $groups[$key] = $g;
            $envCount     = 0;
            foreach ($g['fields'] as $f) {
                if ($f['source'] === 'env') {
                    $envCount++;
                }
            }
            $summary[$key] = [
                'key'         => $g['key'],
                'label'       => $g['label'],
                'desc'        => $g['desc'],
                'field_count' => count($g['fields']),
                'env_count'   => $envCount,
            ];
        }

        return $this->view('config.index', [
            'uri'           => $request->getPathInfo(),
            'groups'        => $groups,
            'summary'       => $summary,
            'readonly'      => $this->manager->isReadonly(),
            'is_prod'       => function_exists('app') && app()->environment('production'),
            'active'        => 'overview',
            'flash_group'   => $request->session()->pull('flash_group'),
            'flash_message' => $request->session()->pull('flash_message'),
            'flash_error'   => $request->session()->pull('flash_error'),
            'flash_diff'    => $request->session()->pull('flash_diff'),
            'flash_skipped' => $request->session()->pull('flash_skipped'),
        ]);
    }

    /**
     * 兼容旧书签：/scaffold/config/{group} 直接 302 到 /scaffold/config#group-{key}
     */
    public function show(Request $request, string $group): RedirectResponse
    {
        $data = $this->manager->read($group);
        if ($data === null) {
            abort(404);
        }

        return redirect()->to(route('scaffold.config') . '#group-' . $group);
    }

    /**
     * 提交分组字段更新（POST /scaffold/config/{group}）。
     * - env 字段写入 .env，file 字段写入 engine/config/scaffold.php（manager 分发）
     * - production / readonly 时整体返 403 flash
     */
    public function update(Request $request, string $group): RedirectResponse
    {
        // 保存后回到 config 主页对应分组锚点
        $back = redirect()->to(route('scaffold.config') . '#group-' . $group);
        $request->session()->flash('flash_group', $group);

        $by = $this->currentOperator($request) ?? 'unknown';

        try {
            // plan-40 §五 F5:字段白名单防旧字段污染(下方 intersect)。
            // F1 的长度 cap 已下沉到 ConfigManager::write(cast 后按类型执行)——
            // 原 'fields.*' => 'string' 规则会把 map 编辑器的嵌套数组整组拒掉,
            // hosts 配置自 plan-40 起在 UI 无法保存(2026-06-10 修)。
            $validated = $request->validate([
                'fields' => 'array',
            ]);
            // ConfigManager::read() 返回的 fields 是数字索引数组 [{path, name, source, ...}],
            // 白名单要从每条 field 的 'path' 提取(plan-40 §五 F5 hotfix:之前用 array_keys 错把 [0,1,2] 当 path,
            // 跟 string key 的 validated.fields 取交集恒空 → 所有合法 update 被吞)
            $groupData     = $this->manager->read($group);
            $allowedFields = array_column((array) ($groupData['fields'] ?? []), 'path');
            $values        = $allowedFields
                ? array_intersect_key((array) ($validated['fields'] ?? []), array_flip($allowedFields))
                : (array) ($validated['fields'] ?? []);
            $result = $this->manager->write($group, $values, $by);
            if ($result['written'] === 0) {
                $request->session()->flash('flash_message', '没有变更被写入');
            } else {
                $msg = "已写入 {$result['written']} 处变更";
                if (! empty($result['env_dirty'])) {
                    // env 新值已覆盖进本进程 config(页面回显/本进程逻辑即时生效),
                    // 持久化在 .env;其他常驻进程要重启才读到
                    $msg .= '；.env 已更新（当前进程已生效；php-fpm / queue 等其他常驻进程需重启后生效）';
                }
                $request->session()->flash('flash_message', $msg);
            }
            if (! empty($result['skipped'])) {
                $request->session()->flash('flash_skipped', $result['skipped']);
            }
            if (! empty($result['diff'])) {
                $request->session()->flash('flash_diff', $result['diff']);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // 不落进 Throwable 兜底:那会把英文验证原文直接糊给用户(2026-06-10 修)
            $request->session()->flash('flash_error', '提交数据格式不合法：' . $e->validator->errors()->first());
        } catch (ConfigWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return $back;
    }

    public function envMirror(Request $request)
    {
        return $this->view('config.env', [
            'uri'        => $request->getPathInfo(),
            'rows'       => $this->manager->readEnvMirror(),
            'all_groups' => $this->manager->groups(),
            'readonly'   => $this->manager->isReadonly(),
            'is_prod'    => function_exists('app') && app()->environment('production'),
            'active'     => '__env',
        ]);
    }

    /**
     * AI 配置页（Designer 翻译上游）。配置存 scaffold/ai.yaml（入 git），
     * 不走 env；api_key 永不回显明文（read() 只给 api_key_set 布尔）。
     */
    public function ai(Request $request)
    {
        return $this->view('config.ai', [
            'uri'        => $request->getPathInfo(),
            'settings'   => $this->aiStore->read(),
            'all_groups' => $this->manager->groups(),
            'readonly'   => $this->aiStore->isReadonly(),
            'is_prod'    => function_exists('app') && app()->environment('production'),
            'active'     => '__ai',
            // 展示用项目相对路径(绝对前缀是宿主根、纯噪音;落 base_path 外 Str::after 优雅回退绝对)
            'yaml_path'     => \Illuminate\Support\Str::after($this->aiStore->path(), base_path() . '/'),
            'flash_message' => $request->session()->pull('flash_message'),
            'flash_error'   => $request->session()->pull('flash_error'),
        ]);
    }

    /**
     * 保存 AI 配置（POST /scaffold/config/ai）。
     * api_key 留空 = 保持原值；production / readonly 由 store::assertWritable + middleware 双拒。
     */
    public function updateAi(Request $request): RedirectResponse
    {
        $back = redirect()->route('scaffold.config.ai');

        try {
            $validated = $request->validate([
                // url:http,https 拦 javascript: 等危险 scheme + 无 scheme 的裸域名(跟 hosts map
                // 的 looksLikeHttpUrl 同语义);空值放行(store 兜底回默认)
                'base_url'        => 'nullable|url:http,https|max:2000',
                'api_key'         => 'nullable|string|max:2000',
                'model'           => 'nullable|string|max:200',
                'timeout'         => 'nullable|integer|min:1|max:120',
                'connect_timeout' => 'nullable|integer|min:1|max:120',
                'max_tokens'      => 'nullable|integer|min:1|max:65536',
                'temperature'     => 'nullable|numeric|min:0|max:2',
            ], [
                // 中文校验消息:默认英文原文会被 flash 直接糊给用户(2026-06-10 修)
                'url'     => ':attribute 必须是 http(s):// 开头的合法 URL',
                'integer' => ':attribute 必须是整数',
                'numeric' => ':attribute 必须是数字',
                'min'     => ':attribute 不能小于 :min',
                'max'     => ':attribute 不能大于 :max',
                'string'  => ':attribute 必须是字符串',
            ], [
                'base_url'        => '上游地址',
                'api_key'         => 'API Key',
                'model'           => '模型',
                'timeout'         => '总超时',
                'connect_timeout' => '连接超时',
                'max_tokens'      => '生成上限',
                'temperature'     => '温度',
            ]);
            $this->aiStore->save($validated);
            $request->session()->flash('flash_message', 'AI 配置已保存（改完即时生效，无需重启）');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $request->session()->flash('flash_error', $e->validator->errors()->first());
        } catch (ConfigWriteForbiddenException $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        } catch (\Throwable $e) {
            $request->session()->flash('flash_error', $e->getMessage());
        }

        return $back;
    }
}
