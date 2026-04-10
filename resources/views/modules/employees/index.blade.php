<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Employees</h2>
    </x-slot>

    <div class="py-8" x-data="employeeEditor({ updateUrlTemplate: @js(route('employees.update', ['employee' => '__EMPLOYEE__'])) })">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">{{ $errors->first() }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Add Employee</h3>
                <form method="POST" action="{{ route('employees.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @csrf
                    <div>
                        <label class="text-sm text-gray-600">Branch</label>
                        <select name="branch_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Employee Code (optional)</label>
                        <input type="text" name="employee_code" class="w-full border rounded px-3 py-2" placeholder="Auto-generated if empty">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Hire Date</label>
                        <input type="date" name="hire_date" value="{{ now()->toDateString() }}" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">First Name</label>
                        <input type="text" name="first_name" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Last Name</label>
                        <input type="text" name="last_name" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Email</label>
                        <input type="email" name="email" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Phone</label>
                        <input type="text" name="phone" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Employment Type</label>
                        <select name="employment_type" class="w-full border rounded px-3 py-2" required>
                            <option value="full_time">Full Time</option>
                            <option value="part_time">Part Time</option>
                            <option value="contract">Contract</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Daily Rate</label>
                        <input type="number" step="0.01" min="0" name="daily_rate" class="w-full border rounded px-3 py-2">
                    </div>
                    <div class="flex items-center gap-2 mt-7">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <label class="text-sm text-gray-600">Active</label>
                    </div>
                    <div class="md:col-span-3">
                        <button class="px-4 py-2 bg-gray-800 text-white rounded">Add Employee</button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Employee List</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Code</th>
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Branch</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Daily Rate</th>
                                <th class="py-2 pr-4">Status</th>
                                @if (auth()->user()?->hasRole('owner'))
                                    <th class="py-2 pr-4">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($employees as $employee)
                                <tr class="border-b align-top">
                                    <td class="py-2 pr-4">{{ $employee->employee_code }}</td>
                                    <td class="py-2 pr-4">{{ $employee->first_name }} {{ $employee->last_name }}</td>
                                    <td class="py-2 pr-4">{{ $employee->branch?->name ?? '-' }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst(str_replace('_', ' ', $employee->employment_type)) }}</td>
                                    <td class="py-2 pr-4">{{ $employee->daily_rate !== null ? 'PHP '.number_format((float) $employee->daily_rate, 2) : '-' }}</td>
                                    <td class="py-2 pr-4">{{ $employee->is_active ? 'Active' : 'Inactive' }}</td>
                                    @if (auth()->user()?->hasRole('owner'))
                                        <td class="py-2 pr-4 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    class="px-2 py-1 bg-blue-700 text-white rounded text-xs"
                                                    @click="openEditor(@js([
                                                        'id' => $employee->id,
                                                        'branch_id' => $employee->branch_id,
                                                        'employee_code' => $employee->employee_code,
                                                        'first_name' => $employee->first_name,
                                                        'last_name' => $employee->last_name,
                                                        'email' => $employee->email,
                                                        'phone' => $employee->phone,
                                                        'hire_date' => $employee->hire_date,
                                                        'employment_type' => $employee->employment_type,
                                                        'daily_rate' => $employee->daily_rate,
                                                        'is_active' => (bool) $employee->is_active,
                                                    ]))"
                                                >
                                                    Edit
                                                </button>
                                                <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Delete this employee record?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="px-2 py-1 bg-red-700 text-white rounded text-xs">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ auth()->user()?->hasRole('owner') ? 7 : 6 }}" class="py-4 text-gray-500">No employees yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $employees->links() }}</div>
            </div>
        </div>

        @if (auth()->user()?->hasRole('owner'))
            <div
                x-cloak
                x-show="open"
                class="fixed inset-0 z-50 overflow-hidden"
                aria-labelledby="slide-over-title"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/50" @click="closeEditor"></div>
                <div class="absolute inset-y-0 right-0 flex max-w-full pl-10">
                    <div
                        x-show="open"
                        x-transition:enter="transform transition ease-in-out duration-300"
                        x-transition:enter-start="translate-x-full"
                        x-transition:enter-end="translate-x-0"
                        x-transition:leave="transform transition ease-in-out duration-300"
                        x-transition:leave-start="translate-x-0"
                        x-transition:leave-end="translate-x-full"
                        class="w-screen max-w-2xl"
                    >
                        <form method="POST" :action="form.action" class="flex h-full flex-col bg-white shadow-xl">
                            @csrf
                            @method('PUT')
                            <div class="px-4 py-4 sm:px-6 border-b flex items-center justify-between">
                                <h2 id="slide-over-title" class="text-lg font-semibold text-gray-900">Edit Employee</h2>
                                <button type="button" class="text-gray-600 hover:text-gray-900" @click="closeEditor">Close</button>
                            </div>
                            <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6 space-y-4">
                                <div>
                                    <label class="text-sm text-gray-600">Branch</label>
                                    <select name="branch_id" x-model="form.branch_id" class="w-full border rounded px-3 py-2" required>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-gray-600">Employee Code</label>
                                        <input type="text" name="employee_code" x-model="form.employee_code" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm text-gray-600">Hire Date</label>
                                        <input type="date" name="hire_date" x-model="form.hire_date" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-gray-600">First Name</label>
                                        <input type="text" name="first_name" x-model="form.first_name" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm text-gray-600">Last Name</label>
                                        <input type="text" name="last_name" x-model="form.last_name" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm text-gray-600">Email</label>
                                        <input type="email" name="email" x-model="form.email" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="text-sm text-gray-600">Phone</label>
                                        <input type="text" name="phone" x-model="form.phone" class="w-full border rounded px-3 py-2">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="text-sm text-gray-600">Employment Type</label>
                                        <select name="employment_type" x-model="form.employment_type" class="w-full border rounded px-3 py-2" required>
                                            <option value="full_time">Full Time</option>
                                            <option value="part_time">Part Time</option>
                                            <option value="contract">Contract</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-sm text-gray-600">Daily Rate</label>
                                        <input type="number" step="0.01" min="0" name="daily_rate" x-model="form.daily_rate" class="w-full border rounded px-3 py-2">
                                    </div>
                                    <div class="flex items-end">
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" value="1" x-model="form.is_active">
                                            <span>Active</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="px-4 py-4 sm:px-6 border-t flex items-center justify-end gap-2">
                                <button type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded" @click="closeEditor">Cancel</button>
                                <button class="px-4 py-2 bg-emerald-700 text-white rounded">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        function employeeEditor(config) {
            return {
                open: false,
                updateUrlTemplate: config.updateUrlTemplate,
                form: {
                    action: '',
                    branch_id: '',
                    employee_code: '',
                    first_name: '',
                    last_name: '',
                    email: '',
                    phone: '',
                    hire_date: '',
                    employment_type: 'full_time',
                    daily_rate: '',
                    is_active: true,
                },
                openEditor(employee) {
                    this.form = {
                        action: this.updateUrlTemplate.replace('__EMPLOYEE__', employee.id),
                        branch_id: String(employee.branch_id ?? ''),
                        employee_code: employee.employee_code ?? '',
                        first_name: employee.first_name ?? '',
                        last_name: employee.last_name ?? '',
                        email: employee.email ?? '',
                        phone: employee.phone ?? '',
                        hire_date: employee.hire_date ?? '',
                        employment_type: employee.employment_type ?? 'full_time',
                        daily_rate: employee.daily_rate ?? '',
                        is_active: Boolean(employee.is_active),
                    };
                    this.open = true;
                },
                closeEditor() {
                    this.open = false;
                },
            };
        }
    </script>
</x-app-layout>
