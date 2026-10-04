@extends('pnshop-installer::layout', ['step' => 3])

@section('content')
    <p class="ok"><strong>PN Shop is installed.</strong></p>
    <ul class="muted">
        @foreach ($steps as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
    <p>Sign in to the admin panel as <strong>{{ $email }}</strong> to add products, payment and shipping methods.</p>
    <a class="button" href="{{ url('/admin') }}">Open the admin panel</a>
    <a class="button" href="{{ url('/') }}" style="background: transparent; color: var(--accent); border: 1px solid var(--border);">View the shop</a>
    <p class="muted" style="margin-top: 24px;">The installer is now switched off. Set up the scheduler (<code>php artisan schedule:run</code> every minute): it also sends the emails. On a server, a queue worker sends them at once; see the installation guide.</p>
@endsection
