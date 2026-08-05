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

namespace HyperfTest\Integration\Concurrency;

use App\Application\TransferFunds;
use App\Application\TransferFundsInput;
use App\Domain\Exception\DomainException;
use App\Domain\Money;
use App\Domain\Port\TransferAuthorizer;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Parallel;
use Hyperf\DbConnection\Db;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Integration\IntegrationTestCase;

use function Hyperf\Support\make;

/**
 * Spec anchors: CONC-01 (no overdraw under concurrent depleting transfers) and
 * CONC-02 (this race fails if SELECT … FOR UPDATE is dropped — verifier sensor).
 * Also covers concurrent credits to one payee (no lost update on the credit side).
 *
 * Depleting race: two coroutines each take the payer's full balance toward
 * different payees. With row locks at most one debit commits; without them a
 * lost update can credit both payees while the payer ends at zero.
 *
 * @internal
 * @coversNothing
 */
final class ConcurrentTransferTest extends IntegrationTestCase
{
    private const STARTING_BALANCE_CENTS = 10000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizer()->authorizes = true;
    }

    public function testConcurrentFullBalanceTransfersCannotOverdrawThePayer(): void
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeAId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payeeBId = $this->insertUser('33333333333', 'common', 'Carla Dias', 'carla@tally.test');

        $payerWalletId = $this->insertWallet($payerId, self::STARTING_BALANCE_CENTS);
        $payeeAWalletId = $this->insertWallet($payeeAId, 0);
        $payeeBWalletId = $this->insertWallet($payeeBId, 0);

        $startingTotal = self::STARTING_BALANCE_CENTS
            + $this->balanceOf($payeeAWalletId)
            + $this->balanceOf($payeeBWalletId);

        $amount = Money::fromCents(self::STARTING_BALANCE_CENTS);
        $transferFunds = make(TransferFunds::class);

        $parallel = new Parallel(2);
        $parallel->add(
            fn (): int => $this->attemptTransfer($transferFunds, $payerId, $payeeAId, $amount)
        );
        $parallel->add(
            fn (): int => $this->attemptTransfer($transferFunds, $payerId, $payeeBId, $amount)
        );
        /** @var list<int> $statuses */
        $statuses = $parallel->wait();

        $successCount = count(array_filter($statuses, static fn (int $status): bool => $status === 201));
        $payerBalance = $this->balanceOf($payerWalletId);
        $payeeABalance = $this->balanceOf($payeeAWalletId);
        $payeeBBalance = $this->balanceOf($payeeBWalletId);
        $successfulDebitSum = (int) Db::table('transfers')->sum('amount_cents');

        $this->assertContainsOnly('int', $statuses);
        $this->assertLessThanOrEqual(1, $successCount);
        $this->assertGreaterThanOrEqual(1, $successCount);
        $this->assertGreaterThanOrEqual(0, $payerBalance);
        $this->assertLessThanOrEqual(self::STARTING_BALANCE_CENTS, $successfulDebitSum);
        $this->assertSame(self::STARTING_BALANCE_CENTS, $successfulDebitSum);
        $this->assertSame(0, $payerBalance);
        $this->assertSame(
            $startingTotal,
            $payerBalance + $payeeABalance + $payeeBBalance,
            'Concurrent successes must not create money across payer and payees.'
        );
        $this->assertSame(
            self::STARTING_BALANCE_CENTS,
            $payeeABalance + $payeeBBalance,
            'Exactly one payee receives the full starting balance.'
        );
        $this->assertSame(1, Db::table('transfers')->count());
    }

    /**
     * Spec edge: concurrent credits to the same payee must not lose an update.
     * Two payers each debit a successful amount toward one shared payee.
     */
    public function testConcurrentCreditsToTheSamePayeeAreNotLost(): void
    {
        $creditCents = 4000;

        $payerAId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payerBId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payeeId = $this->insertUser('33333333333', 'common', 'Carla Dias', 'carla@tally.test');

        $payerAWalletId = $this->insertWallet($payerAId, self::STARTING_BALANCE_CENTS);
        $payerBWalletId = $this->insertWallet($payerBId, self::STARTING_BALANCE_CENTS);
        $payeeWalletId = $this->insertWallet($payeeId, 0);

        $startingTotal = self::STARTING_BALANCE_CENTS
            + self::STARTING_BALANCE_CENTS
            + $this->balanceOf($payeeWalletId);

        $amount = Money::fromCents($creditCents);
        $transferFunds = make(TransferFunds::class);

        $parallel = new Parallel(2);
        $parallel->add(
            fn (): int => $this->attemptTransfer($transferFunds, $payerAId, $payeeId, $amount)
        );
        $parallel->add(
            fn (): int => $this->attemptTransfer($transferFunds, $payerBId, $payeeId, $amount)
        );
        /** @var list<int> $statuses */
        $statuses = $parallel->wait();

        $successCount = count(array_filter($statuses, static fn (int $status): bool => $status === 201));
        $payerABalance = $this->balanceOf($payerAWalletId);
        $payerBBalance = $this->balanceOf($payerBWalletId);
        $payeeBalance = $this->balanceOf($payeeWalletId);
        $successfulCreditSum = (int) Db::table('transfers')->sum('amount_cents');

        $this->assertContainsOnly('int', $statuses);
        $this->assertSame(2, $successCount);
        $this->assertSame(2, Db::table('transfers')->count());
        $this->assertSame(2 * $creditCents, $successfulCreditSum);
        $this->assertSame(
            $creditCents,
            self::STARTING_BALANCE_CENTS - $payerABalance,
            'Payer A must debit exactly one successful credit.'
        );
        $this->assertSame(
            $creditCents,
            self::STARTING_BALANCE_CENTS - $payerBBalance,
            'Payer B must debit exactly one successful credit.'
        );
        $this->assertSame(
            $successfulCreditSum,
            $payeeBalance,
            'Payee balance must equal the sum of successful credits (no lost update).'
        );
        $this->assertSame(
            $startingTotal,
            $payerABalance + $payerBBalance + $payeeBalance,
            'Concurrent credits must conserve money across both payers and the payee.'
        );
    }

    private function attemptTransfer(
        TransferFunds $transferFunds,
        int $payerId,
        int $payeeId,
        Money $amount,
    ): int {
        try {
            $result = $transferFunds->execute(new TransferFundsInput($payerId, $payeeId, $amount));

            return $result->statusCode;
        } catch (DomainException) {
            return 422;
        }
    }

    private function authorizer(): FakeTransferAuthorizer
    {
        return ApplicationContext::getContainer()->get(TransferAuthorizer::class);
    }
}
