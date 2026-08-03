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

namespace HyperfTest\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BootstrapTest extends TestCase
{
    public function testTheTestRuntimeRunsInUtc(): void
    {
        $this->assertSame('UTC', date_default_timezone_get());
    }
}
