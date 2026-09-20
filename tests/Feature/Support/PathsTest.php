<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\Paths;

/**
 * 路径归一的唯一口径（`Support\Paths`）。
 *
 * 为什么钉这个：收口前「相对 → 绝对」有两类需求、共 10 份手写实现，且两侧的**绝对性判定并不一致**
 * —— 命令里的 `absolutePath()` 认 Windows 盘符，配置项那 6 份只认 `/` 开头。后果是 Windows 上把
 * `C:\...` 写进配置项会被当成相对路径挂到 `base_path()` 下，静默落到错地方（不报错、只是找不着）。
 * 所以除了行为用例外，还要一个「这两类字面写法只允许出现在 Paths 里」的锚点。
 *
 * 注释里提这些字面属于说明，不算违规 —— 锚点用 token_get_all 剥注释后再扫，避免把文档字符串当违规。
 *
 * **2026-09-19（第 3 项 · 阶段 3b-2）补的第二段**：`Utility` 的 11 个路径方法（`getModelPath` /
 * `getStoragePath` / `formatNameSpace` …）迁入本类。迁入时方法体**逐字未动**，但当时只在
 * `UtilitySurfaceTest` 钉了「旧名不再存在」的结构面 —— 语义面是靠 `TargetContextTest`
 * **间接对照**的，而那是拿 `Paths` 当**基准**去比 `targetContext`；`Paths` 自身改坏它照样绿
 * （基准跟着一起动）。偏偏 `Paths.php` 的类注释写着三处不对称「都有用例钉着」，
 * 在补齐本节之前那句话是**假承诺**。这节把它兑现：三处不对称各有一条**带对照的**用例，
 * 而不是只把当前值抄一遍（抄一遍的用例，改坏实现时它跟实现一起变，等于没钉）。
 */

/** 剥掉注释后的源码（与 ReadonlyModeTest 同法：正则剥注释会被路由串 `/*` 里的内容吞掉真实代码）。 */
function paths_code_without_comments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= $token[1];
            }

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * 第二段有几条用例要临时改 `scaffold.*` 配置（钉「认 / 不认绝对路径」「acl 不读配置」这些差异）。
 * 整棵 `scaffold` 子树快照 + 还原：这样连**新增的**键（如用例里补的 `scaffold.acl.path`）也会被一并
 * 清掉 —— 只逐键还原的话，补出来的键会留在同进程后续用例里。
 */
beforeEach(function () {
    $this->origScaffold = config('scaffold');
});

afterEach(function () {
    config(['scaffold' => $this->origScaffold]);
});

it('isAbsolute()：`/` 开头 或 Windows 盘符 才算绝对', function () {
    expect(Paths::isAbsolute('/a/b'))->toBeTrue()
        ->and(Paths::isAbsolute('C:\\a\\b'))->toBeTrue()
        ->and(Paths::isAbsolute('C:/a/b'))->toBeTrue()
        ->and(Paths::isAbsolute('c:/a'))->toBeTrue()
        // 下列都不是绝对路径 —— 尤其 `C:` 后面没有分隔符的那种（相对当前盘目录）
        ->and(Paths::isAbsolute(''))->toBeFalse()
        ->and(Paths::isAbsolute('a/b'))->toBeFalse()
        ->and(Paths::isAbsolute('./a'))->toBeFalse()
        ->and(Paths::isAbsolute('../a'))->toBeFalse()
        ->and(Paths::isAbsolute('C:foo'))->toBeFalse()
        ->and(Paths::isAbsolute('~'))->toBeFalse();
});

it('join()：两侧斜杠去重，不做绝对性判断（base 尾斜杠结果仍带一个）', function () {
    expect(Paths::join('/base', 'sub'))->toBe('/base/sub')
        ->and(Paths::join('/base/', 'sub'))->toBe('/base/sub')
        ->and(Paths::join('/base', '/sub'))->toBe('/base/sub')
        ->and(Paths::join('/base/', '/sub/'))->toBe('/base/sub/')
        // 空 path 原实现就是「base 去尾斜杠 + /」，保持逐字节一致（调用方自己判空）
        ->and(Paths::join('/base/', ''))->toBe('/base/')
        ->and(Paths::join('/', 'a'))->toBe('/a');
});

