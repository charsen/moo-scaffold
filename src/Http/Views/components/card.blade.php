@props([
    'title' => null,
    'flush' => false,
    'raised' => false,
    'ghost' => false,
])

<section {{ $attributes->class([
    'card',
    'card--flush' => $flush,
    'card--raised' => $raised,
    'card--ghost' => $ghost,
]) }}>
    @if($title || isset($actions))
        <header class="card__header">
            @if($title)
                <h3 class="card__title">{{ $title }}</h3>
            @endif
            @isset($actions)
                <div class="card__actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="card__body">{{ $slot }}</div>

    @isset($footer)
        <footer class="card__footer">{{ $footer }}</footer>
    @endisset
</section>
