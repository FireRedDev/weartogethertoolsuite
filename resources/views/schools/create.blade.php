@extends('layouts.app')

@section('title', 'Schule anlegen — Wear Together Order Suite')

@section('content')
    <div class="card" style="max-width:560px;">
        <h1>Schule manuell anlegen</h1>
        <p class="lead">Für Anfragen, die nicht über das Formular kamen. Alle Details werden danach im Konfigurator gepflegt.</p>

        @if ($errors->any())
            @foreach ($errors->all() as $error)
                <div class="alert error">{{ $error }}</div>
            @endforeach
        @endif

        <form method="post" action="{{ route('schools.store') }}">
            @csrf
            <label for="school_name">Name der Schule/Organisation</label>
            <input type="text" id="school_name" name="school_name" required maxlength="150" value="{{ old('school_name') }}">

            <label for="delivery_type">Lieferart</label>
            <select id="delivery_type" name="delivery_type" style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--line);border-radius:8px;font:inherit;margin-bottom:1rem;background:#fff;">
                @foreach (\App\Models\SchoolOnboarding::DELIVERY_TYPES as $key => $label)
                    <option value="{{ $key }}" {{ old('delivery_type', 'collective') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>

            <label for="contact_name">Kontaktperson <span class="hint">(optional)</span></label>
            <input type="text" id="contact_name" name="contact_name" maxlength="150" value="{{ old('contact_name') }}">

            <label for="contact_email">Kontakt-E-Mail <span class="hint">(optional)</span></label>
            <input type="text" id="contact_email" name="contact_email" maxlength="150" value="{{ old('contact_email') }}">

            <button class="btn" type="submit">Anlegen</button>
            <a class="btn secondary" href="{{ route('schools.index') }}" style="margin-left:0.5rem;">Abbrechen</a>
        </form>
    </div>
@endsection
