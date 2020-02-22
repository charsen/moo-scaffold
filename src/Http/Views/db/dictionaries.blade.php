@extends('scaffold::layouts.two_columns')

@section('title', '数据字典')

@section('sidebar')
    <li class="open">
        <a href="javascript:;">Enterprise</a>
        <ul class="sub tag-list">
        @foreach ($menus as $file_name => $folder)
            <li>
                <a href="{{ route('table.list', ['name' => $file_name]) }}">
                    <em>{{ $folder['tables_count'] }}</em>{{ $folder['folder_name'] }}
                </a>
            </li>
        @endforeach
        </ul>
    </li>
@endsection

@section('right')
    <h2 class="title">数据字典</h2>
    <div class="alert">
        <h3>数据字典</h3>
        <p>所有数据表的字典汇集</p>
    </div>

    @foreach ($data as $table => $dictionaries)
        @if (empty($dictionaries))
            @continue
        @endif

    <a name="{{ $table }}"></a>
    <div class="panel">
        <div class="hd">
            <h3><i class="icon-wordbook"></i>数据表：{{ $table }}</h3>
        </div>
        <div class="bd">
            <div class="table" id="request_header">
                <table>
                    <tr>
                        <th width="15%">值</th>
                        <th width="25%">英文</th>
                        <th width="30%">中文</th>
                        <th width="30%">说明</th>
                    </tr>
                    @foreach ($dictionaries as $key => $row)
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
    @endforeach
@endsection

@section('scripts')
<script>
    $('.tag-list a').click(function () {
        $('#table_list .show').removeClass('show').addClass('hide');
        $('#table_list').find('.' + $(this).data('tag')).removeClass('hide').addClass('show');
    });

    $('#table_list .link').click(function () {
        var _tr    = $(this).parent().parent();
        var _table = _tr.parent();
        _table.find('tr').removeClass('active');
        _tr.addClass('active');
    });

    $('#right_container').removeClass('transparent');
</script>
@endsection
