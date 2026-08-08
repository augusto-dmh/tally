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

use App\Application\ReconcileLedger;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Log\LoggerInterface;

/**
 * CLI entry for read-only reconcile; exit 0 when clean, 1 on violations.
 */
#[Command]
final class LedgerReconcileCommand extends HyperfCommand
{
    protected ?string $name = 'ledger:reconcile';

    public function __construct(
        private readonly ReconcileLedger $reconcileLedger,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($this->name);
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Reconcile ledger aggregates against wallet projections (read-only).');
    }

    public function handle(): int
    {
        $result = $this->reconcileLedger->execute();

        if (! $result->ok) {
            foreach ($result->violations as $violation) {
                $this->logger->error($violation);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
