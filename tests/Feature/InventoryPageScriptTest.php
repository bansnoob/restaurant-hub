<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the inventory page's JavaScript tests as part of `php artisan test`.
 *
 * The count modal's branch scoping is front-end behaviour: the server guard
 * rejects a cross-branch entry, but only the component decides which branch's
 * ingredients are ever rendered and posted. While that code was inline in the
 * blade nothing could execute it, and a full revert of the front-end half of
 * the fix left the PHP suite green. tests/js/inventory-page.test.js covers it;
 * this makes the project's one verification command actually run it.
 *
 * Skipped, never failed, when Node is unavailable — the PHP suite must not
 * depend on a JS toolchain being installed.
 */
class InventoryPageScriptTest extends TestCase
{
    private const MINIMUM_NODE_MAJOR = 18;

    private const TEST_TIMEOUT_SECONDS = 120;

    public function test_the_inventory_page_component_passes_its_javascript_suite(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed; run `npm run test:js` where it is.');
        }

        $this->skipUnlessNodeIsRecentEnough($node);

        $root = dirname(__DIR__, 2);
        $files = glob($root.'/tests/js/*.test.js') ?: [];
        $this->assertNotEmpty($files, 'the inventory page JS suite has disappeared');

        $process = new Process([$node, '--test', ...$files], $root, timeout: self::TEST_TIMEOUT_SECONDS);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "the inventory page JS suite failed:\n".$process->getOutput().$process->getErrorOutput()
        );
    }

    private function skipUnlessNodeIsRecentEnough(string $node): void
    {
        $version = new Process([$node, '--version'], timeout: 30);
        $version->run();

        $major = (int) ltrim(explode('.', trim($version->getOutput()))[0] ?? '', 'v');

        if ($major < self::MINIMUM_NODE_MAJOR) {
            $this->markTestSkipped('node:test needs Node '.self::MINIMUM_NODE_MAJOR.'+; found '.trim($version->getOutput()).'.');
        }
    }
}
