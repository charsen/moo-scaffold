<h2 class="title">
    <div class="group">
        @if ( ! empty($prototype))
            <a href="{{$prototype}}" target="_blank"><i class="icon-prot"></i></a>
        @endif
        <a href="{{ route('api.request', ['f' => $current_folder, 'c' => $current_controller, 'a' => $current_action]) }}" target="_blank">
            <i class="icon-debug"></i></a>
    </div>

    <em>{{ strtoupper($request[0]) }}</em>
    {{ $name }} : {{ $request[1] }}
</h2>

@if ( ! empty($desc))
<div class="alert">
    <h3>详情描述</h3>
    @foreach ($desc as $v)
        <p> {{ $v }}</p>
    @endforeach
</div>
@endif

<div class="panel">
    <div class="hd">
        <h3>Headers Params</h3>
    </div>
    <div class="bd">
        <div class="table" id="request_header">
            <table>
                <tr>
                    <th width="25%">参数</th>
                    <th width="20%">名称</th>
                    <th width="25%">值</th>
                    <th>说明</th>
                </tr>
                <tr>
                    <td><em class="font-red">*</em> Accept</td>
                    <td></td>
                    <td>application/json</td>
                    <td></td>
                </tr>
                @if (isset($header_params['token']))
                <tr>
                    <td><em class="font-red">*</em> Authorization</td>
                    <td></td>
                    <td>Bearer {Token}</td>
                    <td></td>
                </tr>
                @endif
            </table>
        </div>
    </div>
</div>


@if (! empty($url_params))
    <div class="panel">
        <div class="hd">
            <h3>Url Params</h3>
        </div>
        <div class="bd">
            <div class="table" id="request_header">
                <table>
                    <tr>
                        <th width="25%">参数</th>
                        <th width="20%">名称</th>
                        <th width="25%">值</th>
                        <th>说明</th>
                    </tr>
                    @foreach ($url_params as $key => $v)
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
                        <td>{{ $v['value'] }}</td>
                        <td>{{ $v['desc'] }}</td>
                    </tr>
                @endforeach
                </table>
            </div>
        </div>
    </div>
@endif

@if (! empty($body_params))
    <div class="panel">
        <div class="hd">
            <h3>Body Params</h3>
        </div>
        <div class="bd">
            <div class="table" id="request_header">
                <table>
                    <tr>
                        <th width="25%">参数</th>
                        <th width="20%">名称</th>
                        <th width="25%">值</th>
                        <th>说明</th>
                    </tr>
                    @foreach ($body_params as $key => $v)
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
                            <td>{{ $v['value'] }}</td>
                            <td>{{ $v['desc'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>
@endif
