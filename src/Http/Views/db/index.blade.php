@extends('scaffold::layouts.app')

@section('title', '数据库文档')

@section('content')
<div class="ui text container" style="max-width: none !important; width: 1200px" id="menu_top">
    <div class="ui floating message">
        <div class="ui grid container" style="max-width: none !important;">
            <div class="four wide column">
                <div class="ui vertical accordion menu">
                    <div class="item">
                        <h4 class="title active" style="font-size:16px; margin:0px;">
                            <i class="dropdown icon"></i>DB
                        </h4>
                        <div class="content active" style="margin: 0 -16px -13px -16px;">
                            @foreach ($menus as $file_name => $folder)
                                <a class="item {{ !$first_menu_active ? 'active' : '' }}" data-tab="{{ $file_name }}">
                                    {{ $folder['folder_name'] }} <font color="orange">({{ $folder['tables_count'] }})</font>
                                </a>
                                <?php $first_menu_active = true;?>
                            @endforeach
                        </div>
                    </div>

                    <div class="item">
                        <div class="content">
                            <a href="{{ route('dictionaries') }}" target="dictionaries">数据字典</a>
                        </div>
                    </div>
                    <div class="item">
                        <div class="content">
                            <a href="#menu_top">返回顶部↑↑↑</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="twelve wide stretched column">
                @foreach ($menus as $file_name => $folder)
                <div class="ui tab {{ (!$first_table_active ? 'active' : '') }}" data-tab="{{ $file_name }}">
                    <table class="ui red celled striped table {{ $table_style }} celled striped table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th width="25%">表名</th>
                                <th width="25%">名称</th>
                                <th>更多说明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $index = 1; ?>
                            @foreach ($folder['tables'] as $table_name => $table)
                            <tr>
                                <td>{{ $index++ }}</td>
                                <td>
                                    <a href="{{ route('table.show', ['name' => $table_name]) }}" target="{{ $table_name  }}">
                                        {{ $table_name  }}
                                    </a>
                                </td>
                                <td>{{ $table['name'] }}</td>
                                <td>{{ $table['desc'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                    <?php $first_table_active = true; ?>
                @endforeach
            </div>
        </div>

        <div class="ui blue message">
            <strong>温馨提示：</strong>
        </div>
        @include('scaffold::layouts._footer')
    </div>
</div>
@endsection

@section('scripts')
<script type="text/javascript">
    $('.accordion.menu a.item').tab({ 'deactivate': 'all' });
    $('.ui.sticky').sticky();

    //当点击跳转链接后，回到页面顶部位置
    $(".accordion.menu a.item").click(function() {
        $('body,html').animate({
                scrollTop: 0
            },
            500);
        return false;
    });

    $('.ui.accordion').accordion({ 'exclusive': false });
</script>
@endsection
