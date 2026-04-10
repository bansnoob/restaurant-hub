<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = $request->string('date_from')->toString() ?: now()->startOfMonth()->toDateString();
        $dateTo = $request->string('date_to')->toString() ?: now()->toDateString();

        $salesQuery = Sale::whereDate('sale_datetime', '>=', $dateFrom)
            ->whereDate('sale_datetime', '<=', $dateTo)
            ->orderByDesc('sale_datetime');

        $sales = (clone $salesQuery)->paginate(20)->withQueryString();

        $summary = [
            'orders' => (clone $salesQuery)->count(),
            'gross_sales' => (float) (clone $salesQuery)->sum('grand_total'),
            'discounts' => (float) (clone $salesQuery)->sum('discount_total'),
            'taxes' => (float) (clone $salesQuery)->sum('tax_total'),
        ];

        return view('modules.sales.index', compact('sales', 'summary', 'dateFrom', 'dateTo'));
    }
}
