@extends('layouts.app')

@section('title', 'Fehler — Wear Together Order Suite')

@section('content')
    <div class="card">
        <h1>Es ist ein unerwarteter Fehler aufgetreten</h1>
        <p class="lead">
            Das ist nicht deine Schuld — bitte die technischen Details unten kopieren und an den
            Support schicken, dann kann das Problem gezielt behoben werden.
        </p>

        <div class="alert error">
            ✖ <strong>{{ class_basename($exception) }}</strong>: {{ $exception->getMessage() ?: '(keine Meldung)' }}
        </div>

        <details class="warnrows" open>
            <summary>Technische Details (zum Kopieren)</summary>
            <textarea readonly rows="10" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.5rem;" onclick="this.select()">{{ get_class($exception) }}: {{ $exception->getMessage() }}
Datei: {{ $exception->getFile() }}:{{ $exception->getLine() }}
URL: {{ request()->fullUrl() }}
Zeitpunkt: {{ now()->toDateTimeString() }}

{{ $exception->getTraceAsString() }}</textarea>
        </details>

        <div style="margin-top:1rem;">
            <a class="btn" href="{{ url()->previous() }}">Zurück</a>
            <a class="btn secondary" href="{{ route('home') }}" style="margin-left:0.5rem;">Zur Startseite</a>
        </div>

        <p class="hint" style="margin-top:1rem;">
            Der vollständige Fehler steht außerdem im Server-Log unter <code>storage/logs/laravel.log</code>.
        </p>
    </div>
@endsection
