<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-27 17:12
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-08 17:38
 * @Description: 控制器增量代码生成器
 */

namespace Mooeen\Scaffold\Adder;

use Illuminate\Support\Str;
use Mooeen\Scaffold\Utility;

class ControllerAdder extends Adder
{
    private array $config;

    private bool $is_collection = false;

    public function start($app, $folder, $controller, $new_controller, $action, $request_name, $resource_name, ?string $origin = null): array|bool
    {
        // plan-53 出身:包控制器落包 Controllers/Admin(平铺);写权硬线把闸
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);

        $this->config = $this->utility->getConfig("controller.{$app}");
        $folder       = $folder === '<ROOT_PATH>' ? '' : $folder;

        if ($new_controller) {
            $controller = Utility::ensureControllerSuffix($controller);
            $controller = ucfirst($controller);
            $file_path  = $this->buildNewController($folder, $controller);
        } elseif ($this->originCtx !== null) {
            $file_path = rtrim($this->originCtx->pathFor('controller'), '/') . '/' . $controller . '.php';
        } else {
            $file_path = base_path('/') . $this->config['path'] . $controller . '.php';
        }
        $relative_file_path = $this->relDisplay($file_path, $this->originCtx);
        $file_codes         = file($file_path);
        $action_added       = false;

        $this->replaceUse($file_path, $file_codes, $request_name, $resource_name);

        if ($this->hasFunction($file_codes, $action)) {
            $this->console()->exists("Action {$action}", 'Already exists');
        } else {
            $end_line    = $this->getEndLine($file_codes);
            $action_code = $this->getActionFunction($action, $request_name, $resource_name);
            $this->replaceLine($action_code, $file_codes, $end_line);
            $action_added = true;
        }

        $request_name  = empty($request_name) ? '' : "{$request_name} \$request";
        $resource_name = ($this->is_collection) ? 'BaseResourceCollection' : $resource_name;

        $this->filesystem->put($file_path, implode('', $file_codes));
        if ($action_added) {
            $this->console()->added($relative_file_path, "@{$action}({$request_name}): {$resource_name}");
        }

