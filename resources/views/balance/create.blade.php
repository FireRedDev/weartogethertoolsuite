@extends('layouts.app')

@section('title', 'Neuer Auftrag')

@section('content')
    <h1 style="margin-bottom:0.25rem;">Neuer Auftrag</h1>
    <p class="lead">
        <a href="{{ route('balance.index') }}">← zurück zur Auftragsbilanz</a>
    </p>

    <form method="post" action="{{ route('balance.store') }}">
        @csrf
        @include('balance._form', ['order' => $order, 'productTypes' => $productTypes, 'onboardings' => $onboardings])

        <div style="display:flex;gap:0.6rem;align-items:center;">
            <button class="btn" type="submit">Auftrag anlegen</button>
            <a class="btn secondary" href="{{ route('balance.index') }}">Abbrechen</a>
        </div>
    </form>
@endsection
