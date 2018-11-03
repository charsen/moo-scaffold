<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="stylesheet" href="/scaffold/css/index.css" />
    <link rel="stylesheet" href="/scaffold/css/SyntaxHighlighter.css" />
    <title>{{ $name . ' : ' . $request[1] }}</title>
    <meta name="robots" content="none" />
</head>

<body>
    <div class="main">
        <div class="aside">
            <ul>
                @foreach ($menus as $folder_name => $controllers)
                <li class="{{ ($folder_name == $current_folder ? 'open' : '') }}">
                    <a href="javascript:;">{{ $folder_name }}</a>
                    <ul class="second">
                        @foreach ($controllers as $controller_class => $attr)
                        <li class="{{ ($controller_class == $current_controller ? 'open' : '') }}">
                            <a href="javascript:;">{{ $attr['name'] }} ({{ $attr['api_count'] }})</a>
                            <ul class="sub">
                                @foreach ($apis[$folder_name][$controller_class] as $action => $api)
                                <li>
                                    <a href="{{ route('api.request', ['f' => $folder_name, 'c' => $controller_class, 'a' => $action]) }}"
                                        class="{{ ($folder_name == $current_folder && $controller_class == $current_controller && $action == $current_action ? 'active' : '') }}">
                                        · {{ $api['name'] }}
                                    </a>
                                </li>
                                @endforeach
                            </ul>
                        </li>
                        @endforeach
                    </ul>
                </li>
                @endforeach
            </ul>
        </div>

        <div class="container">
            <div class="panel">
                <div class="hd">
                    <h3><strong>{{ $name }}</strong></h3>
                </div>
                <div class="bd">
                    <div class="send-box">
                        <a href="javascript:;" class="btn" id="send">发送</a>
                        <select id="send_method">
                            <option value="{{ strtoupper($request[0]) }}">{{ strtoupper($request[0]) }}</option>
                        </select>
                        <input type="text" class="txt" id="uri" value="{{ $request_url }}/{{ $request[1] }}">
                    </div>
                </div>

                <div class="hd">
                    <h3>Headers</h3>
                </div>
                <div class="bd">
                    <table id="request_header">
                        <tr>
                            <th><input type="checkbox" class="checkbox-all"><th>名称</th>
                            <th>key</th>
                            <th>value</th>
                            <th>说明</th>
                        </tr>
                        <tr>
                            <td><input type="checkbox" class="checkbox" checked></td>
                            <td><input type="text" value="Accept" class="txt" readonly ></td>
                            <td><input type="text" value="Accept" class="txt key" readonly ></td>
                            <td><input type="text" value="application/json" class="txt value" readonly ></td>
                            <td><input type="text" class="txt" readonly ></td>
                        </tr>
                    </table>
                </div>

                @if ( ! empty($url_params))
                <div class="hd">
                    <h3>Url Params</h3>
                </div>
                <div class="bd">
                    <table id="url_params">
                        <tr>
                            <th><input type="checkbox" class="checkbox-all"><th>名称</th>
                            <th>key</th>
                            <th>value</th>
                            <th>说明</th>
                        </tr>
                        @foreach ($url_params as $key => $v)
                        <tr>
                            <td><input type="checkbox" class="checkbox" {{ (($v[0]) ? 'checked' : '') }}></td>
                            <td><input type="text" value="{{ $v[1] }}" class="txt" readonly ></td>
                            <td><input type="text" value="{{ $key }}" class="txt key" readonly ></td>
                            <td><input type="text" value="{{ $v[2] }}" class="txt value"></td>
                            <td><input type="text" value="{{ $v[3] }}" class="txt" readonly ></td>
                        </tr>
                        @endforeach
                    </table>
                </div>
                @endif

                @if ( ! empty($body_params))
                <div class="hd">
                    <h3>Body Params</h3>
                </div>
                <div class="bd">
                    <table id="request_params">
                        <tr>
                            <th><input type="checkbox" class="checkbox-all"><th>名称</th>
                            <th>key</th>
                            <th>value</th>
                            <th>说明</th>
                        </tr>
                        @foreach ($body_params as $key => $v)
                        <tr>
                            <td><input type="checkbox" class="checkbox" {{ (($v[0]) ? 'checked' : '') }}></td>
                            <td><input type="text" value="{{ $v[1] }}" class="txt" readonly ></td>
                            <td><input type="text" value="{{ $key }}" class="txt key" readonly ></td>
                            <td><input type="text" value="{{ $v[2] }}" class="txt value"></td>
                            <td><input type="text" value="{{ $v[3] }}" class="txt" readonly ></td>
                        </tr>
                        @endforeach
                    </table>
                </div>
                @endif
            </div>

            <div class="panel">
                <div class="hd">
                    <h3>响应Body</h3>
                </div>
                <div class="bd">
                    <textarea class="dp-highlighter"></textarea>
                </div>
            </div>
        </div>
    </div>

    <script src="https://libs.baidu.com/jquery/1.11.3/jquery.min.js"></script>
    <script src="https://cdn.bootcss.com/jquery-cookie/1.4.1/jquery.cookie.min.js"></script>
    <script src="/scaffold/javascript/shCore.js"></script>
    <script src="/scaffold/javascript/shBrushJScript.js"></script>
    <script src="/scaffold/javascript/jQuery.beautyOfCode.js"></script>
    <script src="/scaffold/javascript/main.js"></script>
</body>

</html>
