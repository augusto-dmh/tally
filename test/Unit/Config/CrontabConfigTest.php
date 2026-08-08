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

use Hyperf\Contract\ConfigInterface;
use Hyperf\Crontab\Crontab;
use Hyperf\Crontab\Process\CrontabDispatcherProcess;
use Hyperf\Context\ApplicationContext;
use PHPUnit\Framework\TestCase;

/**
 * Spec anchors: LEDG-06d — crontab registers ledger:reconcile; disabled in testing.
 *
 * @internal
 * @coversNothing
 */
final class CrontabConfigTest extends TestCase
{
    public function testCrontabRegistersLedgerReconcileCommandAndIsDisabledInTesting(): void
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);

        $this->assertFalse($config->get('crontab.enable'));

        $entries = $config->get('crontab.crontab', []);
        $this->assertNotEmpty($entries);

        $reconcile = null;
        foreach ($entries as $entry) {
            $this->assertInstanceOf(Crontab::class, $entry);
            if ($entry->getName() === 'ledger-reconcile') {
                $reconcile = $entry;
                break;
            }
        }

        $this->assertNotNull($reconcile);
        $this->assertSame('command', $reconcile->getType());
        $this->assertSame('*/5 * * * *', $reconcile->getRule());
        $callback = $reconcile->getCallback();
        $this->assertIsArray($callback);
        $this->assertSame('ledger:reconcile', $callback['command']);
        $this->assertTrue($callback['--disable-event-dispatcher']);
    }

    public function testCrontabDispatcherProcessIsRegistered(): void
    {
        $processes = require BASE_PATH . '/config/autoload/processes.php';

        $this->assertContains(CrontabDispatcherProcess::class, $processes);
    }
}
