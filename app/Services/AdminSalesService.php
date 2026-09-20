<?php

namespace App\Services;

use App\Models\AdminSale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminSalesService
{
    /** Total revenue for a given day. */
    public function getDailyRevenue(string $date): array
    {
        $result = AdminSale::whereDate('sale_date', $date)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count, currency_symbol')
            ->groupBy('currency_symbol')
            ->first();

        return [
            'date'     => $date,
            'total'    => $result ? (float) $result->total : 0,
            'count'    => $result ? (int) $result->count : 0,
            'currency' => $result?->currency_symbol ?? '₹',
        ];
    }

    /** Monthly revenue for a given year/month. */
    public function getMonthlyRevenue(int $year, int $month): array
    {
        $result = AdminSale::whereYear('sale_date', $year)
            ->whereMonth('sale_date', $month)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count, currency_symbol')
            ->groupBy('currency_symbol')
            ->first();

        return [
            'year'     => $year,
            'month'    => $month,
            'total'    => $result ? (float) $result->total : 0,
            'count'    => $result ? (int) $result->count : 0,
            'currency' => $result?->currency_symbol ?? '₹',
        ];
    }

    /** Last N months revenue chart data (for bar chart). */
    public function getMonthlyChart(int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $rows = AdminSale::where('sale_date', '>=', $start)
            ->selectRaw('YEAR(sale_date) as y, MONTH(sale_date) as m, SUM(amount) as total, COUNT(*) as count')
            ->groupByRaw('YEAR(sale_date), MONTH(sale_date)')
            ->orderByRaw('YEAR(sale_date), MONTH(sale_date)')
            ->get()
            ->keyBy(fn ($r) => "{$r->y}-{$r->m}");

        $chart = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $dt   = now()->startOfMonth()->subMonths($i);
            $key  = $dt->year . '-' . $dt->month;
            $row  = $rows->get($key);
            $chart[] = [
                'label'  => $dt->format('M Y'),
                'month'  => $dt->month,
                'year'   => $dt->year,
                'total'  => $row ? (float) $row->total : 0,
                'count'  => $row ? (int) $row->count : 0,
            ];
        }

        return $chart;
    }

    /** Last N days revenue chart data (for line chart). */
    public function getDailyChart(int $days = 30): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = AdminSale::where('sale_date', '>=', $start->toDateString())
            ->selectRaw('sale_date, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $chart = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dt  = now()->subDays($i)->toDateString();
            $row = $rows->get($dt);
            $chart[] = [
                'date'  => $dt,
                'label' => Carbon::parse($dt)->format('d M'),
                'total' => $row ? (float) $row->total : 0,
                'count' => $row ? (int) $row->count : 0,
            ];
        }

        return $chart;
    }

    /** Summary overview: today + this month + all time. */
    public function getOverview(): array
    {
        $today   = $this->getDailyRevenue(now()->toDateString());
        $monthly = $this->getMonthlyRevenue(now()->year, now()->month);
        $allTime = AdminSale::selectRaw('SUM(amount) as total, COUNT(*) as count')->first();

        return [
            'today'    => $today,
            'month'    => $monthly,
            'all_time' => [
                'total' => $allTime ? (float) $allTime->total : 0,
                'count' => $allTime ? (int) $allTime->count : 0,
            ],
        ];
    }

    /** Paginated sales records for the Accounts table. */
    public function getSalesTable(int $page = 1, int $perPage = 20, ?string $from = null, ?string $to = null): array
    {
        $query = AdminSale::with(['user:id,name,email', 'validityPlan:id,name,months'])
            ->orderBy('created_at', 'desc');

        if ($from) $query->where('sale_date', '>=', $from);
        if ($to)   $query->where('sale_date', '<=', $to);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }
}
