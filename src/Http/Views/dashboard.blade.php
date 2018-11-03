@extends('scaffold::layouts.app')

@section('title', 'Charsen/Laravel-Scaffold')

@section('content')
<div class="ui text container" style="max-width: none !important; width: 1200px" id="menu_top">
    <div class="ui green message">
        <strong>Coming soon!</strong>
    </div>

    <div class="ui floating message">
        <div class="ui grid container" style="max-width: none !important;">
            - <a href="{{ route('table.list') }}" target="db">DB</a>
        </div>
        <div class="ui grid container" style="max-width: none !important;">
            - <a href="{{ route('api.list') }}" target="api">Api</a>
        </div>
    </div>
</div>
@endsection
