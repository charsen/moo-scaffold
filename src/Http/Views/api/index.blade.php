@extends('scaffold::layouts.app')

@section('title', '接口文档')

@section('sidebar')
    @foreach ($menus as $folder_name => $controllers)
    <li class="{{ (! $first_menu_active ||  $folder_name == $current_folder) ? 'open' : '' }}">
        <a href="javascript:;" class="long">{{ (isset($menus_transform[$folder_name])) ? $menus_transform[$folder_name] : $folder_name }}</a>
        <ul class="sub tag-list">
            @foreach ($controllers as $controller_class => $attr)
            <li class="{{ (! $first_menu_active || $current_controller == $controller_class) ? 'active' : '' }}">
                <a href="javascript:;"
                   data-module="{{ $attr['name'] }}"
                   data-tag="{{ (str_replace('/', '-', $folder_name)) }}-{{ $controller_class }}"
                   data-url="{{ route('api.list', ['f' => $folder_name, 'c' => $controller_class]) }}"
                >
                    <em>{{ $attr['api_count'] }}</em>{{ $attr['name'] }}
                </a>
            </li>
            <?php $first_menu_active = true;?>
            @endforeach
        </ul>
    </li>
    @endforeach
@endsection

@section('middle')
<div class="panel">
    <div class="bd" id="table_list">
        @foreach ($apis as $folder_name => $data)
            @foreach ($data as $controller_class => $actions)
            <div class="table {{ (str_replace('/', '-', $folder_name)) }}-{{ $controller_class }} {{ (!$first_table_active || $current_controller == $controller_class ? 'show' : 'hide') }}">
                <table>
                    <tr>
                        <th>#</th>
                        <th>接口名称</th>
                        <th>方式</th>
                        <th>URL</th>
                    </tr>
                    <?php $api_index = 1;?>
                    @foreach ($actions as $action => $attr)
                        <tr class="{{($current_controller == $controller_class && $action == $current_action) ? 'active' : ''}}">
                            <td>{{ $api_index ++ }}</td>
                            <td>
                                <a class="link {{$controller_class}}_{{$action}}" href="javascript:;"
                                   data-f="{{ $folder_name }}"
                                   data-c="{{ $controller_class }}"
                                   data-a="{{ $action }}"
                                   data-url="{{ route('api.list', ['f' => $folder_name, 'c' => $controller_class, 'a' => $action]) }}">
                                    {{ $attr['name'] }}
                                </a>
                            </td>
                            <td>{{ strtoupper($attr['method']) }}</td>
                            <td>{{ $attr['url'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <?php $first_table_active = true; ?>
            @endforeach
        @endforeach
    </div>
</div>
@endsection

@section('right')
<div class="none">
    <img src="/scaffold/images/none.png">
    <h2>请选择接口</h2>
</div>
@endsection

@section('scripts')
<script>
    $('.tag-list a').click(function () {
        $('#table_list .show').removeClass('show').addClass('hide');
        $('#table_list').find('.' + $(this).data('tag')).removeClass('hide').addClass('show');
        $('.tag-list li.active').removeClass('active');
        $(this).parent().addClass('active');

        $('#right_container').html('<p class="loading">...</p>');

        window.history.pushState({}, 0, $(this).data('url'));
    });

    $('#table_list .link').click(function () {
        var _tr = $(this).parent().parent();
        var _table = _tr.parent();
        _table.find('tr').removeClass('active');
        _tr.addClass('active');

        window.history.pushState({}, 0, $(this).data('url'));

        $('#right_container').html('<p class="loading">loading...</p>');

        getParams($(this).data('f'), $(this).data('c'), $(this).data('a'));
    });

    var getParams = function(folder, controller, action)
    {
        document.title = $('.tag-list li.active a').data('module')
                       + '-'
                       + $('a.' + controller + '_' + action).html();
        $.ajax({
            type: "GET",
            url: '{{ route('api.show') }}',
            data: {'f': folder, 'c': controller, 'a': action},
            dataType: 'html',
            success: function (result) {
                $('#right_container').removeClass('transparent').html(result);
            }
        });
    }

    @if (!empty($current_controller) && ! empty($current_action))
    getParams('{{ $current_folder }}', '{{ $current_controller }}', '{{ $current_action }}');
    @endif
</script>
@endsection