it('absolute()：绝对原样，相对拼 base', function () {
    expect(Paths::absolute('/abs/x', '/base'))->toBe('/abs/x')
        ->and(Paths::absolute('C:\\abs', '/base'))->toBe('C:\\abs')
        ->and(Paths::absolute('rel/x', '/base/'))->toBe('/base/rel/x')
        ->and(Paths::absolute('/rel/x', '/base'))->toBe('/rel/x');
});

it('fromBasePath()：绝对原样，相对走 base_path()', function () {
    $abs = base_path('x');

    expect(Paths::fromBasePath($abs))->toBe($abs)
        ->and(Paths::fromBasePath('scaffold/schema'))->toBe(base_path('scaffold/schema'));
});

it('fromBasePath() 认盘符：Windows 绝对路径不再被挂到 base_path() 下（收口前的 bug）', function () {
    // 收口前这 6 处只判 `str_starts_with($x, '/')` → `C:\...` 会被当相对路径，
    // 静默变成 base_path('C:\...')。这是本类统一判定时**唯一有意改变的运行时行为**。
    expect(Paths::fromBasePath('C:\\scaffold\\schema'))->toBe('C:\\scaffold\\schema')
        ->and(Paths::fromBasePath('D:/work/schema'))->toBe('D:/work/schema');
});

it('路径口径锚点：绝对/相对判定与 base 拼接只在 Paths 里实现', function () {
    $root      = dirname(__DIR__, 3);
    $anchor    = 'src/Support/Paths.php';
    $offenders = [];

    // ① 「绝对 ? 原样 : base_path(x)」—— 应改用 fromBasePath()
    $ternary = '/\?\s*\$[\w>\[\]\'"]+\s*:\s*base_path\(/';
    // ② 「rtrim($base,'/') . '/' . ltrim($path,'/')」—— 应改用 join()
    $join = '/rtrim\([^();]*,\s*\'\/\'\)\s*\.\s*\'\/\'\s*\.\s*ltrim\(/';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $rel = str_replace('\\', '/', str_replace($root . '/', '', $file->getPathname()));
        if ($rel === $anchor) {
            continue;     // 口径本体
        }

        $code = paths_code_without_comments($file->getPathname());

        if (preg_match($ternary, $code) === 1) {
            $offenders[] = "{$rel} → `? ... : base_path(...)`（应改用 Paths::fromBasePath()）";
        }
        if (preg_match($join, $code) === 1) {
            $offenders[] = "{$rel} → `rtrim(...,'/') . '/' . ltrim(...)`（应改用 Paths::join()）";
        }
    }

    expect($offenders)->toBe([], "以下文件自己实现了路径归一（应改用 Support\\Paths）：\n  " . implode("\n  ", $offenders));
});

/* ---------------------------------------------------------------------------------------------------
 * 第二段（2026-09-19 · 阶段 3b-2）：11 个自 `Utility` 迁入的路径方法。
 * 三条 `— 不对称` 用例对应 Paths.php 类注释里点名的三处「有意保留的不对称」。别把它们「顺手统一」。
 * --------------------------------------------------------------------------------------------------- */

it('model/resource/controller/api：相对配置挂 base_path()；$relative 去掉的是 base_path() 前缀', function () {
    expect(Paths::model())->toBe(base_path('app/Models/'))
        ->and(Paths::model(true))->toBe('./app/Models/')
        ->and(Paths::resource())->toBe(base_path('app/Http/Resources/'))
        ->and(Paths::resource(true))->toBe('./app/Http/Resources/')
        ->and(Paths::controller())->toBe(base_path('app/Admin/Controllers/'))
        // ⚠ controller() 的**第一个**参数是配置键名（默认 'controller.admin.path'），不是 relative 开关。
        // 收尾收紧签名之前，写成 `Paths::controller(true)` 不报错：`$key=true` → `config('scaffold.1')`
        // 取到 null → `base_path(null)` **静默返回 base_path() 本身**。现在第一参是 `string` ⇒ 那种误用
        // 直接 TypeError（下面「参数形状」那条正面钉了这一点）。
        ->and(Paths::controller('controller.admin.path', true))->toBe('./app/Admin/Controllers/')
        ->and(Paths::api())->toBe(base_path('scaffold/api/'))
        // api() 同理：第一个参数是「配置里的子键名」，默认 'schema'。
        // 这一组 10 个方法里 5 个是 ($relative)、5 个是 (键名, $relative) —— 形状不统一是既有形态，
        // 记住「$relative 永远在末位」比记住每个方法第一参叫什么更稳（下面有专门的形状锚点）。
        ->and(Paths::api('schema', true))->toBe('./scaffold/api/')
        ->and(Paths::api('history'))->toBe(base_path('scaffold/api/history/'))
        ->and(Paths::api('history', true))->toBe('./scaffold/api/history/');
});

