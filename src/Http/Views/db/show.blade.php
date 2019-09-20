<h2 class="title">{{ $data['name'] }} ：{{ $data['table_name'] }}</h2>
<div class="alert">
    <p>{{ $data['desc'] }}</p>
</div>

@if ( ! empty($data['remark']))
<div class="remark">
    <h3>备注说明</h3>
    @foreach ($data['remark'] as $remark)
        <p> {{ $remark }}</p>
    @endforeach
</div>
@endif

@if ( ! empty($data['index']))
<div class="panel">
    <div class="hd">
        <h3><i class="icon-key"></i>索引</h3>
    </div>
    <div class="bd">
        <div class="table" id="request_header">
            <table>
                <tr>
                    <th>索引名</th>
                    <th>字段</th>
                    <th>类型</th>
                    <th>方法</th>
                </tr>
                @foreach ($data['index'] as $key => $v)
                    <tr>
                        <td>{{ $key }}</td>
                        <td>{{ $v['fields'] }}</td>
                        <td>{{ $v['type'] }}</td>
                        <td>{{ $v['method'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>
@endif

<div class="panel">
    <div class="hd">
        <h3><i class="icon-code"></i>字段</h3>
    </div>
    <div class="bd">
        <div class="table" id="request_header">
            <table>
                <tr>
                    <th>字段</th>
                    <th>名称</th>
                    <th>类型</th>
                    <th>允许为空</th>
                    <th>默认值</th>
                    <th>说明</th>
                </tr>
                @foreach ($data['fields'] as $key => $v)
                <tr>
                    <td>
                        @if ($v['require'])
                            <em class="font-red">*</em>
                        @else
                            &nbsp;
                        @endif
                        {{ $key }}
                    </td>
                    <td>{{ $v['name'] }}</td>
                    <td>
                        @if (in_array($v['type'], ['int', 'bigint', 'tinyint']) && $v['unsigned'])
                            <em class="font-orange">unsigned</em>
                        @endif
                        {{ $v['type'] }}
                        @if (isset($v['size']))
                            ({{ $v['size'] }})
                        @endif
                    </td>
                    <td>
                        @if ($v['allow_null'])
                            <em class="font-green">yes</em>
                        @else
                            no
                        @endif
                    </td>
                    <td>{{ $v['default'] ?? '' }}</td>
                    <td>{{ $v['desc'] }}</td>
                </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>

@if (! empty($data['dictionaries']))
<div class="panel">
    <div class="hd">
        <h3><i class="icon-wordbook"></i>字典</h3>
    </div>
    <div class="bd">
        <div class="table" id="request_header">
            <table>
                <tr>
                    <th>值</th>
                    <th>英文</th>
                    <th>中文</th>
                    <th>说明</th>
                </tr>
                @foreach ($data['dictionaries'] as $key => $row)
                    <tr>
                        <td colspan="4" style="background-color: #EEE;"><em class="font-red">字段：{{ $key }}</em></td>
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
            </table>
        </div>
    </div>
</div>
@endif
