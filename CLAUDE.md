# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview
Restaurant Hub is a multi-location restaurant management platform built with Laravel 12. It handles employee management, attendance tracking, payroll processing, POS/sales, and expense tracking. The platform supports two roles: **owner** (full access) and **cashier** (limited access).

**Tech stack:** Laravel 12.x (PHP 8.2+), Eloquent ORM, Spatie Laravel Permission v7.1, Tailwind CSS 3.1, Alpine.js 3.4, Vite 7.x, MySQL.

## Environment Setup
- **Local Server**: MAMP (Apache + MySQL + PHP 8.2+)
- **URL**: `http://localhost:8888/restaurant-hub/public`
- **Database**: `restaurant_hub` on MySQL `127.0.0.1:8889`
- **DB Credentials**: root/root (local dev)
- **Timezone**: Asia/Manila

### Dev Commands
```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build        # Production build (required before testing in MAMP)
npm run dev          # Dev server with Vite hot reload
php artisan test                                    # Run all tests
php artisan test --filter=ClassName                 # Run a single test class
php artisan test --filter=ClassName::methodName     # Run a single test method
```

> Assets must be compiled (`npm run build`) before testing in MAMP. The `public/build/` directory is git-ignored; always rebuild after pulling.

## Architecture

### MVC with Service Layer
- **Models** — Eloquent models in `app/Models/` (13 models)
- **Controllers** — HTTP logic in `app/Http/Controllers/` (7 controllers)
- **Views** — Blade templates in `resources/views/modules/` organized by feature
- **Services** — Complex business logic in `app/Services/`
- **Form Requests** — Validation in `app/Http/Requests/`

### Role-Based Access Control
Uses Spatie Laravel Permission. Route-level middleware: `role:owner` or `role:owner|cashier`.

| Role | Access |
|------|--------|
| `owner` | Full access to all routes |
| `cashier` | Attendance (index, clock-in, clock-out), Employee (index, store) |

## Database Architecture
- **Primary DB**: `restaurant_hub` (MySQL, MAMP port 8889)
- **Testing DB**: SQLite in-memory (configured in `phpunit.xml`)
- **Schema source of truth**: Migrations + Eloquent models

### Key Tables
| Table | Purpose |
|-------|---------|
| `users` | Auth accounts (linked to employees) |
| `branches` | Restaurant locations |
| `employees` | Staff records with hourly/daily rates |
| `shifts` | Shift definitions — **no Eloquent model**, raw queries only |
| `attendance_records` | Daily clock-in/out logs |
| `payroll_periods` | Pay period groupings (draft/finalized) |
| `payroll_entries` | Individual payroll calculations per employee |
| `payroll_rules` | Per-branch rules for late penalties, overtime, etc. |
| `sales` | POS transactions |
| `sale_items` | Line items within a sale — **no Eloquent model** |
| `expenses` | Expense records |
| `expense_categories` | Expense classification |
| `menu_items` | Menu items with pricing |
| `menu_categories` | Menu groupings |
| `ingredients` | Ingredient master list |

### Status Enums
- `attendance_records.status`: `present`, `late`, `absent`, `leave`, `holiday`
- `payroll_periods.status`: `draft`, `finalized`
- `payroll_entries.status`: `draft`, `paid`
- `sales.status`: `open`, `completed`, `voided`, `refunded`
- `sales.payment_method`: `cash`, `card`, `e_wallet`, `mixed`, `unpaid`
- `expenses.status`: `draft`, `approved`, `voided`
- `employees.employment_type`: `full_time`, `part_time`, `contract`
- `sales.order_type`: `dine_in`, `takeout`, `delivery`

### Model Relationships
- `User` → `Employee` (hasOne)
- `Employee` → `Branch` (belongsTo)
- `Employee` → `AttendanceRecord` (hasMany)
- `Branch` → `PayrollRule` (hasOne)
- `PayrollPeriod` → `PayrollEntry` (hasMany)
- `PayrollEntry` → `Employee` (belongsTo)
- `Sale` → `SaleItem` (hasMany)
- `Expense` → `ExpenseCategory` (belongsTo)

## Key File Locations

### Backend
| Path | Purpose |
|------|---------|
| `app/Http/Controllers/PayrollController.php` | Payroll generation and management (large — read in sections) |
| `app/Http/Controllers/AttendanceController.php` | Clock-in/out, manual entry |
| `app/Http/Controllers/EmployeeController.php` | Employee CRUD |
| `app/Http/Controllers/SalesController.php` | POS/sales views |
| `app/Http/Controllers/ExpenseController.php` | Expense tracking |
| `app/Services/AttendanceSummaryCalculator.php` | Complex attendance → payroll calculations |
| `routes/web.php` | All application routes |
| `routes/auth.php` | Authentication routes |

### Frontend
| Path | Purpose |
|------|---------|
| `resources/views/layouts/app.blade.php` | Authenticated app layout |
| `resources/views/modules/` | Feature views (attendance, payroll, employees, sales, expenses) |
| `resources/views/stores/` | Public restaurant landing pages (assignature, ramen-naijiro, marugo-takoyaki) |
| `resources/views/components/` | Reusable Blade components (modal, buttons, inputs, dropdowns) |
| `resources/css/app.css` | Tailwind + custom `.rh-*` styles |

## Payroll System (Most Complex)
- **`PayrollController`** — orchestrates period creation, generation, finalization
- **`AttendanceSummaryCalculator`** — computes pay from attendance records using branch `payroll_rules`
- **`PayrollRule`** — per-branch config: grace minutes, late penalty per minute, overtime multiplier, tiered deductions (3 thresholds), absent penalty days, required clock-in time

**Payroll flow:**
1. Owner configures `PayrollRule` per branch
2. Owner creates a `PayrollPeriod` (start/end date, cutoff label)
3. System generates `PayrollEntry` per employee via `AttendanceSummaryCalculator`
4. Owner reviews entries, then finalizes the period
5. Entries can be deleted; period can be destroyed if still draft

## Frontend Patterns

### Alpine.js
```html
<div x-data="{ open: false }">
    <button @click="open = !open">Toggle</button>
    <div x-show="open">Content</div>
</div>
```

### AJAX (Axios)
```javascript
axios.post('/payroll/generate', { branch_id: branchId })
    .then(response => { /* handle success */ })
    .catch(error => { /* handle error */ });
```

## Testing
- **Framework**: PHPUnit 11.5.3
- **DB**: SQLite in-memory (see `phpunit.xml`)
- **Location**: `tests/Feature/` and `tests/Unit/`
- **Coverage target**: 80%+

Existing tests cover: Auth (registration, login, password, email verification), Profile management.

## Development Notes
- **No API endpoints** — all responses are HTML redirects or full-page renders
- **No soft deletes** — records are hard-deleted
- **Multi-branch** — all major entities are scoped to `branch_id`

## Behavioral Rules
1. **Ambiguity Protocol**: If any task is unclear or has multiple paths, use `AskUserQuestion` before writing code.
2. **Commit Workflow**: After completing a task, prompt to stage and commit with a descriptive message.
3. **Verification**: Before finishing, ask a follow-up to confirm the output meets requirements.
