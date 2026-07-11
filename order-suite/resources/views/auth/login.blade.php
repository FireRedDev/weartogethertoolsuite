@extends('layouts.app')

@section('title', 'Anmelden — Wear Together Order Suite')

@section('content')
    <div class="card" style="max-width:420px;margin:3rem auto;">
        <h1>Anmelden</h1>
        <p class="lead">Bitte das Team-Passwort eingeben.</p>

        @if ($errors->any())
            <div class="alert error">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('login.attempt') }}">
            @csrf
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required autofocus>
            <button class="btn" type="submit">Anmelden</button>
        </form>
    </div>
@endsection
