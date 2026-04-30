# Restaurant Hub

A multi-location restaurant management platform built with Laravel 13. Handles employee management, attendance tracking, payroll processing, periodic stock counting, POS sales reporting, expense tracking, and end-of-day cash reconciliation across multiple branches. Web admin in Blade + Tailwind, companion mobile POS app in React Native (Expo).

## Tech Stack

- **Backend** — Laravel 13 (PHP 8.2+), Eloquent ORM, Spatie Laravel Permission, Laravel Sanctum
- **Frontend** — Blade, Tailwind CSS 3, Alpine.js
- **Mobile API** — REST API (v1) with Sanctum token auth for the companion mobile POS app
- **Mobile** — React Native + Expo, offline-first with SQLite + sync queue
- **Database** — MySQL
- **Build** — Vite

## Modules

| Module | Description |
|--------|-------------|
| **Dashboard** | Today's net income, cash on hand, attendance, 7-day sales trend, recent activity, low-stock alerts, day closure status |
| **Employees** | Staff records with daily rates, employment type, birthday tracking, contact info, branch assignment. Detail drawer with attendance summary and payroll history |
| **Attendance** | Live roster grouped by state (working / done / not in yet / absent / leave). Quick clock-in / clock-out, manual entry, and weekly summary |
| **Payroll** | Period-based payroll generation with configurable per-branch rules (tiered late deductions). Bulk-generate for multiple employees, finalize-and-lock workflow |
| **Menu** | Menu categories and items management per branch |
| **Inventory** | Periodic stock counting (instead of perpetual movements). Records per-period consumption and computes daily burn rate + days remaining per ingredient |
| **Sales** | POS sales reporting with date presets, daily trend chart, payment-method and order-type breakdowns, drill-in detail drawer |
| **Expenses** | Expense recording with categories and payment methods (cash, gcash, bank transfer, other) |
| **Day Closure** | End-of-day cash reconciliation: counts cash sales − cash expenses, compares to drawer count, computes variance, auto-clocks-out employees still on the clock |
| **Cash Report** | Historical day-closure ledger with running totals (cash on hand, expected, variance, cash expenses) over a date range |

## Roles

- **Owner** — full access to all modules
- **Cashier** — limited access: attendance, employees, day closure (own branch)

## Local Setup

**Requirements:** MAMP (Apache + MySQL + PHP 8.2+), Composer, Node.js

```bash
# Install dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate
```

Update `.env` with your local database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=8889
DB_DATABASE=restaurant_hub
DB_USERNAME=root
DB_PASSWORD=root
```

```bash
# Run migrations
php artisan migrate

# Build assets
npm run build
```

App runs at `http://localhost:8888/restaurant-hub/public`.

To serve the API to the mobile app on the same LAN:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Then point `restaurant-hub-mobile/src/config/env.ts` at `http://<your-lan-ip>:8000/api/v1`.

## Development

```bash
npm run dev          # Vite dev server with hot reload
npm run build        # Production build (required before testing in MAMP)
php artisan test     # Run full PHPUnit/Pest test suite
```

> Assets must be compiled (`npm run build`) before testing in MAMP. The `public/build/` directory is git-ignored; rebuild after pulling.

## Database Architecture

| Table | Purpose |
|-------|---------|
| `users` | Auth accounts (linked to employees) |
| `branches` | Restaurant locations |
| `employees` | Staff records with rates, employment type, hire date, birthday |
| `attendance_records` | Daily clock-in/out logs |
| `payroll_periods` | Pay period groupings |
| `payroll_entries` | Per-employee payroll calculations |
| `payroll_rules` | Per-branch rules: required clock-in time, tiered deductions, overtime |
| `sales` / `sale_items` | POS transactions and line items |
| `expenses` / `expense_categories` | Expense records and categorisation |
| `menu_items` / `menu_categories` | Menu definitions |
| `ingredients` | Inventory master list (name, unit, reorder level) |
| `stock_counts` / `stock_count_entries` | Periodic count sessions and per-ingredient entries |
| `day_closures` | End-of-day cash reconciliation snapshots |

### Key Enums

- `attendance_records.status` — `present`, `late`, `absent`, `leave`, `holiday`
- `payroll_entries.status` — `draft`, `paid`
- `sales.status` — `open`, `completed`, `voided`, `refunded`
- `sales.payment_method` — `cash`, `gcash`, `mixed`, `unpaid`
- `expenses.payment_method` — `cash`, `bank_transfer`, `gcash`, `other`
- `expenses.status` — `draft`, `approved`, `voided`

## API

REST API at `/api/v1/` authenticated via Laravel Sanctum (Bearer token). Used by the companion mobile POS + Attendance app.

### Auth

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/auth/login` | POST | Authenticate and receive token |
| `/api/v1/auth/logout` | POST | Revoke current token |
| `/api/v1/auth/me` | GET | Current user + employee info |

### Menu

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/menu/categories` | GET | List menu categories |
| `/api/v1/menu/items` | GET | List menu items with pricing |

### Orders (POS)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/orders` | GET / POST | List or create orders |
| `/api/v1/orders/{id}` | GET | Order detail with items |
| `/api/v1/orders/{id}/pay` | POST | Process payment (cash / gcash / mixed) |
| `/api/v1/orders/{id}/void` | POST | Void an order |

### Attendance

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/employees` | GET | Active employees for the user's branch |
| `/api/v1/attendance/clock-in` | POST | Clock in an employee |
| `/api/v1/attendance/clock-out` | POST | Clock out an employee |
| `/api/v1/attendance/today` | GET | Today's attendance records |

### Expenses

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/expenses` | GET / POST | List or create expenses |
| `/api/v1/expense-categories` | GET | Active expense categories |

### Day Closure

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/day-close/preview` | GET | Cash breakdown + still-clocked-in employees for today |
| `/api/v1/day-close` | POST | Submit closure (variance computed, employees auto-clocked-out) |
| `/api/v1/day-close/history` | GET | Recent closures for the branch |

## Project Structure

```
app/
  Http/Controllers/          # Web controllers (Blade routes)
    Api/V1/                  # Mobile-facing API controllers
  Models/                    # Eloquent models
  Services/                  # Business logic (AttendanceSummaryCalculator etc.)
resources/
  views/
    dashboard.blade.php      # Main dashboard
    layouts/                 # App + store layouts, sidebar nav
    modules/                 # Per-module pages (employees, attendance, payroll, …)
    stores/                  # Public-facing restaurant landing pages
  css/app.css                # Tailwind + custom rh-* design system
routes/
  web.php                    # Admin routes
  api.php                    # Mobile API routes
database/
  migrations/                # Schema migrations
```

## Testing

- **Framework** — PHPUnit (Laravel default)
- **Database** — SQLite in-memory (configured in `phpunit.xml`)
- **Run all** — `php artisan test`
- **Run one file** — `php artisan test --filter=ClassName`

## License

Proprietary. All rights reserved.
