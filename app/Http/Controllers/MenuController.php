<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $branchId = $user->resolveBranchId();

        abort_unless($branchId, 403, 'User is not linked to any branch.');

        $categories = MenuCategory::where('branch_id', $branchId)
            ->withCount('menuItems')
            ->orderBy('sort_order')
            ->get();

        $activeCategoryId = $request->integer('category')
            ?: ($categories->first()?->id ?? 0);

        $items = MenuItem::where('branch_id', $branchId)
            ->when($activeCategoryId, fn ($q) => $q->where('category_id', $activeCategoryId))
            ->orderBy('name')
            ->get();

        return view('modules.menu.index', [
            'categories' => $categories,
            'items' => $items,
            'activeCategoryId' => $activeCategoryId,
            'branchId' => $branchId,
            'branchName' => Branch::find($branchId)?->name,
        ]);
    }

    // ── Categories ────────────────────────────────────────────

    public function storeCategory(Request $request): RedirectResponse
    {
        $branchId = $this->ownBranchId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);
        $this->assertNameFree('menu_categories', $branchId, $validated['name'], null, 'category');

        $maxSort = MenuCategory::where('branch_id', $branchId)->max('sort_order') ?? 0;

        MenuCategory::create([
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        return redirect()->route('menu.index')
            ->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, MenuCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertNameFree('menu_categories', (int) $category->branch_id, $validated['name'], (int) $category->id, 'category');

        $category->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'sort_order' => $validated['sort_order'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('menu.index', ['category' => $category->id])
            ->with('success', 'Category updated.');
    }

    public function destroyCategory(MenuCategory $category): RedirectResponse
    {
        $itemCount = $category->menuItems()->count();

        if ($itemCount > 0) {
            return back()->with('error', "Cannot delete \"{$category->name}\" — it has {$itemCount} item(s). Move or delete them first.");
        }

        $category->delete();

        return redirect()->route('menu.index')
            ->with('success', 'Category deleted.');
    }

    // ── Items ─────────────────────────────────────────────────

    public function storeItem(Request $request): RedirectResponse
    {
        $branchId = $this->ownBranchId($request);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', $this->categoryOnBranch($branchId)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
        ]);
        $this->assertNameFree('menu_items', $branchId, $validated['name'], null, 'item');

        $sku = 'MI' . random_int(1000, 9999);
        while (MenuItem::where('branch_id', $branchId)->where('sku', $sku)->exists()) {
            $sku = 'MI' . random_int(1000, 9999);
        }

        MenuItem::create([
            'branch_id' => $branchId,
            'category_id' => $validated['category_id'],
            'sku' => $sku,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'],
            'base_price' => $validated['base_price'],
            'tax_rate' => 0,
            'is_active' => true,
        ]);

        return redirect()->route('menu.index', ['category' => $validated['category_id']])
            ->with('success', 'Item created.');
    }

    public function updateItem(Request $request, MenuItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', $this->categoryOnBranch((int) $item->branch_id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->assertNameFree('menu_items', (int) $item->branch_id, $validated['name'], (int) $item->id, 'item');

        $item->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'],
            'base_price' => $validated['base_price'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('menu.index', ['category' => $validated['category_id']])
            ->with('success', 'Item updated.');
    }

    public function destroyItem(MenuItem $item): RedirectResponse
    {
        $categoryId = $item->category_id;
        $item->delete();

        return redirect()->route('menu.index', ['category' => $categoryId])
            ->with('success', 'Item deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────

    /** The menu being edited is the owner's own branch's; there is none without one. */
    private function ownBranchId(Request $request): int
    {
        $branchId = $request->user()->resolveBranchId();
        abort_unless($branchId, 403, 'User is not linked to any branch.');

        return (int) $branchId;
    }

    /**
     * An item's category must be on the item's own branch. The POS lists items by
     * category, so an item filed under another branch's category shows up on that
     * branch's iPad.
     */
    private function categoryOnBranch(int $branchId): Exists
    {
        return Rule::exists('menu_categories', 'id')->where('branch_id', $branchId);
    }

    /**
     * Names are unique per branch by slug (unique(branch_id, slug)). Check first, so
     * a repeated name is a message on the form instead of a database error.
     */
    private function assertNameFree(string $table, int $branchId, string $name, ?int $ignoreId, string $label): void
    {
        $taken = DB::table($table)
            ->where('branch_id', $branchId)
            ->where('slug', Str::slug($name))
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This branch's menu already has a {$label} called \"{$name}\".",
            ]);
        }
    }
}
