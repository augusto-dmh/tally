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

namespace HyperfTest\Unit\Process;

use App\Process\OutboxRelayProcess;
use Hyperf\Context\ApplicationContext;
use PHPUnit\Framework\TestCase;

use function Hyperf\Support\env;

/**
 * Spec anchors: OUTB-12 (relay disabled under testing; registered in processes).
 *
 * @internal
 * @coversNothing
 */
final class OutboxRelayProcessTest extends TestCase
{
    public function testIsDisabledWhenAppEnvIsTesting(): void
    {
        $this->assertSame('testing', env('APP_ENV'));

        $process = ApplicationContext::getContainer()->get(OutboxRelayProcess::class);

        $this->assertFalse($process->isEnable(null));
    }

    public function testIsRegisteredBesideCrontabDispatcher(): void
    {
        $processes = require BASE_PATH . '/config/autoload/processes.php';

        $this->assertContains(OutboxRelayProcess::class, $processes);
    }
}
