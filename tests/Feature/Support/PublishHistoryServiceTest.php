<?php declare(strict_types=1);

/**
 * 「接口发布历史」族（`ScaffoldController` 的 7 个方法）的**行为钉尸**测试。
 *
 * **为什么先有测试、后有外迁**：这 7 个方法 —— `getApiPublishHistory` / `resolvePublishHistoryAuthor` /
 * `summarizePublishOperations` / `groupApiPublishHistory` / `paginatePublishHistoryGroup` /
 * `loadPublishHistoryActions` / `buildApiDebugUrl` —— 此前只有 `ScaffoldDashboardTest` 从 `/scaffold`
 * 端到端 `assertSee` 覆盖，**没有一个方法的契约被直接钉住**。按 `TUNING-PLAN.md` 红线 9
 * 「测试钉现状先行」，本文件**先**钉住现状（外迁前即须全绿），**再**把这一族搬到
 * `Support\PublishHistoryService`（`final` + ctor 注入 `Filesystem` / `Utility`）。
 *
 * **本文件是「同一批断言跨两个宿主」的**：外迁前 `phSubject()` 返回 `ScaffoldController::class`
 * （7 个都是私有**实例**方法 ⇒ 反射），外迁后返回 `PublishHistoryService::class`。
 * 两边的宿主实例都用 `app(phSubject())` 拿 —— **刻意不写死构造参数**：外迁会在 `ScaffoldController`
 * 的 ctor 上多加一个参数，写死了这个文件在搬家前后就不再是「同一批断言」。
 * 跟着宿主变的只有 `phSubject()` 一行（外加顶部一条 `use`），**行为断言一个字都没动**。
 *
 * 与「参数形状归一」（第 4 项）／「字段形状」（第 6 项）那两族不同，**这一族不是零状态**：
 * 它要读文件系统、要走 `cache()`、要 `route()`。所以搬完拿不到字节级保真证明 ——
 * 本文件给的是**行为等价的机器证明**；「没多没少」那半边靠方法集合差集（见 NOTES 对应条目）。
 *
 * 文件分两段：**行为**段（§1~§7，跨宿主不变）+ **结构**段（§8，外迁后的形状契约，外迁前整体 skip）。
 * 「接线是否真的接上了」不在这里另写一遍 —— `ScaffoldDashboardTest` 从 `/scaffold` 端到端
 * `assertSee` 才是那条锁，它跨宿主天然成立。
 *
 * ⚠ 本文件只**钉现状**，一个 bug 都不修。
 */

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Mooeen\Scaffold\Http\Controllers\ScaffoldController;
use Mooeen\Scaffold\Support\PublishHistoryService;

/** 宿主解析点。**外迁时只改这一行**（连同顶部一条 `use`）—— 外迁前是 `ScaffoldController::class`。 */
function phSubject(): string
{
    return PublishHistoryService::class;
}

/**
 * 外迁后的目标宿主。
 *
 * 刻意写成**字符串字面量**：外迁前这个类还不存在，`class_exists()` 必须能安全求值为 false，
 * §8 才能整体 skip、保住「改前先绿」。它与 `phSubject()` 的返回值由 §8 末条绑死 —— 写不一致即红。
 */
function phNewHost(): string
{
    return 'Mooeen\\Scaffold\\Support\\PublishHistoryService';
}

/**
 * 调族内方法。
 *
 * 跨宿主的差别只在**可见性**（外迁前 7 个全 private，外迁后 3 个入口 public、4 个助手仍 private），
 * 两边都是**实例**方法 ⇒ 统一走反射 + `app(phSubject())` 的实例。
 */
function phCall(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(phSubject(), $method);
    $ref->setAccessible(true);

    return $ref->invoke(app(phSubject()), ...$args);
}

/** 落一个发布历史 yaml。 */
function phWriteHistory(string $dir, string $file, string $body): void
{
    file_put_contents($dir . '/' . $file, $body);
}

