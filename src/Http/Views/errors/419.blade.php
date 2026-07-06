@include('scaffold::errors._layout', [
    'code'  => '419',
    'title' => '会话已过期',
    'desc'  => '你的登录态已经过期，请重新登录。',
    'hint'  => '为了安全，scaffold 后台超过配置 TTL 后会自动登出。',
])
