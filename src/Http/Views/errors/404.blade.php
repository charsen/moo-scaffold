@include('scaffold::errors._layout', [
    'code'  => '404',
    'title' => '页面未找到',
    'desc'  => '看起来路径写错了，或者这页已被移除。',
    'hint'  => '可以从 Dashboard 选个入口，或者用顶栏菜单回到主功能区。',
])