        return [
            'class' => $this->originCtx !== null
                ? $this->originCtx->namespaceFor('controller') . '\\' . basename($controller)
                : $this->utility->formatNameSpace($this->config['path'] . $controller),
            'action' => $action,
        ];
    }

    private function buildNewController($folder, $controller): string
    {
        // plan-53:包控制器平铺在包 Controllers/Admin 下(选的"目录"是包本身,非子目录)
        if ($this->originCtx !== null) {
            $path = rtrim($this->originCtx->pathFor('controller'), '/');
        } else {
            $path = base_path('/') . $this->config['path'] . $folder;
            $path = rtrim($path, '/');
        }

        // 带目录生成
        if (str_contains($controller, '/')) {
            [$folder, $controller] = explode('/', $controller);
            $controller            = ucfirst($controller);
            $path                  = $path . '/' . $folder;
        }

        $this->checkDirectory($path);

        $controller_file          = $path . '/' . $controller . '.php';
        $controller_relative_file = $this->relDisplay($controller_file, $this->originCtx);
        $namespace_pre            = $this->originCtx !== null
            ? $this->originCtx->namespaceFor('controller')
            : $this->utility->formatNameSpace(rtrim($this->config['path'] . $folder, '/'));

        if ($this->filesystem->exists($controller_file)) {
            $this->console()->exists($controller_relative_file, 'Controller 已存在');

            return $controller_file;
        }

        $controller = Utility::stripControllerSuffix($controller);
        $meta       = [
            'author'               => $this->utility->getConfig('author'),
            'date'                 => date('Y-m-d H:i'),
            'package_name'         => $this->config['name']['zh-CN'],
            'package_en_name'      => $this->config['name']['en'],
            'module_name'          => $folder,
            'module_en_name'       => $folder,
            'entity_name'          => $controller,
            'entity_en_name'       => $controller,
            'namespace'            => "{$namespace_pre}",
            'use_controller_trait' => "{$namespace_pre}\\Traits\\{$controller}Trait",
            'use_base_controller'  => $this->utility->getConfig('class.controller'),
            'controller_name'      => $controller,
        ];

        $this->buildNewControllerTrait($path, $controller, $namespace_pre);

        $content = $this->buildStub($meta, $this->getStub('controller-adder'));
        $this->filesystem->put($controller_file, $content);
        $this->console()->created($controller_relative_file);

        return $controller_file;
    }

    private function buildNewControllerTrait($path, $controller, $namespace)
    {
        $this->checkDirectory($path . '/Traits/');
        $file_path = $path . '/Traits/' . $controller . 'Trait.php';

        if ($this->filesystem->exists($file_path)) {
            $this->console()->exists(str_replace(base_path('/'), './', $file_path), 'Controller trait 已存在');

            return;
        }

        $this->checkDirectory($path);

        $codes   = ['<?php declare(strict_types=1);', ''];
        $codes[] = "namespace {$namespace}\Traits;";
        $codes[] = '';
        $codes[] = "trait {$controller}Trait";
        $codes[] = '{';
        $codes[] = $this->getTabs(1) . '//...';
        $codes[] = '}';
        $codes[] = '';

        $this->filesystem->put($file_path, implode(PHP_EOL, $codes));
    }

    private function replaceUse($file_path, &$file_codes, &$request_name, &$resource_name): void
    {
        $use_line  = $this->getFirstUseLine($file_codes);
        $use_codes = [];

        $request_use = $this->buildRequest($file_path, $request_name, $file_codes);
        if ($request_use !== '') {
            $use_codes[] = $request_use;
        }

        $resource_use = $this->buildResource($file_path, $resource_name, $file_codes);
        if ($resource_use !== '') {
            $use_codes[] = $resource_use;
        }

        if ($this->is_collection && ! $this->hasUseClass($file_codes, 'BaseResourceCollection')) {
            $use_codes[] = 'use Mooeen\Scaffold\Foundation\BaseResourceCollection;';
        }

        if (! empty($use_codes)) {
            $this->replaceLine(implode(PHP_EOL, $use_codes), $file_codes, $use_line);
        }

    }

    private function buildResource($controller_path, &$resource_name, $file_codes): bool|string
    {
        if (empty($resource_name)) {
            if ($this->hasUseClass($file_codes, 'BaseResource')) {
                return '';
            }
            $resource_name = 'BaseResource';

            return 'use Mooeen\Scaffold\Foundation\BaseResource;';
        }

        $resource_name = ucfirst($resource_name);
        if (Str::endsWith($resource_name, 'Collection')) {
            $resource_name       = Str::replaceEnd('Collection', '', $resource_name);
            $this->is_collection = true;
        }
        $resource_name = Str::endsWith($resource_name, 'Resource') ? $resource_name : $resource_name . 'Resource';

        // 如果原来的 controller 已经 use ，则不用生成
        if ($this->hasUseClass($file_codes, $resource_name)) {
            return '';
        }

        // 检查全局的 Resource 是否已存在，存在则使用全局的
        if ($global_resource = $this->checkGlobalResource($resource_name)) {

            return $global_resource;
        }

        $config    = $this->getResourceConfig($controller_path);
        $file_path = $config['full'] . '/' . $resource_name . '.php';

        $codes   = ['<?php declare(strict_types=1);', ''];
        $codes[] = "namespace {$config['namespace']};";
        $codes[] = '';
        $codes[] = 'use Illuminate\Http\Request;';
        $codes[] = 'use Mooeen\Scaffold\Foundation\BaseResource;';
        $codes[] = '';
        $codes[] = "class {$resource_name} extends BaseResource";
        $codes[] = '{';
        $codes[] = $this->getTabs(1) . '//...';
        $codes[] = '';
        $codes[] = $this->getTabs(1) . 'public function toArray(Request $request): array';
        $codes[] = $this->getTabs(1) . '{';
        $codes[] = $this->getTabs(2) . '$data = collect([';
        $codes[] = $this->getTabs(3) . "'id' => \$this->id,";
        $codes[] = $this->getTabs(3) . '//...';
        $codes[] = $this->getTabs(2) . ']);';
        $codes[] = $this->getTabs(2) . 'return $this->filterFields($data);';
        $codes[] = $this->getTabs(1) . '}';
        $codes[] = '}';
        $codes[] = '';

        $this->filesystem->put($file_path, implode(PHP_EOL, $codes));

        return "use {$config['namespace']}\\{$resource_name};";
    }

    private function buildRequest($controller_path, &$request_name, $file_codes): string
    {
        if (empty($request_name)) {
            return '';
        }

        $request_name = ucfirst($request_name);
        $request_name = Str::endsWith($request_name, 'Request') ? $request_name : $request_name . 'Request';

        // 如果原来的 controller 已经 use ，则不用生成
        if ($this->hasUseClass($file_codes, $request_name)) {
            return '';
        }

        $config    = $this->getRequestConfig($controller_path);
        $file_path = $config['full'] . '/' . $request_name . '.php';

        $codes   = ['<?php declare(strict_types=1);', ''];
        $codes[] = "namespace {$config['namespace']};";
        $codes[] = '';
        $codes[] = 'use Mooeen\Scaffold\Foundation\FormRequest;';
        $codes[] = '';
        $codes[] = "class {$request_name} extends FormRequest";
        $codes[] = '{';
        $codes[] = $this->getTabs(1) . '//...';
        $codes[] = '';
        $codes[] = $this->getTabs(1) . 'public function rules(): array';
        $codes[] = $this->getTabs(1) . '{';
        $codes[] = $this->getTabs(2) . 'return [';
        $codes[] = $this->getTabs(3) . '//...';
        $codes[] = $this->getTabs(2) . '];';
        $codes[] = $this->getTabs(1) . '}';
        $codes[] = '}';
        $codes[] = '';

        $this->filesystem->put($file_path, implode(PHP_EOL, $codes));

        return "use {$config['namespace']}\\{$request_name};";
    }

    private function getActionFunction($action, $request, $resource): string
    {
        $resource = empty($resource) ? 'BaseResource' : $resource;
        $request  = empty($request) ? '' : "{$request} \$request";

        $data_code[] = ''; // 空一行
        $data_code[] = $this->getTabs(1) . '/**';
        $data_code[] = $this->getTabs(1) . " * {$action}";
        $data_code[] = $this->getTabs(1) . ' */';

        $fn_return   = ($this->is_collection) ? 'BaseResourceCollection' : $resource;
        $data_code[] = $this->getTabs(1) . "public function {$action}($request): {$fn_return}";
        $data_code[] = $this->getTabs(1) . '{';
        if (! empty($request)) {
            $data_code[] = $this->getTabs(2) . '$validated = $request->validated();';
        }

        $data_code[] = $this->getTabs(2) . '$result = [];';
        $data_code[] = $this->getTabs(2) . ($this->is_collection ? "return {$resource}::collection(\$result);" : "return {$resource}::make(\$result);");

        $data_code[] = $this->getTabs(1) . '}';

        return implode(PHP_EOL, $data_code);
    }

    private function getFolder($file_path): string
    {
        // plan-53:包路径前缀是包 Controllers/Admin 目录(平铺,子目录即控制器名)
        $prefix = $this->originCtx !== null
            ? rtrim($this->originCtx->pathFor('controller'), '/') . '/'
            : base_path('/') . $this->config['path'];

        $paths      = str_replace($prefix, '', $file_path);
        $tmp        = explode('/', $paths);
        $controller = array_pop($tmp);
        $controller = Str::replaceEnd('Controller.php', '', $controller);

        // <ROOT_PATH> 时 tmp 为空
        $tmp_str = empty($tmp) ? '' : implode('/', $tmp) . '/';

        return $tmp_str . $controller . '/';
    }

    private function getRequestConfig($controller_path): array
    {
        $folder = $this->getFolder($controller_path);

        // plan-53:包 Request 落 src/Http/Requests/{Controller}/(无 module 段)
        if ($this->originCtx !== null) {
            $full_path = rtrim($this->originCtx->pathFor('request'), '/') . '/' . $folder;
            $this->checkDirectory($full_path);

            return [
                'full'      => rtrim($full_path, '/'),
                'relative'  => $this->relDisplay($full_path, $this->originCtx),
                'namespace' => $this->originCtx->namespaceFor('request') . '\\' . trim(str_replace('/', '\\', $folder), '\\'),
            ];
        }

        $path      = $this->config['request_path'] . $folder;
        $full_path = base_path('/') . $path;

        $this->checkDirectory($full_path);

        return [
            'full'      => $full_path,
            'relative'  => './' . $path,
            'namespace' => trim($this->utility->formatNameSpace($path), '\\'),
        ];
    }

    private function getResourceConfig($controller_path): array
    {
        // plan-53:包 Resource 平铺 src/Http/Resources(与 CreateResourceGenerator 同约定)
        if ($this->originCtx !== null) {
            $full_path = rtrim($this->originCtx->pathFor('resource'), '/');
            $this->checkDirectory($full_path);

            return [
                'full'      => $full_path,
                'relative'  => $this->relDisplay($full_path, $this->originCtx),
                'namespace' => $this->originCtx->namespaceFor('resource'),
            ];
        }

        $folder    = $this->getFolder($controller_path);
        $path      = $this->config['resource_path'] . $folder;
        $full_path = base_path('/') . $path;

        $this->checkDirectory($full_path);

        return [
            'full'      => $full_path,
            'relative'  => './' . $path,
            'namespace' => trim($this->utility->formatNameSpace($path), '\\'),
        ];
    }
}
