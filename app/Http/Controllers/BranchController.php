<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\IngredientCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    /**
     * branches.code is a VARCHAR(20) and the prefix of every order number
     * (SaleService::generateOrderNumber), so it is held to the column width and
     * to letters, digits, dashes and underscores.
     */
    private const CODE_MAX_LENGTH = 20;

    /**
     * Anything a branch has done or been given keeps it alive: real records are
     * restrict-on-delete in the schema, so deleting through them was a raw 500.
     * Menu items and ingredients are refused too, as an owner's work. Say what is
     * there and point at deactivation, which hides a branch without losing anything.
     */
    private const RECORDS_THAT_BLOCK_DELETE = [
        'employees' => 'employee',
        'users' => 'user',
        'sales' => 'sale',
        'expenses' => 'expense',
        'special_expenses' => 'special expense',
        'day_closures' => 'closed day',
        'attendance_records' => 'attendance record',
        'payroll_periods' => 'payroll period',
        'stock_counts' => 'stock count',
        'inventory_movements' => 'stock movement',
        'gcash_adjustments' => 'GCash adjustment',
        'menu_items' => 'menu item',
        'ingredients' => 'ingredient',
    ];

    public function index(): View
    {
        $branches = Branch::orderBy('name')->get();
        $myBranchId = Auth::user()?->resolveBranchId();

        return view('modules.branches.index', compact('branches', 'myBranchId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:'.self::CODE_MAX_LENGTH, 'alpha_dash:ascii', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'assign_to_me' => ['nullable', 'boolean'],
        ]);

        $branch = Branch::create([
            'code' => Str::lower($validated['code']),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        // A new branch starts with the default inventory walk, so its first
        // stock count follows the shelves instead of the alphabet. The seed
        // migration only ever ran for the branches that existed when categories
        // shipped; without this call every later branch would start with an
        // empty category list and no way to get the defaults back.
        IngredientCategory::seedDefaultsFor((int) $branch->id);

        if (! empty($validated['assign_to_me'])) {
            $user = $request->user();
            $user->branch_id = $branch->id;
            $user->save();
        }

        return redirect()->route('branches.index')->with('success', "Branch \"{$branch->name}\" created.");
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:'.self::CODE_MAX_LENGTH, 'alpha_dash:ascii', Rule::unique('branches', 'code')->ignore($branch->id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branch->update([
            'code' => Str::lower($validated['code']),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('branches.index')->with('success', "Branch \"{$branch->name}\" updated.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $blocking = collect(self::RECORDS_THAT_BLOCK_DELETE)
            ->map(fn (string $label, string $table) => [
                'label' => $label,
                'count' => DB::table($table)->where('branch_id', $branch->id)->count(),
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->map(fn (array $row) => $row['count'].' '.Str::plural($row['label'], $row['count']));

        if ($blocking->isNotEmpty()) {
            return redirect()->route('branches.index')->with(
                'error',
                "Cannot delete \"{$branch->name}\": it has ".$blocking->join(', ', ' and ').'. Deactivate it instead to hide it everywhere.'
            );
        }

        // An empty branch takes its own setup with it. These categories are
        // null-on-delete, so left behind they would become SHARED ones that show
        // in every other branch's count walk and menu. (Its payroll rule, GCash
        // wallet and shifts cascade.)
        $name = $branch->name;
        DB::transaction(function () use ($branch): void {
            IngredientCategory::where('branch_id', $branch->id)->delete();
            DB::table('menu_categories')->where('branch_id', $branch->id)->delete();
            $branch->delete();
        });

        return redirect()->route('branches.index')->with('success', "Branch \"{$name}\" deleted.");
    }

    public function assignToMe(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        $user->branch_id = $branch->id;
        $user->save();

        return redirect()->route('branches.index')->with('success', "You are now linked to \"{$branch->name}\".");
    }
}
