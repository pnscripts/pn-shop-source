<x-filament-widgets::widget>
    <x-filament::section heading="Euro check (Bulgaria)">
        <ul style="display: grid; gap: 0.5rem; margin: 0; padding: 0; list-style: none">
            @foreach ($results as $result)
                <li style="display: flex; gap: 0.5rem; align-items: baseline">
                    <span aria-hidden="true" style="color: {{ $result['ok'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }}">{{ $result['ok'] ? '✓' : '✗' }}</span>
                    <span>{{ $result['message'] }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
