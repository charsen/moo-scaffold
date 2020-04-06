@extends('scaffold::layouts.app')

@section('title', '接口调试')

@section('styles')
@endsection

@section('sidebar')
    @foreach ($menus as $folder_name => $controllers)
    <li class="{{ (($folder_name == $current_folder || !$first_menu_active) ? 'open' : '') }}">
        <a href="javascript:;" class="long">{{ (isset($menus_transform[$folder_name])) ? $menus_transform[$folder_name] : $folder_name }}</a>
        <ul class="second">
            @foreach ($controllers as $controller_class => $attr)
                <li class="{{ ($controller_class == $current_controller ? 'open' : '') }}">
                    <a href="javascript:;"><em>{{ $attr['api_count'] }}</em>{{ $attr['name'] }}</a>
                    <ul class="sub">
                    @foreach ($apis[$folder_name][$controller_class] as $action => $api)
                        <li class="link_li {{ ($action == $current_action ? 'open active' : '') }}">
                            <a class="link long" href="javascript:;"
                               data-module="{{ $attr['name'] }}"
                               data-f="{{ $folder_name }}"
                               data-c="{{ $controller_class }}"
                               data-a="{{ $action }}"
                               data-m="{{ $api['method'] }}"
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
            <h2>Header</h2>
        </div>
        <div class="bd">
            <div class="dp-highlighter" id="header" style="min-height: auto; margin-bottom: 20px">

            </div>
        </div>

        <div class="hd">
            <h2>Response</h2>
        </div>
        <div class="bd">
            <div class="dp-highlighter" id="json_format"></div>
        </div>
    </div>
@endsection

@section('scripts')
<script src="/vendor/scaffold/javascript/jsonFormat.js"></script>
<script src="/vendor/scaffold/javascript/clipboard.min.js"></script>
<script>
    var cache_url = '{{ route('api.cache', [], false) }}';

    $('#right_container').removeClass('transparent');

    $('#aside_container a.link').click(function () {
        if ($(this).data('f') == undefined) return true;
        $('#result_status').html('');
        $('#result_uri').html('');
        $('#json_format').html('');

        window.history.pushState({}, 0, $(this).data('url'));

        $('#aside_container li.active').removeClass('active');
        $(this).parent().addClass('active');

        $('#left_container').html('<p class="loading">loading...</p>');

        getParams($(this).data('f'), $(this).data('c'), $(this).data('a'), $(this).data('m'));
    });

    var getResult = function(cache_key)
    {
        $.ajax({
            type: "GET",
            url: '{{ route('api.result') }}',
            data: {'key': cache_key},
            dataType: 'json',
            success: function (json) {
                if (json == '') return ;

                $('#header').html('<span class="font-orange">THE RESPONSE IS CACHED !</span>');
                $("#result_uri").html($("#host").val() + $("#uri").val());
                $('#result_status').html('CACHE').attr("class", "status font-orange");

                Process({
                    id: "json_format",
                    data: json
                });
        }
        });
    };

    var getParams = function(folder, controller, action, method)
    {
        document.title = $('#aside_container li.active a').data('module')
                       + ' - '
                       + $('#aside_container li.active a').html();

        $.ajax({
            type: "GET",
            url: '{{ route('api.param') }}',
            data: {'f': folder, 'c': controller, 'a': action},
            dataType: 'html',
            success: function (result) {
                $('#left_container').html(result);
                $('#header').html('');
                $("#result_method").html($("#send_method").val());

                var check = new RegExp(/^(index|authenticate|logout)[\_\w]+$/);
                if (check.test(action) || method == 'GET')
                {
                    $('#send').trigger('click');
                }
                else
                {
                    var cache_key = $('#cache_key').val();
                    getResult(cache_key);
                }
            }
        });
    };

    @if (!empty($current_controller) && ! empty($current_action))
    getParams('{{ $current_folder }}', '{{ $current_controller }}', '{{ $current_action }}', '{{ $current_method }}');
    @endif
</script>
@endsection
