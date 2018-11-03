@extends('scaffold::layouts.app')

@section('title', '接口列表')

@section('content')
<div class="ui text container" style="max-width: none !important; width: 1200px" id="menu_top">
    <div class="ui floating message">
        <div class="ui grid container" style="max-width: none !important;">
            <div class="four wide column">
                <div class="ui vertical accordion menu">
                    @foreach ($menus as $folder_name => $controllers)
                    <div class="item">
                        <h4 class="title active" style="font-size:16px; margin:0px;">
                            <i class="dropdown icon"></i>{{ $folder_name }}
                        </h4>
                        <div class="content active" style="margin: 0 -16px -13px -16px;">
                        @foreach ($controllers as $controller_class => $attr)
                            <a class="item {{ (!$first_menu_active ? 'active' : '') }}" data-tab="{{ $folder_name }}-{{ $controller_class }}">
                                {{ $attr['name'] }} <font color="orange">({{ $attr['api_count'] }})</font>
                            </a>
                        <?php $first_menu_active = true;?>
                        @endforeach
                        </div>
                    </div>
                    @endforeach

                    <div class="item">
                        <div class="content">
                            <a href="#menu_top">返回顶部↑↑↑</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="twelve wide stretched column">
                @foreach ($apis as $folder_name => $data)
                    @foreach ($data as $controller_class => $actions)
                    <div class="ui tab {{ (!$first_table_active ? 'active' : '') }}" data-tab="{{ $folder_name}}-{{ $controller_class }}">
                        <table class="ui red celled striped table {{ $table_style }} celled striped table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>接口名称</th>
                                    <th>方式</th>
                                    <th>URL</th>
                                    <th>更多说明</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $api_index = 1;?>
                                @foreach ($actions as $action => $attr)
                                <tr>
                                    <td>{{ $api_index ++ }}</td>
                                    <td>
                                        <a href="{{ route('api.show', ['f' => $folder_name, 'c' => $controller_class, 'a' => $action]) }}"
                                            target="{{ $action }}">{{ $attr['name'] }}</a>
                                     </td>
                                    <td>{{ strtoupper($attr['method']) }}</td>
                                    <td>{{ $attr['url'] }}</td>
                                    <td>{{ implode("\n", $attr['desc']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <?php $first_table_active = true;?>
                    @endforeach
                @endforeach
            </div>
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
