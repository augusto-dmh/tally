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

namespace HyperfTest\Unit\Domain;

use App\Domain\InFlightTransferParties;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class InFlightTransferPartiesTest extends TestCase
{
    public function testItHoldsTheGivenPayerAndPayeeIds(): void
    {
        $parties = new InFlightTransferParties(7, 11);

        $this->assertSame(7, $parties->payerId);
        $this->assertSame(11, $parties->payeeId);
    }
}
