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

namespace App\Command;

use App\Application\DrainOutbox;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CLI entry for one DrainOutbox pass; exit 0 on normal completion.
 */
#[Command]
final class OutboxDrainCommand extends HyperfCommand
{
    protected ?string $name = 'outbox:drain';

    public function __construct(
        private readonly DrainOutbox $drainOutbox,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($this->name);
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Drain due outbox rows once (claim, notify, mark done/retry/dead).');
    }

    public function handle(): int
    {
        try {
            $result = $this->drainOutbox->execute();
        } catch (Throwable $exception) {
            $this->logger->error(sprintf(
                'Outbox drain failed unexpectedly: %s',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->logger->info(sprintf(
            'Outbox drain completed: processed=%d done=%d failed=%d dead=%d',
            $result->processed,
            $result->done,
            $result->failed,
            $result->dead,
        ));

        return self::SUCCESS;
    }
}
