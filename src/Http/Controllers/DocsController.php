<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Support\DocsRepository;
use Mooeen\Scaffold\Support\Markdown\DocMarkdownRenderer;
use Mooeen\Scaffold\Utility;

/**
 * plan-52 文档中心。
 *
 *   GET  /scaffold/docs?doc=slug      渲染单篇（侧导航 + 正文），无 doc 取第一篇；纯只读，生产可看。
 *   GET  /scaffold/docs/edit?doc=slug 编辑器（doc 空 = 新建）；生产/只读时整页灰 + 红条。
 *   POST /scaffold/docs/save          写入（slug + content）；EnforceScaffoldWritable + repo 双层锁。
 *   POST /scaffold/docs/preview       实时预览渲染（复用 DocMarkdownRenderer，单一真源）。
 *   POST /scaffold/docs/delete        删除（git-tracked，可 restore）。
 *   GET  /scaffold/docs/_diagram      Mermaid 隔离渲染帧（单独放宽 CSP）。
 *   GET  /scaffold/docs/picker        引用 picker 的接口/表 catalog（JSON）。
 */
class DocsController extends Controller
{
    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly DocsRepository $repo,
        private readonly DocMarkdownRenderer $renderer,
    ) {
        parent::__construct($utility, $filesystem);
    }

    public function index(Request $req): View
    {
        $src  = $this->resolveSrc($req);
        $slug = trim((string) $req->query('doc', ''));

        // 无 doc 参数:按源顺序(host 优先)取第一篇非空源的第一篇
        $hasDocs = false;
        foreach ($this->repo->sources() as $s) {
            $first = $this->repo->firstSlug($s['key']);
            if ($first !== null) {
                $hasDocs = true;
                if ($slug === '') {
                    [$slug, $src] = [$first, $s['key']];
                }
                break;
            }
        }

        $doc  = $slug !== '' ? $this->repo->find($slug, $src) : null;
        $html = $doc  !== null ? $this->renderer->render($doc['body']) : null;

        return $this->view('docs.index', array_merge($this->lockFlags(), [
            'tree'         => $this->repo->navTree(),
            'has_docs'     => $hasDocs,
            'current_key'  => $doc !== null ? DocsRepository::docKey($doc['slug'], $src) : null,
            'doc'          => $doc,
            'html'         => $html,
            'not_found'    => $slug !== '' && $doc === null,
            'rel_base'     => $this->repo->relBaseDir($src),
            'src'          => $src,
            'src_writable' => $this->repo->sourceWritable($src),
            'uri'          => $req->getPathInfo(),
        ]));
    }

    public function edit(Request $req): View
    {
        $src   = $this->resolveSrc($req);
        $slug  = trim((string) $req->query('doc', ''));
        $doc   = $slug !== '' ? $this->repo->find($slug, $src) : null;
        $isNew = $doc === null;

        // 新建时可选落点源:host + 可写(软链)包;只读包不进列表
        $writableSources = array_values(array_filter($this->repo->sources(), static fn ($s) => $s['writable']));

        return $this->view('docs.edit', array_merge($this->lockFlags(), [
            'tree'             => $this->repo->navTree(),
            'is_new'           => $isNew,
            'current_key'      => $isNew ? null : DocsRepository::docKey($doc['slug'], $src),
            'current_slug'     => $isNew ? '' : $doc['slug'],
            'raw'              => $isNew ? $this->newDocTemplate() : $doc['raw'],
            'rel_base'         => $this->repo->relBaseDir($src),
            'src'              => $src,
            'src_writable'     => $this->repo->sourceWritable($src),
            'writable_sources' => array_map(fn ($s) => [
                'key'   => $s['key'] ?? '',
                'label' => $this->repo->relBaseDir($s['key']),
            ], $writableSources),
            'uri' => $req->getPathInfo(),
        ]));
    }

    public function save(Request $req): JsonResponse
    {
        $data = $req->validate([
            'slug'    => 'required|string|max:200',
            'content' => 'present|string',
            'src'     => 'nullable|string|max:100',
        ]);

        $src = $this->normalizeSrc($data['src'] ?? '');
        if ($src !== null && ! $this->repo->isKnownSource($src)) {
            return response()->json(['error' => "未知文档源 [{$src}]。"], 422);   // 写操作不静默回退 host
        }
        try {
            $slug = $this->repo->save($data['slug'], (string) $data['content'], $src);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'       => true,
            'slug'     => $slug,
            'redirect' => route('docs.index', ['doc' => $slug] + ($src !== null ? ['src' => $src] : [])),
        ]);
    }

    public function preview(Request $req): JsonResponse
    {
        $data = $req->validate(['content' => 'present|string']);
        $body = $this->repo->parseRaw((string) $data['content'])['body'];

        return response()->json(['html' => $this->renderer->render($body)]);
    }

    public function delete(Request $req): JsonResponse
    {
        $data = $req->validate([
            'slug' => 'required|string|max:200',
            'src'  => 'nullable|string|max:100',
        ]);

        $src = $this->normalizeSrc($data['src'] ?? '');
        if ($src !== null && ! $this->repo->isKnownSource($src)) {
            return response()->json(['error' => "未知文档源 [{$src}]。"], 422);   // 写操作不静默回退 host
        }
        try {
            $this->repo->delete($data['slug'], $src);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'redirect' => route('docs.index', $src !== null ? ['src' => $src] : [])]);
    }

    /** '' / 'host' 归一为 null(host)。 */
    private function normalizeSrc(?string $src): ?string
    {
        $src = trim((string) $src);

        return ($src === '' || $src === 'host') ? null : $src;
    }

    /**
     * 读路径的 ?src=(文档出身源,plan-53):只接受 sources() 里已发现的源,非法回退 host。
     */
    private function resolveSrc(Request $req): ?string
    {
        $src = $this->normalizeSrc((string) $req->query('src', ''));

        return ($src !== null && $this->repo->isKnownSource($src)) ? $src : null;
    }

    public function diagram(): View
    {
        return $this->view('docs._diagram');
    }

    public function picker(SchemaLoader $loader): JsonResponse
    {
        return response()->json([
            'endpoints' => $this->apiCatalog(),
            'tables'    => $this->dbCatalog($loader),
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array{is_prod:bool,is_readonly:bool,locked:bool} */
    private function lockFlags(): array
    {
        $isProd     = function_exists('app') && app()->environment('production');
        $isReadonly = (bool) config('scaffold.config_ui.readonly', false);

        return [
            'is_prod'     => $isProd,
            'is_readonly' => $isReadonly,
            'locked'      => $isProd || $isReadonly,
        ];
    }

    private function newDocTemplate(): string
    {
        // 不写死 group:让 deriveGroup 按保存路径推断(存到 示例/ 下就归「示例」组)。
        // 原模板硬写 group: 未分组 会盖掉路径推断 → 文件夹下新建的文档全落「未分组」。想显式分组的人自行加回 group: 行。
        return "---\ntitle: 新文档\norder: 100\ntags: []\n---\n\n# 新文档\n\n在这里写正文。可用 Markdown、Mermaid 流程图、以及深链 shortcode。\n";
    }

    /**
     * 接口 catalog（引用 picker 用）：扫 scaffold/api/{app}/ 下 yaml。
     *
     * @return list<array{app:string,folder:string,controller:string,action:string,label:string,method:string,target:string,url_debug:string,url_doc:string}>
     */
    private function apiCatalog(): array
    {
        $apps = array_keys((array) $this->utility->getConfig('controller', []));
        $base = $this->utility->getApiPath('schema');
        $out  = [];

        foreach ($apps as $app) {
            $dir = $base . $app . '/';
            if (! $this->filesystem->isDirectory($dir)) {
                continue;
            }
            foreach ($this->filesystem->allFiles($dir) as $file) {
                $bn = $file->getBasename();
                if (! str_ends_with($bn, '.yaml') || str_starts_with($bn, '_')) {
                    continue;
                }
                $folder = str_replace('\\', '/', $file->getRelativePath());
                if ($folder === '') {
                    $folder = 'Index';
                }
                $yaml       = $this->utility->parseYamlFile($file->getPathname());
                $controller = $yaml['controller']['class'] ?? null;
                $actions    = $yaml['actions']             ?? null;
                if (! is_string($controller) || ! is_array($actions)) {
                    continue;
                }
                $cname = (string) ($yaml['controller']['name'] ?? $controller);
                foreach ($actions as $actionKey => $def) {
                    $aname  = is_array($def) ? (string) ($def['name'] ?? $actionKey) : (string) $actionKey;
                    $method = is_array($def) && isset($def['request'][0]) ? (string) $def['request'][0] : '';
                    $target = $app . '/' . ($folder !== 'Index' ? $folder . '/' : '') . $controller . '@' . $actionKey;
                    $params = ['app' => $app, 'f' => $folder, 'c' => $controller, 'a' => $actionKey];
                    $out[]  = [
                        'app'        => $app,
                        'folder'     => $folder,
                        'controller' => (string) $controller,
                        'action'     => (string) $actionKey,
                        'label'      => $cname . ' · ' . $aname,
                        'method'     => $method,
                        'target'     => $target,
                        // 相对 URL,供流程图 mermaid `click 节点 href "..."` 插入(不写死前缀/域名)
                        'url_debug' => route('api.request', $params, false),
                        'url_doc'   => route('api.list', $params, false),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * 数据库 catalog（引用 picker 用）：模块 + 表。
     *
     * @return list<array{module:string,table:?string,label:string,target:string,url:string}>
     */
    private function dbCatalog(SchemaLoader $loader): array
    {
        $out = [];
        foreach ($loader->listModules() as $key => $mod) {
            $name  = (string) ($mod['name'] ?? $key);
            $out[] = ['module' => (string) $key, 'table' => null, 'label' => $name . '（整模块）', 'target' => (string) $key,
                'url'          => route('db.docs', ['schema' => (string) $key], false)];
            try {
                $tables = $loader->loadModuleTables((string) $key);
            } catch (\Throwable) {
                $tables = [];
            }
            foreach ($tables as $tkey => $t) {
                $out[] = [
                    'module' => (string) $key,
                    'table'  => (string) $tkey,
                    'label'  => $name . ' · ' . (string) ($t['name'] ?? $tkey),
                    'target' => $key . '.' . $tkey,
                    'url'    => route('db.docs', ['schema' => (string) $key, 'table' => (string) $tkey], false),
                ];
            }
        }

        return $out;
    }
}
