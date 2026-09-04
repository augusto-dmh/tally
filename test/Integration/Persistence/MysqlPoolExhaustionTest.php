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

namespace HyperfTest\Integration\Persistence;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Pool\DbPool;
use Hyperf\DbConnection\Pool\PoolFactory;
use Hyperf\Engine\Channel;
use HyperfTest\Integration\IntegrationTestCase;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;

/**
 * Spec anchors: POOL-01 (hold-across-slow-work exhausts a dedicated tiny
 * Hyperf MySQL pool), POOL-02 (same knobs, slow work outside the txn, does
 * not exhaust), POOL-05 (existing feature suite stays green via gate-full).
 *
 * @internal
 * @coversNothing
 */
final class MysqlPoolExhaustionTest extends IntegrationTestCase
{
    private const PROBE = 'pool_exhaustion_probe';

    private const EXHAUSTED = 'Connection pool exhausted. Cannot establish new connection before wait_timeout.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->installProbePool();
    }

    public function testHoldingAConnectionAcrossSlowWorkExhaustsTheTinyPool(): void
    {
        $this->assertProbeIsARealDbPool();

        $holding = new Channel(1);
        $release = new Channel(1);
        $parallel = new Parallel(2);

        $parallel->add(function () use ($holding, $release): string {
            $open = false;
            $db = Db::connection(self::PROBE);

            try {
                $db->beginTransaction();
                $open = true;
                $db->select('select 1');
                $holding->push(true);
                $release->pop();
                Coroutine::sleep(0.5);
                $db->select('select 1');
                $db->commit();
                $open = false;

                return 'committed';
            } finally {
                if ($open) {
                    try {
                        $db->rollBack();
                    } catch (Throwable) {
                    }
                }
            }
        }, 'a');

        $parallel->add(function () use ($holding, $release): RuntimeException|string {
            $holding->pop();

            try {
                $db = Db::connection(self::PROBE);
                $db->beginTransaction();
                $db->select('select 1');

                return 'borrowed';
            } catch (RuntimeException $exception) {
                return $exception;
            } finally {
                $release->push(true);
            }
        }, 'b');

        $results = $parallel->wait();

        $this->assertSame('committed', $results['a']);
        $this->assertInstanceOf(RuntimeException::class, $results['b']);
        $this->assertSame(self::EXHAUSTED, $results['b']->getMessage());
        $this->assertProductionPoolFileUnchanged();
    }

    public function testSlowWorkOutsideATransactionDoesNotExhaustTheTinyPool(): void
    {
        $this->assertProbeIsARealDbPool();

        $parallel = new Parallel(2);
        $parallel->add(fn (): string => $this->sleepThenShortTransaction(), 'a');
        $parallel->add(fn (): string => $this->sleepThenShortTransaction(), 'b');
        $results = $parallel->wait();

        $this->assertSame('ok', $results['a']);
        $this->assertSame('ok', $results['b']);
        $this->assertProductionPoolFileUnchanged();
    }

    private function sleepThenShortTransaction(): string
    {
        Coroutine::sleep(0.5);

        $open = false;
        $db = Db::connection(self::PROBE);

        try {
            $db->beginTransaction();
            $open = true;
            $db->select('select 1');
            $db->commit();
            $open = false;

            return 'ok';
        } finally {
            if ($open) {
                try {
                    $db->rollBack();
                } catch (Throwable) {
                }
            }
        }
    }

    private function installProbePool(): void
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $default = $config->get('databases.default');
        $this->assertIsArray($default);

        $probe = $default;
        $probe['pool'] = [
            'min_connections' => 1,
            'max_connections' => 1,
            'connect_timeout' => 10.0,
            'wait_timeout' => 0.2,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ];
        $config->set('databases.' . self::PROBE, $probe);
    }

    private function assertProbeIsARealDbPool(): void
    {
        $pool = ApplicationContext::getContainer()->get(PoolFactory::class)->getPool(self::PROBE);

        $this->assertInstanceOf(DbPool::class, $pool);
        $this->assertSame(1, $pool->getOption()->getMaxConnections());
        $this->assertSame(0.2, $pool->getOption()->getWaitTimeout());
    }

    private function assertProductionPoolFileUnchanged(): void
    {
        $source = file_get_contents(BASE_PATH . '/config/autoload/databases.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'max_connections' => 10", $source);
        $this->assertStringContainsString("'wait_timeout' => 3.0", $source);
        $this->assertStringNotContainsString(self::PROBE, $source);
    }
}
