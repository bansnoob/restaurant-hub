<x-app-layout>

<div class="rh-menu-page" x-data="menuPage()">

    {{-- ── Flash messages ── --}}
    @if(session('success'))
        <div class="rm-toast rm-toast--ok" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition.opacity>
            <svg viewBox="0 0 20 20" fill="currentColor" class="rm-toast-icon"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rm-toast rm-toast--err" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)" x-transition.opacity>
            <svg viewBox="0 0 20 20" fill="currentColor" class="rm-toast-icon"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Top bar ── --}}
    <div class="rm-topbar">
        <div>
            <h1 class="rm-page-title">Menu</h1>
            <p class="rm-page-sub">{{ $categories->sum('menu_items_count') }} items across {{ $categories->count() }} categories</p>
        </div>
        <div class="rm-topbar-actions">
            <button type="button" class="rm-btn rm-btn--ghost" @click="openCategoryForm()">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 3v10M3 8h10"/></svg>
                Category
            </button>
            <button type="button" class="rm-btn rm-btn--primary" @click="openItemForm()">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 3v10M3 8h10"/></svg>
                New Item
            </button>
        </div>
    </div>

    {{-- ── Category tabs ── --}}
    <div class="rm-cat-strip">
        <div class="rm-cat-track">
            @foreach($categories as $cat)
                <a href="{{ route('menu.index', ['category' => $cat->id]) }}"
                   class="rm-cat-pill {{ $cat->id === $activeCategoryId ? 'rm-cat-pill--on' : '' }} {{ !$cat->is_active ? 'rm-cat-pill--dim' : '' }}">
                    <span class="rm-cat-pill-name">{{ $cat->name }}</span>
                    <span class="rm-cat-pill-count">{{ $cat->menu_items_count }}</span>
                </a>
            @endforeach
            @if($categories->isEmpty())
                <span class="rm-cat-empty">No categories yet &mdash; create one to get started.</span>
            @endif
        </div>
    </div>

    {{-- ── Item table ── --}}
    <div class="rm-table-wrap">
        <table class="rm-table">
            <thead>
                <tr>
                    <th class="rm-th">Item</th>
                    <th class="rm-th rm-th--right">Price</th>
                    <th class="rm-th rm-th--center">Status</th>
                    <th class="rm-th rm-th--right">SKU</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr class="rm-row {{ !$item->is_active ? 'rm-row--off' : '' }}" @click="openItemForm({{ $item->toJson() }})">
                        <td class="rm-td">
                            <span class="rm-item-name">{{ $item->name }}</span>
                            @if($item->description)
                                <span class="rm-item-desc">{{ Str::limit($item->description, 50) }}</span>
                            @endif
                        </td>
                        <td class="rm-td rm-td--right">
                            <span class="rm-item-price">₱{{ number_format((float) $item->base_price, 2) }}</span>
                        </td>
                        <td class="rm-td rm-td--center">
                            <span class="rm-status-dot {{ $item->is_active ? 'rm-status-dot--on' : 'rm-status-dot--off' }}"></span>
                            <span class="rm-status-label">{{ $item->is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="rm-td rm-td--right">
                            <span class="rm-item-sku">{{ $item->sku }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="rm-td rm-td--empty">
                            @if($categories->isEmpty())
                                Create a category first, then add items.
                            @else
                                No items in this category yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Active category edit bar ── --}}
    @if($activeCategoryId && $categories->isNotEmpty())
        @php $activeCat = $categories->firstWhere('id', $activeCategoryId); @endphp
        @if($activeCat)
            <div class="rm-cat-bar">
                <span class="rm-cat-bar-label">Category:</span>
                <span class="rm-cat-bar-name">{{ $activeCat->name }}</span>
                <button type="button" class="rm-cat-bar-btn" @click="openCategoryForm({{ $activeCat->toJson() }})">Edit</button>
                <form method="POST" action="{{ route('menu.categories.destroy', $activeCat) }}" class="inline"
                      onsubmit="return confirm('Delete category &quot;{{ $activeCat->name }}&quot;? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="rm-cat-bar-btn rm-cat-bar-btn--danger">Delete</button>
                </form>
            </div>
        @endif
    @endif

    {{-- ── Slide-over: Category form ── --}}
    <template x-teleport="body">
        <div x-show="showCatForm" x-transition:enter="rm-slide-enter" x-transition:leave="rm-slide-leave" class="rm-overlay" @click.self="showCatForm = false" style="display:none">
            <div class="rm-drawer" @click.stop>
                <div class="rm-drawer-head">
                    <h2 class="rm-drawer-title" x-text="editCat ? 'Edit Category' : 'New Category'"></h2>
                    <button type="button" class="rm-drawer-close" @click="showCatForm = false">&times;</button>
                </div>
                <form :action="editCat ? '{{ url('menu/categories') }}/' + editCat.id : '{{ route('menu.categories.store') }}'" method="POST" class="rm-drawer-body">
                    @csrf
                    <template x-if="editCat"><input type="hidden" name="_method" value="PUT"></template>

                    <label class="rm-field">
                        <span class="rm-field-label">Name</span>
                        <input type="text" name="name" class="rm-input" required maxlength="100" :value="editCat?.name ?? ''" autocomplete="off">
                    </label>

                    <template x-if="editCat">
                        <label class="rm-field">
                            <span class="rm-field-label">Sort Order</span>
                            <input type="number" name="sort_order" class="rm-input" min="0" :value="editCat?.sort_order ?? 0">
                        </label>
                    </template>

                    <template x-if="editCat">
                        <label class="rm-toggle-wrap">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="rm-toggle" :checked="editCat?.is_active">
                            <span class="rm-toggle-label">Active</span>
                        </label>
                    </template>

                    <div class="rm-drawer-foot">
                        <button type="button" class="rm-btn rm-btn--ghost" @click="showCatForm = false">Cancel</button>
                        <button type="submit" class="rm-btn rm-btn--primary" x-text="editCat ? 'Save Changes' : 'Create Category'"></button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- ── Slide-over: Item form ── --}}
    <template x-teleport="body">
        <div x-show="showItemForm" x-transition:enter="rm-slide-enter" x-transition:leave="rm-slide-leave" class="rm-overlay" @click.self="showItemForm = false" style="display:none">
            <div class="rm-drawer rm-drawer--wide" @click.stop>
                <div class="rm-drawer-head">
                    <h2 class="rm-drawer-title" x-text="editItem ? 'Edit Item' : 'New Item'"></h2>
                    <button type="button" class="rm-drawer-close" @click="showItemForm = false">&times;</button>
                </div>
                <form :action="editItem ? '{{ url('menu/items') }}/' + editItem.id : '{{ route('menu.items.store') }}'" method="POST" class="rm-drawer-body">
                    @csrf
                    <template x-if="editItem"><input type="hidden" name="_method" value="PUT"></template>

                    <label class="rm-field">
                        <span class="rm-field-label">Category</span>
                        <select name="category_id" class="rm-input" required>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}"
                                    :selected="(editItem?.category_id ?? {{ $activeCategoryId }}) == {{ $cat->id }}">
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="rm-field">
                        <span class="rm-field-label">Item Name</span>
                        <input type="text" name="name" class="rm-input" required maxlength="150" :value="editItem?.name ?? ''" autocomplete="off">
                    </label>

                    <label class="rm-field">
                        <span class="rm-field-label">Description <span class="rm-field-opt">(optional)</span></span>
                        <textarea name="description" class="rm-input rm-textarea" maxlength="500" x-text="editItem?.description ?? ''"></textarea>
                    </label>

                    <label class="rm-field">
                        <span class="rm-field-label">Price</span>
                        <input type="number" name="base_price" class="rm-input" step="0.01" min="0" required :value="editItem?.base_price ?? ''">
                    </label>

                    <template x-if="editItem">
                        <label class="rm-toggle-wrap">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="rm-toggle" :checked="editItem?.is_active">
                            <span class="rm-toggle-label">Active</span>
                        </label>
                    </template>

                    <div class="rm-drawer-foot">
                        <template x-if="editItem">
                            <button type="button" class="rm-btn rm-btn--danger" @click="deleteItem()">Delete Item</button>
                        </template>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="showItemForm = false">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" x-text="editItem ? 'Save Changes' : 'Create Item'"></button>
                        </div>
                    </div>
                </form>

                {{-- Hidden delete form --}}
                <template x-if="editItem">
                    <form :action="'{{ url('menu/items') }}/' + editItem?.id" method="POST" x-ref="deleteItemForm" style="display:none">
                        @csrf @method('DELETE')
                    </form>
                </template>
            </div>
        </div>
    </template>

</div>

<script>
function menuPage() {
    return {
        showCatForm: false,
        showItemForm: false,
        editCat: null,
        editItem: null,

        openCategoryForm(cat = null) {
            this.editCat = cat;
            this.showCatForm = true;
        },

        openItemForm(item = null) {
            this.editItem = item;
            this.showItemForm = true;
        },

        deleteItem() {
            if (confirm('Delete this item? This cannot be undone.')) {
                this.$refs.deleteItemForm.submit();
            }
        }
    }
}
</script>

</x-app-layout>