/** 一份「最小可用」的发布历史 yaml（meta 齐全 + 1 条带 debug 块的 action）。 */
function phHistoryYaml(string $publishedAt, string $actionName, string $operation = 'create', string $author = 'tester'): string
{
    return <<<YAML
meta:
  app: admin
  namespace: Light
  author: {$author}
  controller_count: 1
  action_count: 1
  published_at: '{$publishedAt}'
actions:
  -
    name: '{$actionName}'
    controller: MemoController
    action_key: index_get
    method: get
    uri: 'light/memos'
    operation: {$operation}
    debug:
      app: admin
      folder: Light
      controller: Memo
      action: index_get
YAML;
}

/** 建一个临时历史目录并返回它的**相对**路径（`Paths::api()` 只认相对值，见 `Paths` 类头注释）。 */
function phMakeHistoryDir(Filesystem $fs): string
{
    $rel = 'ph_hist_' . uniqid();
    $fs->ensureDirectoryExists(base_path($rel));
    config(['scaffold.api.history' => $rel]);

    return $rel;
}

beforeEach(function () {
    // `paginatePublishHistoryGroup` 与 `buildApiDebugUrl` 内部都走 `route()`，需要一个 request 上下文。
    $this->app->instance('request', Request::create('http://localhost/scaffold'));
});

