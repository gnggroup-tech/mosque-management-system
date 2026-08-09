<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExportReportRequest;
use App\Services\AuditLogger;
use App\Services\ReportExportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $reports,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'superadmin']), 403);

        return view('reports.index', [
            'types' => ReportExportService::TYPES,
            'mosques' => $this->reports->mosquesFor($request->user()),
        ]);
    }

    public function export(ExportReportRequest $request): Response
    {
        $data = $request->validated();
        $filters = $request->filters();
        $type = $data['type'];
        $format = $data['format'];

        // Resolve rows now so authorization is enforced before a streamed response starts.
        $rows = $this->reports->rows($type, $filters, $request->user());
        $filename = $this->reports->filename($type, $format, $filters, $request->user());

        $this->audit->log('report.export.requested', metadata: [
            'type' => $type,
            'format' => $format,
            'filters' => $filters,
        ]);

        if ($format === 'csv') {
            return new StreamedResponse(function () use ($type, $rows): void {
                $output = fopen('php://output', 'wb');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, $this->reports->headings($type), ';', '"', '\\', "\r\n");
                foreach ($rows as $row) {
                    fputcsv($output, $this->reports->csvRow($type, $row), ';', '"', '\\', "\r\n");
                }
                fclose($output);
            }, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $limit = max(1, (int) config('reports.pdf_max_rows', 2000));
        $candidateRows = LazyCollection::make(function () use ($rows) {
            yield from $rows;
        })->take($limit + 1)->collect();
        $truncated = $candidateRows->count() > $limit;
        $limitedRows = $candidateRows->take($limit);
        $html = view('reports.pdf', [
            'type' => $type,
            'rows' => $limitedRows,
            'headings' => $this->reports->headings($type),
            'totals' => $this->reports->totals($type, $filters, $request->user()),
            'filters' => $filters,
            'generatedAt' => now(),
            'truncated' => $truncated,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
