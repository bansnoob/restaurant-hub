<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An unclosed <template> is invisible to every other kind of test.
 *
 * Blade renders it happily, the route returns 200, and assertSee() finds everything
 * it looks for — because the string is all there. The damage happens in the browser's
 * parser: an unclosed <template> swallows the rest of the document, so every <script>
 * after it is never executed and every Alpine component on the page is undefined.
 *
 * That shipped. `special-drawers.blade.php` was included halfway down the Cash Report
 * and left its `x-if="formOpen"` template open, which ate both inline scripts. The
 * page returned 200, the suite was green, and the whole page was inert — no Edit Day
 * button, no drawers, four "is not defined" errors in the console.
 *
 * The same partial is included at the very END of /expenses/special, where the missing
 * closers coincided with the end of the document and nothing was lost. So the bug was
 * live on one page and harmless on the other, which is exactly how it survived review.
 */
class RenderedMarkupIsBalancedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $branch->id]);
        $this->owner->assignRole('owner');
    }

    public static function pages(): array
    {
        return [
            'cash report' => ['day-closures.index'],
            'special expenses' => ['special-expenses.index'],
            'expenses' => ['expenses.index'],
            'payroll' => ['payroll.index'],
            'attendance' => ['attendance.index'],
            'employees' => ['employees.index'],
            'inventory' => ['inventory.index'],
            'gcash report' => ['gcash-report.index'],
            'sales' => ['sales.index'],
            'dashboard' => ['dashboard'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_template_and_div_is_closed(string $routeName): void
    {
        $html = $this->actingAs($this->owner)->get(route($routeName))->assertOk()->getContent();

        foreach (['template', 'div', 'form', 'table', 'select'] as $tag) {
            $open = preg_match_all('/<'.$tag.'\b/i', $html);
            $close = preg_match_all('#</'.$tag.'>#i', $html);

            $this->assertSame(
                $open,
                $close,
                "{$routeName}: {$open} <{$tag}> opened but {$close} closed. An unclosed <{$tag}> — "
                .'a <template> especially — makes the browser swallow the rest of the document, '
                .'so every script after it never runs and the page is inert. Blade and this suite '
                .'cannot see that on their own.'
            );
        }
    }

    #[DataProvider('pages')]
    public function test_the_pages_inline_scripts_survive_parsing(string $routeName): void
    {
        $html = $this->actingAs($this->owner)->get(route($routeName))->assertOk()->getContent();

        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/si', $html, $matches);
        $defined = [];
        foreach ($matches[1] as $body) {
            preg_match_all('/function\s+(\w+)\s*\(/', $body, $fns);
            $defined = array_merge($defined, $fns[1]);
        }

        // Components may also come from the Vite bundle rather than an inline script.
        $bundle = '';
        foreach (glob(resource_path('js').'/{,*/,*/*/}*.js', GLOB_BRACE) as $file) {
            $bundle .= file_get_contents($file);
        }

        preg_match_all('/x-data="\s*(\w+)\(/', $html, $components);

        foreach (array_unique($components[1]) as $component) {
            if (str_contains($bundle, 'function '.$component) || str_contains($bundle, $component.' =')) {
                continue;
            }

            $this->assertContains(
                $component,
                $defined,
                "{$routeName}: x-data mounts {$component}() but nothing defines it — no inline "
                .'script and nothing in resources/js. If the defining script sits after an '
                .'unclosed tag, the browser never runs it.'
            );
        }
    }
}