it('宿主解析点有效：phSubject() 指向的类存在且持有 7 个族成员', function () {
    $rc = new ReflectionClass(phSubject());

    expect(class_exists(phSubject()))->toBeTrue();

    foreach (phMemberNames() as $name) {
        expect($rc->hasMethod($name))->toBeTrue();
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §1 summarizePublishOperations —— badge 汇总只认 4 个「真信号」operation
 * ═══════════════════════════════════════════════════════════════════════ */

it('§1 summarizePublishOperations：只统计 4 个已知 operation；未知值(overwrite)与缺省值各走一边', function () {
    $labels = ['create' => '新增', 'append' => '追加', 'delete' => '删除', 'deprecated' => '弃用'];

    // 空入参 ⇒ 空列表（`array_map` 两个空数组）
    expect(phCall('summarizePublishOperations', [], $labels))->toBe([]);

    $out = phCall('summarizePublishOperations', [
        ['operation' => 'create'],
        ['operation' => 'append'],
        ['operation' => 'create'],
        // 旧历史里残留的 'overwrite' —— generator 早已不再产出，只在这里被**过滤**
        ['operation' => 'overwrite'],
        ['operation' => 'deprecated'],
        // 缺 `operation` 键 ⇒ 当 append 算（不是跳过）
        ['name' => '没有 operation 键'],
    ], $labels);

    // 顺序 = 首次出现的顺序（`array_keys($counts)` 的插入序），不是 labels 的声明序
    expect($out)->toBe([
        ['key' => 'create', 'label' => '新增', 'count' => 2],
        ['key' => 'append', 'label' => '追加', 'count' => 2],
        ['key' => 'deprecated', 'label' => '弃用', 'count' => 1],
    ]);

    // 不在 labels 里的 operation 一律过滤（delete 没出现在 labels 时同样被丢掉）
    expect(phCall('summarizePublishOperations', [['operation' => 'delete']], ['create' => '新增']))->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §2 resolvePublishHistoryAuthor —— meta.author 优先，回落文件头的 `# @author`
 * ═══════════════════════════════════════════════════════════════════════ */

it('§2 resolvePublishHistoryAuthor：meta.author 优先(trim)；为空则读文件头；读不到一律空串', function () {
    $fs  = app(Filesystem::class);
    $dir = base_path('ph_author_' . uniqid());
    $fs->ensureDirectoryExists($dir);
    $file = $dir . '/a.yaml';

    try {
        $fs->put($file, "# @author   bob \nmeta:\n  app: admin\n");

        expect(phCall('resolvePublishHistoryAuthor', ['author' => '  alice  '], $file))->toBe('alice')
            // 空串 / 缺键 / 只有空白 ⇒ 都回落到文件头
            ->and(phCall('resolvePublishHistoryAuthor', ['author' => ''], $file))->toBe('bob')
            ->and(phCall('resolvePublishHistoryAuthor', ['author' => '   '], $file))->toBe('bob')
            ->and(phCall('resolvePublishHistoryAuthor', [], $file))->toBe('bob')
            // 文件读不到 ⇒ 空串（**不抛** —— `Filesystem::get()` 的 FileNotFoundException 被吞）
            ->and(phCall('resolvePublishHistoryAuthor', [], $dir . '/missing.yaml'))->toBe('')
            // 目录传进来也一样：`get()` 抛、被吞
            ->and(phCall('resolvePublishHistoryAuthor', [], $dir))->toBe('');

        // 文件里没有 `# @author` 头 ⇒ 空串
        $fs->put($file, "meta:\n  app: admin\n");
        expect(phCall('resolvePublishHistoryAuthor', [], $file))->toBe('');

        // `+` 贪婪：整行剩余部分都算作者名，最后 trim
        $fs->put($file, "# @author  carol  \nmeta:\n");
        expect(phCall('resolvePublishHistoryAuthor', [], $file))->toBe('carol');
    } finally {
        $fs->deleteDirectory($dir);
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §3 buildApiDebugUrl —— debug 块优先；四要素任一为空即 null
 * ═══════════════════════════════════════════════════════════════════════ */

it('§3 buildApiDebugUrl：debug 块优先、缺项回落 meta/action；四要素任一为空 ⇒ null', function () {
    $meta = ['app' => 'admin', 'namespace' => 'Light'];

    // 没有 debug 块 ⇒ 回落 meta.app / meta.namespace + action.controller / action.action_key
    $url = phCall('buildApiDebugUrl', $meta, [
        'controller' => 'MemoController',
        'action_key' => 'index_get',
    ]);
    expect($url)->toContain('api/request')
        ->and($url)->toContain('app=admin')
        ->and($url)->toContain('f=Light')
        ->and($url)->toContain('c=Memo')
        ->and($url)->toContain('a=index_get');

    // debug 块齐全 ⇒ 四个都听它的（哪怕与 meta / action 矛盾）
    $url2 = phCall('buildApiDebugUrl', $meta, [
        'controller' => 'MemoController',
        'action_key' => 'index_get',
        'debug'      => ['app' => 'web', 'folder' => 'Web', 'controller' => 'Foo', 'action' => 'bar_get'],
    ]);
    expect($url2)->toContain('app=web')
        ->and($url2)->toContain('f=Web')
        ->and($url2)->toContain('c=Foo')
        ->and($url2)->toContain('a=bar_get')
        // 与上一条互为对照：同一份 action，有没有 debug 块是两个世界
        ->and($url2)->not->toContain('c=Memo');

    // folder 的三级回落：debug.folder → meta.namespace → 'Index'
    expect(phCall('buildApiDebugUrl', ['app' => 'admin'], ['controller' => 'A', 'action_key' => 'b']))
        ->toContain('f=Index');

    // 四要素任一为空 ⇒ null（注意 folder 有 'Index' 兜底，要显式给空串才空）
    $blank = static fn (array $m, array $a): mixed => phCall('buildApiDebugUrl', $m, $a);

    expect($blank([], ['controller' => 'A', 'action_key' => 'b']))->toBeNull()                          // app 空
        ->and($blank(['app' => 'admin', 'namespace' => ''], ['controller' => 'A', 'action_key' => 'b']))->toBeNull()  // folder 空
        ->and($blank(['app' => 'admin'], ['action_key' => 'b']))->toBeNull()                            // controller 空
        ->and($blank(['app' => 'admin'], ['controller' => 'A']))->toBeNull()                            // action 空
        ->and($blank(['app' => 'admin'], ['controller' => 'A', 'action_key' => 'b']))->not->toBeNull();
});

/* ═══════════════════════════════════════════════════════════════════════
 * §4 groupApiPublishHistory —— 按 app 分组；空值落 unknown；pane_key 被净化
 * ═══════════════════════════════════════════════════════════════════════ */

it('§4 groupApiPublishHistory：按 app 分组保序、app 为空落 unknown、pane_key 净化', function () {
    expect(phCall('groupApiPublishHistory', []))->toBe([]);

    $groups = phCall('groupApiPublishHistory', [
        ['app' => 'admin', 'app_name' => '后台', 'n' => 1],
        ['app' => 'web', 'app_name' => 'Web 端接口', 'n' => 2],
        ['app' => 'admin', 'app_name' => '后台', 'n' => 3],
        // app 为空 ⇒ key 落 'unknown'
        ['app' => '', 'app_name' => '', 'n' => 4],
        // app 含非 [a-z0-9_-] 字符 ⇒ 只有 pane_key 被净化，key 保持原样
        ['app' => 'Weird App!', 'app_name' => '', 'n' => 5],
    ]);

    // 组顺序 = 首次出现顺序
    expect(array_column($groups, 'key'))->toBe(['admin', 'web', 'unknown', 'Weird App!'])
        ->and(array_column($groups, 'count'))->toBe([2, 1, 1, 1]);

    $byKey = array_column($groups, null, 'key');

    expect($byKey['admin']['pane_key'])->toBe('admin')
        ->and($byKey['admin']['name'])->toBe('后台')
        ->and($byKey['admin']['items'])->toHaveCount(2)
        // 组内条目保持输入顺序
        ->and(array_column($byKey['admin']['items'], 'n'))->toBe([1, 3])
        // app 为空：key / pane_key 用 'unknown'，name 也回落 'unknown'（app_name 为空）
        ->and($byKey['unknown']['name'])->toBe('unknown')
        ->and($byKey['unknown']['pane_key'])->toBe('unknown')
        // app 非空但 app_name 为空 ⇒ name 回落 appKey 本身；pane_key 才是净化后的
        ->and($byKey['Weird App!']['name'])->toBe('Weird App!')
        ->and($byKey['Weird App!']['pane_key'])->toBe('Weird-App-');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §5 getApiPublishHistory —— 目录扫描 + 排序 + 键集 + limit + 签名缓存
 * ═══════════════════════════════════════════════════════════════════════ */

it('§5 getApiPublishHistory：目录不存在 ⇒ []；产出按发布时间倒序，键集固定，limit 截断，新发布立即生效', function () {
    $fs = app(Filesystem::class);

    // 历史目录不存在（默认配置指向的目录在 testbench 下就没有）⇒ 空列表，不抛
    config(['scaffold.api.history' => 'ph_missing_' . uniqid()]);
    expect(phCall('getApiPublishHistory', []))->toBe([]);

    $rel = phMakeHistoryDir($fs);
    $dir = base_path($rel);

    try {
        phWriteHistory($dir, 'publish_old.yaml', phHistoryYaml('2026-06-10 10:00:00', '旧列表'));
        phWriteHistory($dir, 'publish_new.yaml', phHistoryYaml('2026-06-10 12:00:00', '新列表'));
        // 非 .yaml 的文件不进列表
        $fs->put($dir . '/README.md', 'not a history file');

        $list = phCall('getApiPublishHistory', ['admin' => '后台']);

        expect($list)->toHaveCount(2)
            // 按 meta.published_at 倒序（不是文件名序）
            ->and(array_column($list, 'file'))->toBe(['publish_new.yaml', 'publish_old.yaml'])
            // 键集：`_sort_time` 是中间量，产出前已被 unset —— 顺序也钉住（视图侧直接取用）
            ->and(array_keys($list[0]))->toBe([
                'file', 'published_at', 'app', 'app_name', 'namespace',
                'author', 'controller_count', 'action_count', 'operations', 'relative_path',
            ])
            ->and($list[0]['app'])->toBe('admin')
            ->and($list[0]['app_name'])->toBe('后台')
            ->and($list[0]['namespace'])->toBe('Light')
            ->and($list[0]['author'])->toBe('tester')
            ->and($list[0]['controller_count'])->toBe(1)
            ->and($list[0]['action_count'])->toBe(1)
            ->and($list[0]['published_at'])->toBe('2026-06-10 12:00:00')
            ->and($list[0]['operations'])->toBe([['key' => 'create', 'label' => '新增', 'count' => 1]])
            // relative_path 是「相对 base_path」的形态（`loadPublishHistoryActions` 靠它回头再读）
            ->and($list[0]['relative_path'])->toBe($rel . '/publish_new.yaml');

        // limit：截前 N 条
        expect(array_column(phCall('getApiPublishHistory', ['admin' => '后台'], 1), 'file'))->toBe(['publish_new.yaml'])
            // limit = 0 / null ⇒ 不截断（`$limit > 0` 才截）
            ->and(phCall('getApiPublishHistory', ['admin' => '后台'], 0))->toHaveCount(2)
            ->and(phCall('getApiPublishHistory', ['admin' => '后台']))->toHaveCount(2);

        // 新发布 ⇒ 文件集合变化 ⇒ 签名立即失效，无 TTL 等待
        phWriteHistory($dir, 'publish_newest.yaml', phHistoryYaml('2026-06-10 13:00:00', '最新列表'));
        expect(array_column(phCall('getApiPublishHistory', ['admin' => '后台']), 'file')[0])->toBe('publish_newest.yaml');
    } finally {
        $fs->deleteDirectory($dir);
    }
});

it('§5 getApiPublishHistory：meta 缺失时 app / app_name / namespace / author / published_at 各自回落', function () {
    $fs  = app(Filesystem::class);
    $rel = phMakeHistoryDir($fs);
    $dir = base_path($rel);

    try {
        // 只有 actions、没有 meta；文件里也没有 `# @author` 头
        phWriteHistory($dir, 'bare.yaml', "actions:\n  -\n    name: '无 meta'\n    controller: MemoController\n"
            . "    action_key: index_get\n    method: get\n    uri: 'light/memos'\n    operation: append\n");
        // meta / actions 都空 ⇒ 整条不进列表（`continue`）
        phWriteHistory($dir, 'empty.yaml', "meta: {}\nactions: []\n");

        $list = phCall('getApiPublishHistory', []);

        expect($list)->toHaveCount(1)
            ->and($list[0]['file'])->toBe('bare.yaml')
            ->and($list[0]['app'])->toBe('')
            // app 为空且 apps 映射里没有 '' 这一项 ⇒ 落 '-'
            ->and($list[0]['app_name'])->toBe('-')
            ->and($list[0]['namespace'])->toBe('Index')
            ->and($list[0]['author'])->toBe('')
            // action_count 缺省 ⇒ 数 actions；controller_count 缺省 ⇒ 数去重后的 controller
            ->and($list[0]['action_count'])->toBe(1)
            ->and($list[0]['controller_count'])->toBe(1)
            ->and($list[0]['operations'])->toBe([['key' => 'append', 'label' => '追加', 'count' => 1]])
            // published_at 缺省 ⇒ 文件 mtime 格式化
            ->and($list[0]['published_at'])->toBe(date('Y-m-d H:i:s', filemtime($dir . '/bare.yaml')));
    } finally {
        $fs->deleteDirectory($dir);
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §6 loadPublishHistoryActions —— 懒加载：只为当前页的条目重读文件、补 debug_url
 * ═══════════════════════════════════════════════════════════════════════ */

it('§6 loadPublishHistoryActions：relative_path 为空 ⇒ []；否则重读文件并给每条 action 补 debug_url', function () {
    $fs = app(Filesystem::class);

    expect(phCall('loadPublishHistoryActions', []))->toBe([])
        ->and(phCall('loadPublishHistoryActions', ['relative_path' => '']))->toBe([]);

    $rel = phMakeHistoryDir($fs);
    $dir = base_path($rel);

    try {
        phWriteHistory($dir, 'a.yaml', phHistoryYaml('2026-06-10 10:00:00', '列表A'));

        $actions = phCall('loadPublishHistoryActions', ['relative_path' => $rel . '/a.yaml']);

        expect($actions)->toHaveCount(1)
            ->and($actions[0]['name'])->toBe('列表A')
            ->and($actions[0]['operation'])->toBe('create')
            ->and($actions[0]['debug_url'])->toContain('api/request')
            ->and($actions[0]['debug_url'])->toContain('app=admin')
            ->and($actions[0]['debug_url'])->toContain('a=index_get')
            // 原 action 的键都还在（`...$item` 展开，只多一个 debug_url）
            // ⚠ `array_diff` **保键**，必须 `array_values` 归一，否则断言拿到的是 `[7 => 'debug_url']`
            ->and(array_values(array_diff(array_keys($actions[0]), array_keys(['name' => 1, 'controller' => 1, 'action_key' => 1, 'method' => 1, 'uri' => 1, 'operation' => 1, 'debug' => 1]))))
            ->toBe(['debug_url']);

        // 文件已不在 ⇒ `parseYamlFile` 返回 [] ⇒ 空明细（不抛）
        expect(phCall('loadPublishHistoryActions', ['relative_path' => $rel . '/gone.yaml']))->toBe([]);
    } finally {
        $fs->deleteDirectory($dir);
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §7 paginatePublishHistoryGroup —— 选 tab + 切页 + 夹紧页号 + 懒加载明细
 * ═══════════════════════════════════════════════════════════════════════ */

/** 造 admin 12 条 + web 3 条的分组（`relative_path` 为空 ⇒ 明细恒为 []，不碰文件系统）。 */
function phGroups(int $admin = 12, int $web = 3): array
{
    $items = [];
    for ($i = 0; $i < $admin; $i++) {
        $items[] = ['app' => 'admin', 'app_name' => '后台', 'relative_path' => '', 'n' => $i];
    }
    for ($i = 0; $i < $web; $i++) {
        $items[] = ['app' => 'web', 'app_name' => 'Web 端接口', 'relative_path' => '', 'n' => 100 + $i];
    }

    return phCall('groupApiPublishHistory', $items);
}

it('§7 paginatePublishHistoryGroup：空分组返回 null 对；选 tab / 切页 / 夹紧页号 / 懒加载明细', function () {
    expect(phCall('paginatePublishHistoryGroup', [], []))->toBe(['group' => null, 'paginator' => null]);

    $groups = phGroups();

    $r = phCall('paginatePublishHistoryGroup', [], $groups);

    expect($r['group']['key'])->toBe('admin')
        ->and($r['paginator']->total())->toBe(12)
        ->and($r['paginator']->perPage())->toBe(10)
        ->and($r['paginator']->lastPage())->toBe(2)
        ->and($r['paginator']->currentPage())->toBe(1)
        ->and($r['paginator']->items())->toHaveCount(10)
        // 当前页的每条都被换成「原条目 + items 明细」（懒加载；relative_path 为空 ⇒ 明细空数组）
        ->and($r['group']['items'])->toHaveCount(10)
        ->and($r['group']['items'][0]['n'])->toBe(0)
        ->and($r['group']['items'][0]['items'])->toBe([])
        // count 仍是这一组的全量条数，不是当前页条数
        ->and($r['group']['count'])->toBe(12);

    // 第二页只剩 2 条
    $r2 = phCall('paginatePublishHistoryGroup', ['history_page' => 2], $groups);

    expect($r2['paginator']->currentPage())->toBe(2)
        ->and(array_column($r2['paginator']->items(), 'n'))->toBe([10, 11]);

    // 页号越界 ⇒ 夹到 lastPage；非法页（数组 / 0 / 负数 / 非数字）⇒ 回落第 1 页
    expect(phCall('paginatePublishHistoryGroup', ['history_page' => 99], $groups)['paginator']->currentPage())->toBe(2)
        ->and(phCall('paginatePublishHistoryGroup', ['history_page' => ['x']], $groups)['paginator']->currentPage())->toBe(1)
        ->and(phCall('paginatePublishHistoryGroup', ['history_page' => 0], $groups)['paginator']->currentPage())->toBe(1)
        ->and(phCall('paginatePublishHistoryGroup', ['history_page' => -3], $groups)['paginator']->currentPage())->toBe(1)
        ->and(phCall('paginatePublishHistoryGroup', ['history_page' => 'abc'], $groups)['paginator']->currentPage())->toBe(1);

    // history_app：能选中指定 tab；非字符串 / 不存在的值一律回落默认（第一个）tab
    expect(phCall('paginatePublishHistoryGroup', ['history_app' => 'web'], $groups)['group']['key'])->toBe('web')
        ->and(phCall('paginatePublishHistoryGroup', ['history_app' => ['web']], $groups)['group']['key'])->toBe('admin')
        ->and(phCall('paginatePublishHistoryGroup', ['history_app' => 5], $groups)['group']['key'])->toBe('admin')
        ->and(phCall('paginatePublishHistoryGroup', ['history_app' => 'nope'], $groups)['group']['key'])->toBe('admin');

    // 翻页链接把当前 tab 带上（切页不丢 tab）
    $web = phCall('paginatePublishHistoryGroup', ['history_app' => 'web'], $groups);

    expect($web['group']['key'])->toBe('web')
        ->and($web['paginator']->url(1))->toContain('history_app=web')
        ->and($web['paginator']->url(1))->toContain('history_page=1');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §8 结构锚点（外迁后的形状契约，只针对新宿主）
 *
 * ⚠ 外迁前整体 skip —— 这就是「红线 9：先补测试、跑绿、再动手改」的落地方式：
 *   本文件在外迁**前**必须全绿（§8 全部 skipped 不算红），外迁**后** §8 必须全转绿。
 *   若外迁只做了一半（类建了但没接上），§8 会红而不是被跳过 —— skip 的条件是
 *   「新宿主这个类存不存在」，不是「我改完了没有」。
 * ═══════════════════════════════════════════════════════════════════════ */

/** 新宿主源码路径。 */
function phSourcePath(): string
{
    return dirname(__DIR__, 3) . '/src/Support/PublishHistoryService.php';
}

/** 去掉注释（可选再去掉字符串字面量）后的源码 —— 结构断言必须先剥注释。 */
function phCodeOnly(string $php, bool $keepStrings = true): string
{
    $out = '';

    foreach (token_get_all($php) as $t) {
        if (! is_array($t)) {
            $out .= $t;

            continue;
        }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            continue;
        }
        if (! $keepStrings && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $out .= $t[1];
    }

    return $out;
}

/** 迁走的 7 个方法名（唯一真源，§8 各条共用）。 */
function phMemberNames(): array
{
    return [
        'buildApiDebugUrl',
        'getApiPublishHistory',
        'groupApiPublishHistory',
        'loadPublishHistoryActions',
        'paginatePublishHistoryGroup',
        'resolvePublishHistoryAuthor',
        'summarizePublishOperations',
    ];
}

/** 3 个入口（族外只经它们进来）—— 搬到新家后只有这 3 个是 public。 */
function phEntryPoints(): array
{
    return ['getApiPublishHistory', 'groupApiPublishHistory', 'paginatePublishHistoryGroup'];
}

/**
 * 某类**自己声明**的普通公开方法名（升序）。
 *
 * ⚠ 跳过 `__*` 魔术方法 —— `__construct` 是**依赖入口**不是家族成员，混进来会让
 * 「公开面恰好 = 3 个入口」这条断言变成「3 个入口 + 构造器」，指代不清。
 */
function phOwnMethodNames(string $class): array
{
    $rc    = new ReflectionClass($class);
    $names = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class
                && $m->isPublic()
                && ! str_starts_with($m->getName(), '__'),
        ),
    );
    sort($names);

    return $names;
}

it('§8 宿主形状：final + 不继承 UI 基类 + ctor 恰好注入 Filesystem/Utility + 方法集合恰 7 个 + 公开面恰 3 个', function () {
    $rc = new ReflectionClass(phNewHost());

    expect($rc->isFinal())->toBeTrue()
        // 不继承 `Http\Controllers\Controller`（那是个 UI 控制器基类，本类不是控制器）
        ->and($rc->getParentClass())->toBeFalse()
        // 普通公开方法**恰好**等于 3 个入口 —— 多一个（顺手扩大可见面）少一个都红
        // （4 个助手必须仍是 private；`__construct` 由 `phOwnMethodNames` 跳过）
        ->and(phOwnMethodNames(phNewHost()))->toBe(phEntryPoints());

    // 全部 7 个都在，且都是**实例**方法（不是全静态那一型：本族有 IO 依赖）
    foreach (phMemberNames() as $name) {
        $m = $rc->getMethod($name);
        expect($m->isStatic())->toBeFalse()
            ->and($m->getDeclaringClass()->getName())->toBe(phNewHost());
    }

    // ctor：恰好 2 个参数，类型恰为 Filesystem + Utility（顺序不钉 —— 注入顺序是可选实现细节）
    $ctor  = $rc->getConstructor();
    $types = array_map(
        static fn (ReflectionParameter $p): string => (string) $p->getType(),
        $ctor?->getParameters() ?? [],
    );
    sort($types);

    expect($types)->toBe(['Illuminate\Filesystem\Filesystem', 'Mooeen\Scaffold\Utility']);
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');

it('§8 每页条数常量随族一起搬：新宿主持有 PUBLISH_HISTORY_PER_PAGE = 10', function () {
    expect((new ReflectionClassConstant(phNewHost(), 'PUBLISH_HISTORY_PER_PAGE'))->getValue())->toBe(10);
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');

it('§8 依赖面锚点：`$this->` 接收者恰为 6 个（filesystem / utility / 4 个助手），不碰容器与其它服务', function () {
    $code = phCodeOnly((string) file_get_contents(phSourcePath()), keepStrings: false);

    // 接收者集合必须**恰好**是这几个 —— 多出一个就是新引入的耦合
    preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)/', $code, $m);
    $receivers = array_values(array_unique($m[1]));
    sort($receivers);

    expect($receivers)->toBe([
        'buildApiDebugUrl', 'filesystem', 'loadPublishHistoryActions',
        'resolvePublishHistoryAuthor', 'summarizePublishOperations', 'utility',
    ]);

    // 容器 / 其它服务一律不许出现
    foreach (['app(', 'config(', 'StorageRegistry', 'SchemaLoader', 'ApiSchemaService', 'CloudClient'] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // `use` 清单恰好 3 条（`Paths` 是同命名空间引用，**不该**有 import —— 多一条即红）
    $head = substr($code, 0, (int) strpos($code, 'final class'));
    preg_match_all('/^use\s+([^\s;]+);/m', $head, $u);

    expect($u[1])->toBe([
        'Illuminate\Filesystem\Filesystem',
        'Illuminate\Pagination\LengthAwarePaginator',
        'Mooeen\Scaffold\Utility',
    ]);
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');

it('§8 「已不存在」锚点：这 7 个方法不得再出现在 ScaffoldController 上', function () {
    $rc = new ReflectionClass(ScaffoldController::class);

    $stillThere = array_values(array_filter(
        phMemberNames(),
        static fn (string $name): bool => $rc->hasMethod($name),
    ));

    expect($stillThere)->toBe([]);
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');

it('§8 接线锚点：ScaffoldController 的 3 个入口调用点改指新宿主，且不留本地转发', function () {
    $code = phCodeOnly((string) file_get_contents(dirname(__DIR__, 3) . '/src/Http/Controllers/ScaffoldController.php'));

    // ctor 多注入一个服务
    expect($code)->toContain('PublishHistoryService $publishHistoryService')
        // 3 个调用点全部改指新宿主，且不再有 `$this-><族方法>(` 的残留
        ->and($code)->toContain('$this->publishHistoryService->getApiPublishHistory(')
        ->and($code)->toContain('$this->publishHistoryService->groupApiPublishHistory(')
        ->and($code)->toContain('$this->publishHistoryService->paginatePublishHistoryGroup(');

    foreach (phMemberNames() as $name) {
        expect($code)->not->toContain('$this->' . $name . '(');
    }

    // 常量也搬走了（留下的那份是死代码，等价于「没删干净」）
    expect((new ReflectionClass(ScaffoldController::class))->getConstants())->not->toHaveKey('PUBLISH_HISTORY_PER_PAGE');
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');

it('§8 phSubject() 已翻到新宿主 ⇒ §1~§7 的断言现在跑在新类上（断言一字未动）', function () {
    expect(phSubject())->toBe(phNewHost());
})->skip(! class_exists(phNewHost()), '外迁前新宿主尚不存在');
