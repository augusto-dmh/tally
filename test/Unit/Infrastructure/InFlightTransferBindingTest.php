<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * SCOPE-08: production DI binds the Context store, not a property or
 * recording fake. SCOPE-06 HTTP half: empty get is not a new error slug.
 *
 * @internal
 * @coversNothing
 */
final class InFlightTransferBindingTest extends TestCase
{
    public function testProductionDependenciesBindContextInFlightTransfer(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/config/autoload/dependencies.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('InFlightTransfer::class', $source);
        $this->assertStringContainsString('ContextInFlightTransfer', $source);
        $this->assertStringContainsString(
            'InFlightTransfer::class => ContextInFlightTransfer::class',
            $source
        );
        $this->assertStringNotContainsString('PropertyInFlightTransfer', $source);
        $this->assertStringNotContainsString('RecordingInFlightTransfer', $source);
    }

    public function testDomainExceptionHandlerHasNoMissingScopeSlug(): void
    {
        $handler = file_get_contents(dirname(__DIR__, 3) . '/app/Exception/Handler/DomainExceptionHandler.php');
        $outcomes = file_get_contents(dirname(__DIR__, 3) . '/app/Application/TransferFunds.php');
        $this->assertIsString($handler);
        $this->assertIsString($outcomes);

        foreach ([$handler, $outcomes] as $source) {
            $this->assertStringNotContainsString('missing_scope', $source);
            $this->assertStringNotContainsString('missing-scope', $source);
            $this->assertStringNotContainsString('in_flight', $source);
            $this->assertStringNotContainsString('InFlightTransfer', $source);
        }
    }
}
