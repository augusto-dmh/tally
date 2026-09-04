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

namespace HyperfTest\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * POOL-07: production databases.php pool knobs stay 10 / 3.0.
 *
 * @internal
 * @coversNothing
 */
final class DatabasePoolConfigTest extends TestCase
{
    public function testProductionDefaultPoolKnobsAreUnchanged(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/config/autoload/databases.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'max_connections' => 10", $source);
        $this->assertStringContainsString("'wait_timeout' => 3.0", $source);
        $this->assertStringNotContainsString('pool_exhaustion_probe', $source);
    }
}
