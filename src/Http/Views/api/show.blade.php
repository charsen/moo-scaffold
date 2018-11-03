@extends('scaffold::layouts.app')

@section('title', $name . ' : ' . $request[1])

@section('content')
<div class="ui text container" style="max-width: none !important;">
    <div class="ui floating message">
        <h2 class='ui header'>
            <a href="{{ route('api.request', ['f' => $current_folder, 'c' => $current_controller, 'a' => $current_action]) }}" target="_method">
                {{ strtoupper($request[0]) }} : {{ $request[1] }}
            </a>
        </h2>
        <br /> <span class='ui teal tag label'>{{ $name }}</span>
        <div class="ui raised segment">
            <span class="ui red ribbon label">详细描述</span>
            <div class="ui message">
                @foreach ($desc as $v)
                    <p> {{ $v }}</p>
                @endforeach
            </div>
        </div>
        <h3><i class="sign in alternate icon"></i>Headers Params</h3>
        <table class="ui blue celled striped table">
            <thead>
                <tr>
                    <th width="25%">参数</th>
                    <th width="20%">名称</th>
                    <th width="20%">值</th>
                    <th width="35%">说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><font color="red">*</font> Accept</td>
                    <td></td>
                    <td>application/json</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        @if ( ! empty($url_params))
        <h3><i class="sign in alternate icon"></i>Url Params</h3>
        <table class="ui orange celled striped table">
            <thead>
                <tr>
                    <th width="25%">参数</th>
                    <th width="20%">名称</th>
                    <th width="20%">值</th>
                    <th width="35%">说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($url_params as $key => $v)
                <tr>
                    <td>
                        @if ($v[0])
                            <font color="red">*</font>
                        @else
                            &nbsp;
                        @endif
                        {{ $key }}
                    </td>
                    <td>{{ $v[1] }}</td>
                    <td>{{ $v[2] }}</td>
                    <td>{{ $v[3] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        @if ( ! empty($body_params))
        <h3><i class="sign in alternate icon"></i>Body Params</h3>
        <table class="ui green celled striped table">
            <thead>
                <tr>
                    <th width="25%">参数</th>
                    <th width="20%">名称</th>
                    <th width="20%">值</th>
                    <th width="35%">说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($body_params as $key => $v)
                    <tr>
                        <td>
                            @if ($v[0])
                                <font color="red">*</font>
                            @else
                                &nbsp;
                            @endif
                            {{ $key }}
                        </td>
                        <td>{{ $v[1] }}</td>
                        <td>{{ $v[2] }}</td>
                        <td>{{ $v[3] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        @include('scaffold::layouts._footer')
    </div>
</div>
@endsection
