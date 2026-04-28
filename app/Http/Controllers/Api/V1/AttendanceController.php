<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    public function clockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        try {
            $record = $this->attendanceService->clockIn(
                $employee,
                $validated['work_date'],
                $request->user(),
                $validated['notes'] ?? null
            );
        } catch (AttendanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AttendanceRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'record_id' => ['required', 'integer', 'exists:attendance_records,id'],
        ]);

        $record = AttendanceRecord::findOrFail($validated['record_id']);

        try {
            $record = $this->attendanceService->clockOut($record);
        } catch (AttendanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AttendanceRecordResource($record))->response();
    }

    public function today(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $employee = $user->employee;

        abort_unless($employee, 403, 'User is not linked to any branch.');

        $records = AttendanceRecord::where('branch_id', $employee->branch_id)
            ->whereDate('work_date', now()->toDateString())
            ->with('employee')
            ->orderByDesc('clock_in_at')
            ->get();

        return AttendanceRecordResource::collection($records);
    }
}
