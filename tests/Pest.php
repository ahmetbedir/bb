<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Run an artisan command through the kernel with a buffered output and return
 * [exitCode, output]. Unlike the artisan() test double, this captures Laravel
 * Prompts output (tables, notes), which is written to its own output stream.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $parameters = []): array
{
    // A headless test has no real terminal; pin a wide width so table sizing
    // is deterministic (mirrors a normal-width terminal, not an 80-col squeeze).
    putenv('COLUMNS=200');

    // Decorated so Laravel Prompts treats ANSI / OSC 8 escapes as zero-width
    // when sizing tables — mirroring a real terminal.
    $buffer = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
    $code = app(Kernel::class)->call($command, $parameters, $buffer);

    return [$code, $buffer->fetch()];
}
