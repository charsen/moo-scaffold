@extends('scaffold::layouts.app')

@section('title', 'Database Dictionaries')

@section('content')
<div class="ui text container" style="max-width: none !important;">
    <div class="ui floating message">
        <h2 class='ui header'>数据字典</h2>
        <div class="ui raised segment">
            <span class="ui red ribbon label">说明</span>
            <div class="ui message">
                <p>所有数据表的字典汇集</p>
            </div>
        </div>

        <?php foreach ($data as $table => $dictionaries): ?>
            <?php if (empty($dictionaries)) continue; ?>
        <h3><i class="sign in alternate icon"></i>数据表：<?=$table;?></h3>
        <table class="ui green celled striped table">
            <thead>
                <tr>
                    <th>值</th>
                    <th>英文</th>
                    <th>中文</th>
                    <th>说明</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dictionaries as $key => $row): ?>
                <tr>
                    <td colspan="4" style="background: #eee">
                        <font color="red">字段：<?=$key;?></font>
                    </td>
                </tr>
                    <?php foreach ($row as $v): ?>
                    <tr>
                        <td><?=$v[0];?></td>
                        <td><?=$v[1];?></td>
                        <td><?=$v[2];?></td>
                        <td><?=$v[3] ?? '';?></td>
                    </tr>
                    <?php endforeach;?>
                <?php endforeach;?>
            </tbody>
        </table>
        <?php endforeach;?>

        @include('scaffold::layouts._footer')
    </div>
</div>
@endsection
