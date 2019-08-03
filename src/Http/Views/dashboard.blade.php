@extends('scaffold::layouts.two_columns')

@section('title', 'Charsen/Laravel-Scaffold')

@section('right')
<div class="panel">
    <div class="bd">
        <div style="max-width: none !important;">
            - <a href="{{ route('table.list') }}" target="db">DB</a>
        </div>
        <div style="max-width: none !important;">
            - <a href="{{ route('api.list') }}" target="api">Api</a>
        </div>
    </div>
</div>
@endsection
