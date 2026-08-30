@extends('layouts.app')

@section('title', 'Statistiken — Wear Together Order Suite')

@section('content')
    <div class="card">
        <h1>Statistiken</h1>

        <div class="alert error">
            ✖ {{ $error }}
            @if ($technical)
                <details class="warnrows" open>
                    <summary>Technische Details (zum Kopieren, für Support)</summary>
                    <textarea readonly rows="3" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ $technical }}</textarea>
                </details>
            @endif
        </div>

        <p class="hint">
            Die Zugangsdaten stehen in der <code>.env</code>; nach einer Änderung
            <code>php artisan config:cache</code> ausführen oder neu deployen. Ob die Verbindung steht, zeigen die
            <a href="{{ route('admin.status') }}">Admin-Informationen</a>.
        </p>
    </div>
@endsection