it('参数形状：$relative 恒为末参且为 bool；键名参数恒为 string —— 误用一律 TypeError，不静默', function () {
    $params       = static fn (string $method): array => (new ReflectionMethod(Paths::class, $method))->getParameters();
    $onlyRelative = ['model', 'resource', 'migration', 'storage', 'acl'];
    $withKey      = ['controller', 'api', 'database', 'schema', 'appResource'];

    foreach ($onlyRelative as $method) {
        expect($params($method))->toHaveCount(1, "Paths::{$method}() 只该有 \$relative 一个参数");
    }

    foreach ($withKey as $method) {
        expect($params($method))->toHaveCount(2, "Paths::{$method}() 该是 (键名, \$relative) 两个参数");
    }

    // 不变量 ①：末参恒为 `bool $relative` —— 调换顺序、或把类型去掉，这里就红。
    foreach (array_merge($onlyRelative, $withKey) as $method) {
        $list = $params($method);
        $last = $list[count($list) - 1];

        expect($last->getName())->toBe('relative', "Paths::{$method}() 的末参必须是 \$relative")
            ->and((string) $last->getType())->toBe('bool', "Paths::{$method}() 的 \$relative 必须是 bool");
    }

    // 不变量 ②：键名参数恒为 string（`schema()` 的 `$file_name` 是 `?string`，`null` 表示「只要目录」）。
    // 为什么这条值钱：收尾收紧**之前**，controller() / database() / schema() 的第一参无类型 ⇒
    //   `controller(true)` 静默返回 base_path() 本身、`database(true)` 静默返回 base_path()、
    //   `schema(true)` 静默返回 ".../scaffold/database/1"（true 被拼成 "1"）—— 路径**错但像对的**。
    // 现在第一参有类型 ⇒ 同一个写法的失败形态从「静默错值」变成「TypeError」。见下面那条断言。
    $expectedFirstType = [
        'controller'  => 'string',
        'api'         => 'string',
        'database'    => 'string',
        'schema'      => '?string',
        'appResource' => 'string',
    ];

    foreach ($expectedFirstType as $method => $type) {
        expect((string) $params($method)[0]->getType())->toBe($type, "Paths::{$method}() 的第一参必须是 {$type}");
    }

    // 反向断言：把 $relative 传到**键名位**这条误用，现在必须是响亮的 TypeError（不是静默错路径）。
    // 只对 `$withKey` 那 5 个成立 —— `model(true)` / `storage(true)` 本来就是合法调用（$relative 是首参），
    // 所以「传错位」这件事只存在于「有键名位」的方法上。
    // 前提是调用方在 `declare(strict_types=1)` 下 —— 本仓 202 个非 blade src 文件 + 136 个 tests 文件
    // 全都声明了，Blade 视图零处调 Paths ⇒ 全仓每个调用点都满足（核查记录见 NOTES 3b-2 条）。
    foreach ($withKey as $method) {
        // 第二参是**异常消息的子串**（不是自定义说明）：TypeError 的消息形如
        // `Mooeen\Scaffold\Support\Paths::controller(): Argument #1 ($key) must be of type string, true given`，
        // 顺手把「是哪个方法」也断言进去。
        expect(fn () => Paths::{$method}(true))->toThrow(TypeError::class, "Paths::{$method}(");
    }
});

it('database() 认绝对路径，而 model()/api()/acl() 不认（不对称之二）', function () {
    config(['scaffold.database.schema' => '/abs/schema']);
    config(['scaffold.model.path' => '/abs/models']);

    // database() 走 Paths::fromBasePath()：绝对则原样。
    expect(Paths::database('schema'))->toBe('/abs/schema');

    // 其余走裸 base_path($config)：配置写成绝对路径会被**拼到 base_path() 下面**。
    // 这是外迁前的既有形态（不是本次引入的 bug），要统一得单独立项并核下游配置 —— 所以钉住它，
    // 让它只能被**有意识**地改：这里两条断言必须真的不同，否则本用例就失去鉴别力。
    expect(Paths::model())->toBe(base_path('/abs/models'))
        ->and(Paths::model())->not->toBe('/abs/models')
        ->and(Paths::api())->toBe(base_path('scaffold/api/'));
});

