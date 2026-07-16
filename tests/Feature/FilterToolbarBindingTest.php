<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alpine only binds directives inside an x-data component. A filter control that reaches for
 * $refs.filterForm from outside one is silently inert — the dropdown moves and nothing
 * happens. The Cash Report shipped that way; this pins every report toolbar against it.
 */
class FilterToolbarBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    public static function toolbarRoutes(): array
    {
        return [
            'cash report' => ['day-closures.index'],
            'gcash report' => ['gcash-report.index'],
            'sales' => ['sales.index'],
            'expenses' => ['expenses.index'],
            'payroll' => ['payroll.index'],
            'inventory' => ['inventory.index'],
            'employees' => ['employees.index'],
            'attendance' => ['attendance.index'],
        ];
    }

    #[DataProvider('toolbarRoutes')]
    public function test_filter_controls_are_reachable_by_alpine_or_use_native_submit(string $routeName): void
    {
        $html = $this->actingAs($this->owner)->get(route($routeName))->assertOk()->getContent();

        foreach ($this->unscopedAlpineBindings($html) as $offender) {
            $this->fail(
                "{$routeName}: <{$offender}> references \$refs.filterForm but has no x-data ancestor, ".
                'so Alpine never binds it and the filter is inert. Use onchange="this.form.submit()" '.
                'or wrap the page in an x-data component.'
            );
        }

        $this->assertTrue(true);
    }

    /**
     * @return array<int, string> tag snippets that use $refs.filterForm outside any x-data scope
     */
    private function unscopedAlpineBindings(string $html): array
    {
        $offenders = [];

        /** @var array<int, array{tag: string, xData: bool}> $stack open ancestors */
        $stack = [];

        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(\/?)>/', $html, $matches, PREG_SET_ORDER);

        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

        foreach ($matches as $m) {
            $closing = $m[1];
            $tag = strtolower($m[2]);
            $attrs = $m[3];
            $selfClose = $m[4];

            if ($closing === '/') {
                // Unwind to the matching open tag, tolerating unclosed markup.
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $tag) {
                        array_splice($stack, $i);
                        break;
                    }
                }

                continue;
            }

            $hasXData = (bool) preg_match('/(?:^|\s)x-data(?:=|\s|>|$)/', $attrs);

            $scoped = $hasXData;
            foreach ($stack as $ancestor) {
                if ($ancestor['xData']) {
                    $scoped = true;
                    break;
                }
            }

            if (str_contains($attrs, '$refs.filterForm') && ! $scoped) {
                $offenders[] = $tag.' '.trim(substr($attrs, 0, 60));
            }

            if (! in_array($tag, $void, true) && $selfClose !== '/') {
                $stack[] = ['tag' => $tag, 'xData' => $hasXData];
            }
        }

        return $offenders;
    }
}
