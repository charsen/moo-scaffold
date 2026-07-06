@include('scaffold::errors._layout', [
    'code'  => '403',
    'title' => '没有权限',
    'desc'  => '当前账号没有访问该资源的权限。',
    'hint'  => '检查 scaffold/accounts.yaml 里这个账号的 role，或者切换账号。',
])
