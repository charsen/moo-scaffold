<div class="header">
    <div class="user" id="user">
        <a href="javascript:;" class="cover">
            <img src="/scaffold/images/cover.png">
        </a>
        <div class="drop-down">
            <a href="#">设置</a>
            <a href="#">退出</a>
        </div>
    </div>
    <a href="javascript:;" class="logo">
        <img src="/scaffold/images/logo.png">
    </a>
    <div class="collapse">
        <div class="menu">
            <a href="{{ route('table.list') }}" class="<?= route('table.list', [], false) == $uri ? 'active' : ''?>">数据库文档</a>
            <a href="{{ route('api.list') }}" class="<?= route('api.list', [], false) == $uri ? 'active' : ''?>">接口文档</a>
            <a href="{{ route('api.request') }}" class="<?= route('api.request', [], false) == $uri ? 'active' : ''?>">接口调试</a>
        </div>
        <i class="slider" style="left: 14px;"></i>
    </div>
</div>
