<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MenuCategoryResource;
use App\Http\Resources\V1\MenuItemResource;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MenuController extends Controller
{
    public function categories(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $categories = MenuCategory::where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['menuItems' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('sort_order')
            ->get();

        return MenuCategoryResource::collection($categories);
    }

    public function items(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $query = MenuItem::where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('category')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return MenuItemResource::collection($query->get());
    }

    private function resolveBranchId(Request $request): int
    {
        $user = $request->user();

        if ($user->hasRole('owner') && $request->filled('branch_id')) {
            return $request->integer('branch_id');
        }

        $employee = $user->employee;
        abort_unless($employee, 403, 'User is not linked to any branch.');

        return (int) $employee->branch_id;
    }
}
