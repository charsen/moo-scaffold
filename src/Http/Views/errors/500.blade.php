@include('scaffold::errors._layout', [
    'code'  => '500',
    'title' => '服务器开了个小差',
    'desc'  => '后端出了点意料之外的状况，工程师可从 Moo Scaffold Cloud 的 Runtimes 看堆栈。',
    'hint'  => '请稍后重试。如果反复出现，请检查 storage/logs/laravel.log。',
])
