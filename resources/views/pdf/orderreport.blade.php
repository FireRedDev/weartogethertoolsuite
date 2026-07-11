<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20pt 18pt 26pt 18pt; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; margin: 0; }
    table { border-collapse: collapse; margin: 0 auto; width: auto; }
    th, td {
        border: 0.6pt solid #000;
        padding: 1.5pt 3pt;
        font-size: 8pt;
        text-align: center;
        white-space: nowrap;
    }
    th { background-color: #b1dce4; font-weight: bold; }
    td.idcol { background-color: #b1dce4; }
    tr.even td:not(.idcol) { background-color: #ffffff; }
    tr.odd td:not(.idcol) { background-color: #e4e4e4; }
    .pagebreak { page-break-after: always; }
    .footer { text-align: center; font-size: 8pt; margin-top: 10pt; }
    .footer-fixed {
        position: fixed;
        bottom: -22pt;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 8pt;
    }
</style>
</head>
<body>
@foreach ($pages as $pageIndex => $rows)
    <div @if ($pageIndex < $pageCount - 1) class="pagebreak" @endif>
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $rowIndex => $row)
                    <tr class="{{ $rowIndex % 2 === 0 ? 'even' : 'odd' }}">
                        @foreach ($columns as $column)
                            <td @if ($column === 'ID') class="idcol" @endif>{{ $row[$column] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="footer">Seite {{ $pageIndex + 1 }} von {{ $pageCount }}</div>
    </div>
@endforeach
</body>
</html>