it('storage() 的 $relative 去掉的是 storage_path()；migration() 去掉的仍是 base_path()（不对称之一）', function () {
    expect(Paths::storage())->toBe(storage_path('scaffold/'))
        ->and(Paths::storage(true))->toBe('./scaffold/');

    // 反向对照：若哪天「顺手统一」成 base_path 基线，产物会变成 ./storage/scaffold/ —— 两者必须不同。
    expect(Paths::storage(true))->not->toBe(str_replace(base_path(), '.', storage_path('scaffold/')));

    // migration() 取的是 database_path()，但 $relative 去掉的**仍是 base_path()** —— 与 storage() 相反。
    // 这两条合起来才说明「基线不统一」这件事是被知道的，而不是被漏掉的。
    expect(Paths::migration())->toBe(database_path('migrations/'))
        ->and(Paths::migration(true))->toBe(str_replace(base_path(), '.', database_path('migrations/')))
        ->and(Paths::migration(true))->not->toBe(str_replace(database_path(), '.', database_path('migrations/')));
});

it('acl() 的 scaffold/acl/ 是硬编码，不读配置（不对称之三）', function () {
    // 本仓 config/config.php 里**没有** scaffold.acl.* 键。这里故意补一个同名候选键：
    // 将来若有人把 acl() 改成读配置，本用例会红 —— 那正是要「有意识地去改」的时刻。
    config(['scaffold.acl.path' => 'somewhere/else/']);

    expect(Paths::acl())->toBe(base_path('scaffold/acl/'))
        ->and(Paths::acl(true))->toBe('./scaffold/acl/')
        ->and(Paths::acl())->not->toBe(base_path('somewhere/else/'));
});

it('schema() = database(schema) 加文件名；$relative 透传', function () {
    expect(Paths::schema())->toBe(Paths::database('schema'))
        ->and(Paths::schema('users.yaml'))->toBe(Paths::database('schema') . 'users.yaml')
        // ⚠ schema() 的第一个参数是 $file_name：收紧签名之前 `schema(true)` 不报错，而是把 true 拼成
        // 字符串 "1" ⇒ `.../scaffold/database/1`。现在第一个参是 `?string` ⇒ 那种误用直接 TypeError。
        // 真实调用点（CreateSchemaGenerator）写的是 ("x.yaml", true)，形态正确。
        ->and(Paths::schema(null, true))->toBe('./scaffold/database/')
        ->and(Paths::schema('users.yaml', true))->toBe('./scaffold/database/users.yaml');
});

it('appResource():读 controller.{app}.resource_path;端未注册、或该端没写 resource_path 都抛', function () {
    expect(Paths::appResource('admin'))->toBe(base_path('app/Admin/Resources/'))
        ->and(Paths::appResource('admin', true))->toBe('./app/Admin/Resources/');

    // 端根本没注册 → 由 AppTargetRegistry::get() 抛（带「当前可用：…」提示）
    expect(fn () => Paths::appResource('ghost'))->toThrow(InvalidArgumentException::class);

    // 端注册了但没写 resource_path → 本方法自己抛，而不是静默回落 base_path() 根目录
    config(['scaffold.controller.admin.resource_path' => '']);
    expect(fn () => Paths::appResource('admin'))->toThrow(InvalidArgumentException::class);
});

it('namespaceOf():先去 `./` 再换 `\\`，所以首段不会留空;末段首字母大写', function () {
    // 典型输入就是 model(true) 的产物（`./app/Models/`）
    expect(Paths::namespaceOf('./app/Models/'))->toBe('App\\Models\\')
        ->and(Paths::namespaceOf('./app/Models'))->toBe('App\\Models')
        // 没有 `./` 前缀时行为一致（`./` 只是先被整体删掉，不影响其它段）
        ->and(Paths::namespaceOf('app/Models'))->toBe('App\\Models')
        ->and(Paths::namespaceOf('App/Models'))->toBe('App\\Models')
        ->and(Paths::namespaceOf(''))->toBe('');
});
