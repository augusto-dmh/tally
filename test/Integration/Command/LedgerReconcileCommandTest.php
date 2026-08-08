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

namespace HyperfTest\Integration\Command;

use App\Application\ReconcileLedger;
use App\Application\TransferFunds;
use App\Application\TransferFundsInput;
use App\Command\LedgerReconcileCommand;
use App\Domain\Money;
use App\Infrastructure\Persistence\OpeningLedgerBackfill;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Spec anchors: LEDG-06 (drift → non-zero exit, error log, no writes),
 * LEDG-06b (clean → exit 0), LEDG-06c (failed reconcile does not block transfer).
 *
 * @internal
 * @coversNothing
 */
final class LedgerReconcileCommandTest extends IntegrationTestCase
{
    public function testExitsZeroWhenLedgerAndProjectionsMatch(): void
    {
        $this->seedBalancedWallets();

        $exitCode = $this->runReconcile();

        $this->assertSame(0, $exitCode);
    }

    public function testExitsNonZeroLogsViolationsAndWritesNothingOnProjectionDrift(): void
    {
        [$walletId] = $this->seedBalancedWallets();
        $ledgerCountBefore = (int) Db::table('ledger_entries')->count();
        $balanceBefore = $this->balanceOf($walletId);

        Db::table('wallets')->where('id', $walletId)->update(['balance_cents' => $balanceBefore + 1]);

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = (string) $message;
                }
            }
        };

        $exitCode = $this->runReconcile($logger);

        $this->assertSame(1, $exitCode);
        $this->assertNotEmpty($logger->errors);
        $this->assertStringContainsString('wallet_projection_drift', $logger->errors[0]);
        $this->assertSame($ledgerCountBefore, (int) Db::table('ledger_entries')->count());
        $this->assertSame($balanceBefore + 1, $this->balanceOf($walletId));
    }

    public function testFailedReconcileDoesNotBlockASubsequentSuccessfulTransfer(): void
    {
        [$payerWalletId, $payeeWalletId, $payerUserId, $payeeUserId] = $this->seedBalancedWallets();
        Db::table('wallets')->where('id', $payerWalletId)->update([
            'balance_cents' => 100001,
        ]);

        $this->assertSame(1, $this->runReconcile());

        $result = ApplicationContext::getContainer()
            ->get(TransferFunds::class)
            ->execute(new TransferFundsInput(
                $payerUserId,
                $payeeUserId,
                Money::fromCents(1000),
            ));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame(99001, $this->balanceOf($payerWalletId));
        $this->assertSame(51000, $this->balanceOf($payeeWalletId));
        $this->assertSame(1, (int) Db::table('transfers')->count());
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} payerWalletId, payeeWalletId, payerUserId, payeeUserId
     */
    private function seedBalancedWallets(): array
    {
        $payerUserId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeUserId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerUserId, 100000);
        $payeeWalletId = $this->insertWallet($payeeUserId, 50000);
        (new OpeningLedgerBackfill())->run();

        return [$payerWalletId, $payeeWalletId, $payerUserId, $payeeUserId];
    }

    private function runReconcile(?LoggerInterface $logger = null): int
    {
        $container = ApplicationContext::getContainer();
        $command = $logger === null
            ? $container->get(LedgerReconcileCommand::class)
            : new LedgerReconcileCommand($container->get(ReconcileLedger::class), $logger);

        return $command->run(new ArrayInput([]), new NullOutput());
    }
}
