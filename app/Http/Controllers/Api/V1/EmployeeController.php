<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $branchId = $user->resolveBranchId();

        if ($user->hasRole('owner') && $request->has('branch_id')) {
            $branchId = (int) $request->input('branch_id');
        }

        abort_unless($branchId, 403, 'User is not linked to any branch.');

        $employees = Employee::where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return EmployeeResource::collection($employees);
    }
}
