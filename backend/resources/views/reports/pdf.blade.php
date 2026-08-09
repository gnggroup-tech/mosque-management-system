<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1f2937; font-size: 10px; }
        h1 { color: #166534; margin-bottom: 4px; }
        .meta { color: #4b5563; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 5px; text-align: start; }
        th { background: #dcfce7; }
        .totals { margin: 16px 0; }
        .warning { color: #9a3412; }
    </style>
</head>
<body>
    <h1>SGAR — {{ __(Illuminate\Support\Str::headline($type)) }}</h1>
    <div class="meta">
        {{ __('Generated at') }}: {{ $generatedAt->format('Y-m-d H:i:s') }}<br>
        {{ __('Period') }}: {{ $filters['from'] ?? '—' }} — {{ $filters['to'] ?? '—' }}
    </div>

    <div class="totals">
        <strong>{{ __('Totals by currency') }}</strong>
        @forelse ($totals as $currency => $total)
            <div>
                {{ $currency }}:
                @if (is_array($total))
                    {{ __('income') }} {{ number_format($total['income'], 2, '.', ' ') }} /
                    {{ __('expense') }} {{ number_format($total['expense'], 2, '.', ' ') }} /
                    {{ __('Balance') }} {{ number_format($total['income'] - $total['expense'], 2, '.', ' ') }}
                @else
                    {{ number_format($total, 2, '.', ' ') }}
                @endif
            </div>
        @empty
            <div>{{ __('No data') }}</div>
        @endforelse
    </div>

    @if ($truncated)<p class="warning">{{ __('The PDF row limit was reached. Use CSV for the complete dataset.') }}</p>@endif

    <table>
        <thead><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach (app(App\Services\ReportExportService::class)->csvRow($type, $row) as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">{{ __('No data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
