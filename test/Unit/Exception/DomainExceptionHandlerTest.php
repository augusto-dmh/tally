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

namespace HyperfTest\Unit\Exception;

use App\Domain\Exception\InsufficientBalance;
use App\Domain\Exception\NotificationFailed;
use App\Domain\Exception\TransferUnauthorized;
use App\Exception\Handler\DomainExceptionHandler;
use PHPUnit\Framework\TestCase;

/**
 * CONC-04 handler contract: NotificationFailed is swallowed after commit in
 * TransferFunds, so it must not be a mapped DomainExceptionHandler outcome.
 * Re-adding NotificationFailed → 502 to OUTCOMES must fail this test (M5).
 *
 * @internal
 * @coversNothing
 */
final class DomainExceptionHandlerTest extends TestCase
{
    public function testNotificationFailedIsNotAMappedDomainOutcome(): void
    {
        $handler = new DomainExceptionHandler();

        $this->assertFalse(
            $handler->isValid(new NotificationFailed('payee could not be notified')),
            'NotificationFailed must not map to an HTTP business outcome (e.g. 502).'
        );
    }

    public function testKnownBusinessExceptionsRemainMapped(): void
    {
        $handler = new DomainExceptionHandler();

        $this->assertTrue($handler->isValid(new TransferUnauthorized('declined')));
        $this->assertTrue($handler->isValid(new InsufficientBalance('short')));
    }
}
