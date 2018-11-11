@extends('scaffold::layouts.app')

@section('title', '接口调试')

@section('styles')
    <link rel="stylesheet" href="/scaffold/css/SyntaxHighlighter.css?v={{$version}}" />
@endsection

@section('sidebar')
    @foreach ($menus as $folder_name => $controllers)
    <li class="{{ (($folder_name == $current_folder || !$first_menu_active) ? 'open' : '') }}">
        <a href="javascript:;">{{ (isset($menus_transform[$folder_name])) ? $menus_transform[$folder_name] : $folder_name }}</a>
        <ul class="second">
            @foreach ($controllers as $controller_class => $attr)
                <li class="{{ ($controller_class == $current_controller ? 'open' : '') }}">
                    <a href="javascript:;"><em>{{ $attr['api_count'] }}</em>{{ $attr['name'] }}</a>
                    <ul class="sub">
                    @foreach ($apis[$folder_name][$controller_class] as $action => $api)
                        <li class="link_li {{ ($action == $current_action ? 'open active' : '') }}">
                            <a class="link" href="javascript:;"
                               data-f="{{ $folder_name }}"
                               data-c="{{ $controller_class }}"
                               data-a="{{ $action }}"
                               data-url="{{ route('api.request', ['f' => $folder_name, 'c' => $controller_class, 'a' => $action]) }}"
                            >
                                {{ $api['name'] }}
                            </a>
                        </li>
                    @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    </li>
    <?php $first_menu_active = true;?>
    @endforeach
@endsection

@section('middle')
    @if (empty($current_controller) && empty($current_action))
        <p class="loading">......</p>
    @endif
@endsection

@section('right')
    <div class="panel">
        <div class="bd">
            <div class="send-box">
                <p class="status" id="result_status"></p>
                <p class="method-type" id="result_method"></p>
                <p class="result-txt" id="result_uri"></p>
            </div>
        </div>
    </div>
    <div class="panel">
        <div class="hd">
            <h2>Response</h2>
        </div>
        <div class="bd">
            <div class="dp-highlighter"></div>
        </div>
    </div>
@endsection

@section('scripts')
<script src="/scaffold/javascript/shCore.js?v={{$version}}"></script>
<script src="/scaffold/javascript/shBrushJScript.js?v={{$version}}"></script>
<script src="/scaffold/javascript/jQuery.beautyOfCode.js?v={{$version}}"></script>
<script src="/scaffold/javascript/clipboard.min.js?v={{$version}}"></script>
<script>
    $('#right_container').removeClass('transparent');

    $('#aside_container a.link').click(function () {
        if ($(this).data('f') == undefined) return true;
        $('#result_status').html('');
        $('#result_status').html('');
        $('#result_uri').html('');
        $('.dp-highlighter').html('');

        window.history.pushState({}, 0, $(this).data('url'));

        $('#aside_container li.active').removeClass('active');
        $(this).parent().addClass('active');

        $('#left_container').html('<p class="loading">loading...</p>');

        getParams($(this).data('f'), $(this).data('c'), $(this).data('a'));
    });

    var getParams = function(folder, controller, action)
    {
        $.ajax({
            type: "GET",
            url: '{{ route('api.param') }}',
            data: {'f': folder, 'c': controller, 'a': action},
            dataType: 'html',
            success: function (result) {
                $('#left_container').removeClass('transparent').html(result);
            }
        });
    }
    @if (!empty($current_controller) && ! empty($current_action))
    getParams('{{ $current_folder }}', '{{ $current_controller }}', '{{ $current_action }}');
    @endif
</script>
@endsection
