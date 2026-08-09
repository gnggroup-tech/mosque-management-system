<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Mosque;
use App\Models\Subsidy;
use App\Models\User;
use App\Models\WaqfAsset;
use App\Models\WaqfExpense;
use App\Models\WaqfRevenue;
use App\Models\ZakatCollection;
use App\Models\ZakatDistribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReportExportService
{
    public const TYPES = [
        'donations', 'zakat_collections', 'zakat_distributions', 'waqf_assets',
        'waqf_revenues', 'waqf_expenses', 'subsidies', 'expenses', 'consolidated',
    ];

    private const DEFINITIONS = [
        'donations' => [Donation::class, 'received_at', 'amount', null],
        'zakat_collections' => [ZakatCollection::class, 'collected_at', 'amount', null],
        'zakat_distributions' => [ZakatDistribution::class, 'distributed_at', 'amount', null],
        'waqf_assets' => [WaqfAsset::class, 'dedicated_at', 'estimated_value', null],
        'waqf_revenues' => [WaqfRevenue::class, 'received_at', 'amount', 'asset'],
        'waqf_expenses' => [WaqfExpense::class, 'spent_at', 'amount', 'asset'],
        'subsidies' => [Subsidy::class, 'received_at', 'amount', null],
        'expenses' => [Expense::class, 'spent_at', 'amount', null],
    ];

    public function mosquesFor(User $user): Collection
    {
        return Mosque::query()
            ->when(! $user->hasRole('superadmin'), fn (Builder $query) => $query->where('admin_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    public function rows(string $type, array $filters, User $user): iterable
    {
        if ($type === 'consolidated') {
            return $this->consolidatedRows($filters, $user);
        }

        return $this->query($type, $filters, $user)
            ->with($this->relations($type))
            ->lazyById(500)
            ->map(fn ($record) => $this->mapRecord($type, $record));
    }

    public function totals(string $type, array $filters, User $user): Collection
    {
        if ($type === 'consolidated') {
            return $this->consolidatedRows($filters, $user)
                ->groupBy('currency')
                ->map(fn (Collection $rows) => [
                    'income' => $rows->where('direction', 'income')->sum('amount'),
                    'expense' => $rows->where('direction', 'expense')->sum('amount'),
                ]);
        }

        [, , $amountColumn] = self::DEFINITIONS[$type];

        return $this->query($type, $filters, $user)
            ->selectRaw("currency, COALESCE(SUM({$amountColumn}), 0) as total")
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($amount) => (float) $amount);
    }

    public function headings(string $type): array
    {
        $keys = $type === 'consolidated'
            ? ['Report type', 'Direction', 'Currency', 'Amount']
            : ['Mosque', 'Reference', 'Category', 'Date', 'Status', 'Currency', 'Amount'];

        return array_map(fn (string $key) => __($key), $keys);
    }

    public function csvRow(string $type, array $row): array
    {
        $values = $type === 'consolidated'
            ? [$row['label'], __($row['direction']), $row['currency'], $row['amount']]
            : [$row['mosque'], $row['reference'], $row['category'], $row['date'], __($row['status']), $row['currency'], $row['amount']];

        return array_map($this->escapeCsvCell(...), $values);
    }

    public function filename(string $type, string $format, array $filters, User $user): string
    {
        $mosque = isset($filters['mosque_id'])
            ? Mosque::query()->find($filters['mosque_id'])
            : null;
        $scope = $mosque ? Str::slug($mosque->code ?: $mosque->name) : ($user->hasRole('superadmin') ? 'all-mosques' : 'my-mosques');

        return sprintf('sgar-%s-%s-%s.%s', Str::slug($type), $scope, now()->format('Ymd-His'), $format);
    }

    private function query(string $type, array $filters, User $user): Builder
    {
        [$model, $dateColumn, , $through] = self::DEFINITIONS[$type];
        $mosqueIds = $this->authorizedMosqueIds($user, $filters['mosque_id'] ?? null);
        $query = $model::query();

        if ($through === 'asset') {
            $query->whereHas('asset', fn (Builder $assets) => $assets->whereIn('mosque_id', $mosqueIds));
        } else {
            $query->whereIn('mosque_id', $mosqueIds);
        }

        return $query
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate($dateColumn, '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate($dateColumn, '<=', $to))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['currency'] ?? null, fn (Builder $q, string $currency) => $q->where('currency', $currency));
    }

    private function authorizedMosqueIds(User $user, ?int $requestedMosqueId): Collection
    {
        abort_unless($user->hasAnyRole(['admin', 'superadmin']), 403);

        $query = Mosque::query()->when(
            ! $user->hasRole('superadmin'),
            fn (Builder $mosques) => $mosques->where('admin_id', $user->id)
        );

        if ($requestedMosqueId !== null) {
            abort_unless((clone $query)->whereKey($requestedMosqueId)->exists(), 403);

            return collect([$requestedMosqueId]);
        }

        return $query->pluck('id');
    }

    private function relations(string $type): array
    {
        return in_array($type, ['waqf_revenues', 'waqf_expenses'], true)
            ? ['asset:id,mosque_id,name', 'asset.mosque:id,name']
            : ['mosque:id,name'];
    }

    private function mapRecord(string $type, object $record): array
    {
        [, $dateColumn, $amountColumn, $through] = self::DEFINITIONS[$type];
        $reference = $record->receipt_number ?? $record->reference_number ?? $record->registration_number ?? (string) $record->id;
        $category = $record->contribution_type ?? $record->category ?? $record->type ?? $record->source ?? '';
        $date = $record->{$dateColumn};

        return [
            'mosque' => $through === 'asset' ? $record->asset->mosque->name : $record->mosque->name,
            'reference' => $reference,
            'category' => $category,
            'date' => $date?->format('Y-m-d') ?? (string) $date,
            'status' => $record->status,
            'currency' => $record->currency,
            'amount' => (float) ($record->{$amountColumn} ?? 0),
        ];
    }

    private function consolidatedRows(array $filters, User $user): Collection
    {
        $directions = [
            'donations' => 'income', 'zakat_collections' => 'income', 'zakat_distributions' => 'expense',
            'waqf_revenues' => 'income', 'waqf_expenses' => 'expense', 'subsidies' => 'income', 'expenses' => 'expense',
        ];

        return collect($directions)->flatMap(function (string $direction, string $type) use ($filters, $user) {
            [, , $amountColumn] = self::DEFINITIONS[$type];

            return $this->query($type, $filters, $user)
                ->selectRaw("currency, COALESCE(SUM({$amountColumn}), 0) as total")
                ->groupBy('currency')
                ->get()
                ->map(fn ($total) => [
                    'type' => $type,
                    'label' => __(Str::headline($type)),
                    'direction' => $direction,
                    'currency' => $total->currency,
                    'amount' => (float) $total->total,
                ]);
        })->values();
    }

    private function escapeCsvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/u', ltrim($value)) ? "'".$value : $value;
    }
}
