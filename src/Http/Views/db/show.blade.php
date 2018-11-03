@extends('scaffold::layouts.app')

@section('title', '数据表: ' . $data['table_name'])

@section('styles')
<link rel="stylesheet" href="https://staticfile.qnssl.com/semantic-ui/2.1.6/components/table.min.css">
<link rel="stylesheet" href="https://staticfile.qnssl.com/semantic-ui/2.1.6/components/container.min.css">
<link rel="stylesheet" href="https://staticfile.qnssl.com/semantic-ui/2.1.6/components/message.min.css">
<link rel="stylesheet" href="https://staticfile.qnssl.com/semantic-ui/2.1.6/components/label.min.css">
@endsection

@section('content')
<div class="ui text container" style="max-width: none !important;">
    <div class="ui floating message">
        <h2 class='ui header'>数据表：{{ $data['table_name'] }}</h2>
        <br /> <span class='ui teal tag label'>{{ $data['name'] }}</span>
        <div class="ui raised segment">
            <span class="ui red ribbon label">详细描述</span>
            <div class="ui message">
                <p> {{ $data['desc'] }}</p>
            </div>
        </div>

        @if ( ! empty($data['remark']))
            <div class="ui raised segment">
                <span class="ui orange ribbon label">备注说明</span>
                <div class="ui message">
                    @foreach ($data['remark'] as $remark)
                        <p> {{ $remark }}</p>
                    @endforeach
                </div>
            </div>
        @endif

        <h3><i class="sign in alternate icon"></i>索引</h3>
        <table class="ui orange celled striped table">
            <thead>
                <tr>
                    <th>索引名</th>
                    <th>字段</th>
                    <th>类型</th>
                    <th>方法</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['index'] as $key => $v)
                <tr>
                    <td>{{ $key }}</td>
                    <td>{{ $v['fields'] }}</td>
                    <td>{{ $v['type'] }}</td>
                    <td>{{ $v['method'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <h3><i class="sign in alternate icon"></i>字段</h3>
        <table class="ui green celled striped table">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>名称</th>
                    <th>类型</th>
                    <th>允许为空</th>
                    <th>默认值</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['fields'] as $key => $v)
                <tr>
                    <td>
                        @if ($v['require'])
                            <font color="red">*</font>
                        @else
                            &nbsp;
                        @endif
                        {{ $key }}
                    </td>
                    <td>{{ $v['name'] }}</td>
                    <td>
                        @if (in_array($v['type'], ['int', 'bigint', 'tinyint']) && $v['unsigned'])
                            <font color="orange">unsigned</font>
                        @endif
                        {{ $v['type'] }}
                        @if (! empty($v['size']))
                            ({{ $v['size'] }})
                        @endif
                    </td>
                    <td>
                        @if ($v['allow_null'])
                            <font color="teal">yes</font>
                        @else
                            no
                        @endif
                    </td>
                    <td>{{ $v['default'] }}</td>
                    <td>{{ $v['desc'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if (! empty($data['dictionaries']))
        <h3><i class="sign in alternate icon"></i>字典</h3>
        <table class="ui green celled striped table">
            <thead>
                <tr>
                    <th>值</th>
                    <th>英文</th>
                    <th>中文</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['dictionaries'] as $key => $row)
                <tr>
                    <td colspan="4" style="background: #eee"><font color="red">字段：{{ $key }}</font></td>
                </tr>
                    @foreach ($row as $v)
                    <tr>
                        <td>{{ $v[0] }}</td>
                        <td>{{ $v[1] }}</td>
                        <td>{{ $v[2] }}</td>
                        <td>{{ $v[3] ?? '' }}</td>
                    </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        @endif

        @include('scaffold::layouts._footer')
    </div>
</div>
@endsection
