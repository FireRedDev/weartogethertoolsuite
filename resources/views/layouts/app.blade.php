<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Wear Together Order Suite')</title>
    <style>
        :root {
            --accent: #ffbb00;
            --accent-dark: #e0a500;
            --ink: #1d2733;
            --muted: #64748b;
            --bg: #f6f7f9;
            --card: #ffffff;
            --line: #e2e8f0;
            --ok: #16803c;
            --warn: #b45309;
            --error: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.5;
        }
        header.site {
            background: var(--ink);
            color: #fff;
            padding: 0.9rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        header.site .brand { font-weight: 700; font-size: 1.05rem; letter-spacing: 0.01em; }
        header.site .brand .dot { color: var(--accent); }
        main { max-width: 1080px; margin: 2rem auto 4rem; padding: 0 1.25rem; }
        .steps { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .step {
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            background: var(--card);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.85rem;
        }
        .step.active { background: var(--accent); border-color: var(--accent); color: var(--ink); font-weight: 600; }
        .step.done { color: var(--ok); border-color: var(--ok); }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        h1 { font-size: 1.35rem; margin: 0 0 0.5rem; }
        h2 { font-size: 1.05rem; margin: 0 0 0.75rem; }
        p.lead { color: var(--muted); margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
        .hint { font-weight: 400; color: var(--muted); font-size: 0.85rem; }
        input[type=text], input[type=password], textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--line);
            border-radius: 8px;
            font: inherit;
            margin-bottom: 1rem;
            background: #fff;
        }
        input:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--ink);
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 0.65rem 1.4rem;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: var(--accent-dark); }
        .btn.secondary { background: var(--card); border: 1px solid var(--line); font-weight: 600; }
        .btn.secondary:hover { background: var(--bg); }
        .dropzone {
            border: 2px dashed var(--line);
            border-radius: 12px;
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--muted);
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .dropzone.dragover { border-color: var(--accent); background: #fffaeb; }
        .dropzone strong { color: var(--ink); }
        .dropzone .filename { color: var(--ok); font-weight: 600; margin-top: 0.5rem; }
        .alert { border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.95rem; }
        .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: var(--error); }
        .alert.warn { background: #fffbeb; border: 1px solid #fde68a; color: var(--warn); }
        .alert.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--ok); }
        details.warnrows { margin-top: 0.4rem; font-size: 0.85rem; }
        details.warnrows summary { cursor: pointer; }
        .stats { display: flex; gap: 1rem; flex-wrap: wrap; margin: 0.5rem 0 1rem; }
        .stat {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            min-width: 130px;
        }
        .stat .value { font-size: 1.4rem; font-weight: 700; }
        .stat .label { color: var(--muted); font-size: 0.8rem; }
        .downloads { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.9rem; }
        .dl {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 1rem;
            background: var(--card);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .dl .name { font-weight: 600; font-size: 0.95rem; }
        .dl .desc { color: var(--muted); font-size: 0.82rem; flex: 1; }
        .tablewrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
        table.data { border-collapse: collapse; width: 100%; font-size: 0.82rem; }
        table.data th, table.data td { border-bottom: 1px solid var(--line); padding: 0.4rem 0.6rem; text-align: left; white-space: nowrap; }
        table.data th { background: #eef2f7; position: sticky; top: 0; }
        table.data tr:nth-child(even) td { background: #fafbfc; }
        .tabs { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; }
        .tab {
            border: 1px solid var(--line);
            background: var(--card);
            border-radius: 8px;
            padding: 0.4rem 1rem;
            cursor: pointer;
            font: inherit;
            font-size: 0.9rem;
        }
        .tab.active { background: var(--ink); color: #fff; border-color: var(--ink); }
        .searchbox { margin-bottom: 0.75rem; }
        .searchbox input { margin-bottom: 0; max-width: 320px; }
        footer.site { text-align: center; color: var(--muted); font-size: 0.8rem; padding: 2rem 0 3rem; }
    </style>
</head>
<body>
<header class="site">
    <a href="{{ route('home') }}" class="brand" style="color:#fff;text-decoration:none;">Wear Together <span class="dot">●</span> Order Suite</a>
    <nav style="display:flex;gap:0.6rem;align-items:center;">
        @php($isTool = request()->routeIs('tool.*', 'shop.*', 'job.*'))
        @php($isSchools = request()->routeIs('schools.*'))
        <a href="{{ route('home') }}" style="color:{{ request()->routeIs('home') ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Startseite</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('tool.index') }}" style="color:{{ $isTool ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Auftragsdokumente</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('schools.index') }}" style="color:{{ $isSchools ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Schul-Onboarding</a>
        @if (config('ordersuite.password') !== '' && session('tool_authenticated'))
            <form method="post" action="{{ route('logout') }}" style="margin-left:0.75rem;">
                @csrf
                <button class="btn secondary" type="submit" style="padding:0.35rem 0.9rem;font-size:0.85rem;">Abmelden</button>
            </form>
        @endif
    </nav>
</header>
<main>
    @yield('content')
</main>
<footer class="site">Wear Together Order Suite — Nachfolger der Wear Together Toolsuite</footer>
</body>
</html>
