@extends('scaffold::layouts.app')

@section('title', '数据库文档')

@section('sidebar')
<li class="open">
    <a href="javascript:;">Database</a>
    <ul class="sub tag-list">
    @foreach ($menus as $file_name => $folder)
        <li class="{{ (! $first_menu_active || $current_file == $file_name) ? 'active' : '' }}">
            <a href="javascript:;" {{$current_file}}
                data-module="{{ $folder['folder_name'] }}"
                data-tag="{{ $file_name }}"
                data-url="{{ route('table.list', ['name' => $file_name]) }}"
            >
                <em>{{ $folder['tables_count'] }}</em>{{ $folder['folder_name'] }}
            </a>
        </li>
        <?php $first_menu_active = true;?>
    @endforeach
    </ul>
</li>
@endsection

@section('middle')
<div class="panel">
    <div class="bd" id="table_list">
    @foreach ($menus as $file_name => $folder)
        <div class="table {{ $file_name }} {{ (!$first_table_active || ($current_file == $file_name) ? 'show' : 'hide') }}">
            <table>
                <tr>
                    <th>#</th>
                    <th>表名</th>
                    <th>名称</th>
                </tr>
                <?php $index = 1; ?>
                @foreach ($folder['tables'] as $table_name => $table)
                <tr class="{{ ($current_table == $table_name) ? 'active' : '' }}">
                    <td>{{ $index++ }}</td>
                    <td>
                        <a class="link {{ $table_name }}"
                           href="javascript:;"
                           data-table="{{ $table_name }}"
                           data-url="{{ route('table.list', ['name' => $file_name, 'table' => $table_name]) }}"
                        >{{ $table_name  }}</a>
                    </td>
                    <td class="{{ $table_name }}">{{ $table['name'] }}</td>
                </tr>
                @endforeach
            </table>
        </div>
        <?php $first_table_active = true; ?>
    @endforeach
    </div>
</div>
@endsection

@section('right')
<div class="none">
    <img src="/scaffold/images/none.png">
    <h2>请选择数据表</h2>
</div>
@endsection

@section('scripts')
<script>
    $('.tag-list a').click(function () {
        $('#table_list .show').removeClass('show').addClass('hide');
        $('#table_list').find('.' + $(this).data('tag')).removeClass('hide').addClass('show');
        $('.tag-list li.active').removeClass('active');
        $(this).parent().addClass('active');
        $('#right_container').html('');

        window.history.pushState({}, 0, $(this).data('url'));
    });

    $('#table_list .link').click(function () {
        var _tr = $(this).parent().parent();
        var _table = _tr.parent();
        _table.find('tr').removeClass('active');
        _tr.addClass('active');

        window.history.pushState({}, 0, $(this).data('url'));

        $('#right_container').html('<p class="loading">loading...</p>');

        getTable($(this).data('table'));
    });

    var getTable =function (table_name) {
        document.title = $('#aside_container li.active a').data('module')
                       + '-'
                       + $('td.' + table_name).html();

        $.ajax({
            type: "GET",
            url: '{{ route('table.show') }}',
            data: {'name': table_name},
            dataType: 'html',
            success: function (result) {
                $('#right_container').removeClass('transparent').html(result);
            }
        });
    };
    @if (! empty($current_table))
        getTable('{{$current_table}}');
    @endif
</script>
@endsection
