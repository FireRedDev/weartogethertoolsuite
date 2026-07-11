@extends('layouts.app')

@section('title', 'Shop-Export hochladen — Wear Together Order Suite')

@section('content')
    <div class="steps">
        <span class="step active">1 · Hochladen</span>
        <span class="step">2 · Auftrag & Prüfung</span>
        <span class="step">3 · Dokumente</span>
    </div>

    <div class="card">
        <h1>Shop-Export hochladen</h1>
        <p class="lead">Lade den Bestell-Export aus dem Wear-Together-Shop hoch (.xlsx). Daraus entstehen die drei Excel-Reports (Lieferant, intern, Kunde) und das Verteil-PDF — exakt wie bisher.</p>

        @if ($errors->any())
            @foreach ($errors->all() as $error)
                <div class="alert error">{{ $error }}</div>
            @endforeach
        @endif

        <form method="post" action="{{ route('tool.upload') }}" enctype="multipart/form-data" id="uploadform">
            @csrf
            <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Datei auswählen oder hierher ziehen">
                <div><strong>Datei hierher ziehen</strong> oder klicken, um auszuwählen</div>
                <div class="hint">Nur .xlsx / .xltx, max. 20 MB</div>
                <div class="filename" id="filename"></div>
            </div>
            <input type="file" name="export" id="fileinput" accept=".xlsx,.xltx" style="display:none">
            <div style="margin-top:1rem;">
                <button class="btn" type="submit" id="uploadbtn" disabled>Weiter zur Prüfung</button>
            </div>
        </form>
    </div>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileinput = document.getElementById('fileinput');
        const filename = document.getElementById('filename');
        const uploadbtn = document.getElementById('uploadbtn');

        function setFile(files) {
            if (files.length > 0) {
                fileinput.files = files;
                filename.textContent = files[0].name;
                uploadbtn.disabled = false;
            }
        }
        dropzone.addEventListener('click', () => fileinput.click());
        dropzone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') fileinput.click(); });
        fileinput.addEventListener('change', () => setFile(fileinput.files));
        dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            setFile(e.dataTransfer.files);
        });
    </script>
@endsection
