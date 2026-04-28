# Restaurant Hub

A multi-location restaurant management platform built with Laravel 13. Handles employee management, attendance tracking, payroll processing, inventory, POS sales reporting, and expense tracking across multiple branches.

## Tech Stack

- **Backend** — Laravel 13 (PHP 8.2+), Eloquent ORM, Spatie Laravel Permission, Laravel Sanctum
- **Frontend** — Blade, Tailwind CSS 3, Alpine.js
- **Mobile API** — REST API (v1) with Sanctum token auth for the companion mobile POS app
- **Database** — MySQL
- **Build** — Vite

## Modules

| Module | Description |
|--------|-------------|
| **Employees** | Staff records with hourly/daily rates and employment type |
| **Attendance** | Clock-in/out, manual entry, and attendance history |
| **Payroll** | Period-based payroll generation with configurable rules per branch |
| **Menu** | Menu categories and items management per branch |
| **Inventory** | Ingredient tracking, stock adjustments, and movement history per branch |
| **Sales** | POS sales reporting with cash/GCash/mixed payment tracking |
| **Expenses** | Expense recording with categories and payment methods |

## Roles

- **Owner** — full access to all modules
- **Cashier** — limited to attendance and employee views

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

App runs at `http://localhost:8888/restaurant-hub/public`

## Development

```bash
npm run dev          # Vite dev server with hot reload
php artisan test     # Run test suite
```

## API

REST API at `/api/v1/` authenticated via Laravel Sanctum (Bearer token). Used by the companion mobile POS + Attendance app.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/login` | POST | Authenticate and receive token |
| `/api/v1/logout` | POST | Revoke current token |
| `/api/v1/me` | GET | Current user + employee info |
| `/api/v1/menu` | GET | Menu categories with items |
| `/api/v1/employees` | GET | Active employees for branch |
| `/api/v1/orders` | GET/POST | List/create orders |
| `/api/v1/orders/{id}/pay` | POST | Process payment (cash/gcash/mixed) |
| `/api/v1/orders/{id}/void` | POST | Void an order |
| `/api/v1/attendance/clock-in` | POST | Clock in an employee |
| `/api/v1/attendance/clock-out` | POST | Clock out an employee |
| `/api/v1/attendance/today` | GET | Today's attendance records |
