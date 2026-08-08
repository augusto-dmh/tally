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

namespace App\Process;

use App\Application\DrainOutbox;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * Custom process that repeatedly drains the outbox while the server runs.
 */
final class OutboxRelayProcess extends AbstractProcess
{
    public string $name = 'outbox-relay';

    public int $nums = 1;

    public function handle(): void
    {
        /** @var DrainOutbox $drain */
        $drain = $this->container->get(DrainOutbox::class);
        $sleepSeconds = (int) config('outbox.process_sleep_seconds', 1);

        while (ProcessManager::isRunning()) {
            $result = $drain->execute();
            if ($result->processed === 0) {
                sleep($sleepSeconds);
            }
        }
    }

    public function isEnable($server): bool
    {
        if (env('APP_ENV') === 'testing') {
            return false;
        }

        return (bool) config('outbox.relay_enable', true);
    }
}
