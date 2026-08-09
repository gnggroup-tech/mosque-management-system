<?php

namespace App\Http\Requests\Reports;

use App\Services\ReportExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ReportExportService::TYPES)],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            // Existence is checked inside the authorized mosque scope to avoid leaking other mosque IDs.
            'mosque_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['pending', 'validated', 'rejected', 'active', 'inactive', 'disposed'])],
            'currency' => ['nullable', Rule::in(['GNF', 'USD', 'EUR'])],
        ];
    }

    public function filters(): array
    {
        return $this->safe()->only(['mosque_id', 'from', 'to', 'status', 'currency']);
    }
}
