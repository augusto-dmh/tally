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

namespace HyperfTest\Unit\Application;

use App\Application\TransferFunds;
use App\Application\TransferFundsInput;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InsufficientBalance;
use App\Domain\Exception\InvalidAmount;
use App\Domain\Exception\MerchantCannotTransfer;
use App\Domain\Exception\NotificationFailed;
use App\Domain\Exception\SelfTransferNotAllowed;
use App\Domain\Exception\TransferUnauthorized;
use App\Domain\Exception\UserNotFound;
use App\Domain\Money;
use App\Domain\User;
use App\Domain\UserType;
use App\Domain\Wallet;
use DateTimeImmutable;
use HyperfTest\Fake\FakeTransactionRunner;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;
use HyperfTest\Fake\FakeTransferRepository;
use HyperfTest\Fake\FakeUserRepository;
use HyperfTest\Fake\FakeWalletRepository;
use PHPUnit\Framework\TestCase;

/**
 * Seeded scenario shared by every test: user 1 is a common payer holding
 * 10050 cents in wallet 11, user 2 a common payee holding 5000 in wallet 22,
 * user 3 a merchant holding 700 in wallet 33, and user 4 a common user with
 * no wallet at all.
 *
 * @internal
 * @coversNothing
 */
class TransferFundsTest extends TestCase
{
    private FakeUserRepository $users;

    private FakeWalletRepository $wallets;

    private FakeTransferRepository $transfers;

    private FakeTransferAuthorizer $authorizer;

    private FakeTransferNotifier $notifier;

    private FakeTransactionRunner $runner;

    private TransferFunds $transferFunds;

    protected function setUp(): void
    {
        $this->users = new FakeUserRepository();
        $this->users->add($this->user(1, UserType::Common));
        $this->users->add($this->user(2, UserType::Common));
        $this->users->add($this->user(3, UserType::Merchant));
        $this->users->add($this->user(4, UserType::Common));

        $this->wallets = new FakeWalletRepository();
        $this->wallets->save(new Wallet(11, 1, Money::fromCents(10050)));
        $this->wallets->save(new Wallet(22, 2, Money::fromCents(5000)));
        $this->wallets->save(new Wallet(33, 3, Money::fromCents(700)));

        $this->transfers = new FakeTransferRepository();
        $this->authorizer = new FakeTransferAuthorizer();
        $this->notifier = new FakeTransferNotifier();
        $this->runner = new FakeTransactionRunner();

        $this->transferFunds = new TransferFunds(
            $this->runner,
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->authorizer,
            $this->notifier,
        );
    }

    public function testItMovesTheExactAmountBetweenTwoCommonUsers(): void
    {
        $before = new DateTimeImmutable();

        $output = $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(7550, $this->wallets->walletsByUserId[2]->balance()->cents());

        $this->assertCount(1, $this->transfers->added);
        $transfer = $this->transfers->added[0];
        $this->assertSame(11, $transfer->payerWalletId);
        $this->assertSame(22, $transfer->payeeWalletId);
        $this->assertSame(2550, $transfer->amount->cents());
        $this->assertGreaterThanOrEqual($before, $transfer->createdAt);
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $transfer->createdAt);

        $this->assertSame($transfer->id, $output->id);
        $this->assertSame(1, $output->payerId);
        $this->assertSame(2, $output->payeeId);
        $this->assertSame(2550, $output->amount->cents());
        $this->assertEquals($transfer->createdAt, $output->createdAt);

        $this->assertSame(1, $this->runner->runs);
    }

    public function testItMovesMoneyToAMerchantPayee(): void
    {
        $output = $this->transferFunds->execute(new TransferFundsInput(1, 3, Money::fromCents(2550)));

        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(3250, $this->wallets->walletsByUserId[3]->balance()->cents());

        $this->assertCount(1, $this->transfers->added);
        $this->assertSame(11, $this->transfers->added[0]->payerWalletId);
        $this->assertSame(33, $this->transfers->added[0]->payeeWalletId);
        $this->assertSame(2550, $this->transfers->added[0]->amount->cents());

        $this->assertSame(3, $output->payeeId);
        $this->assertSame(2550, $output->amount->cents());
    }

    public function testItMovesASingleCent(): void
    {
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(1)));

        $this->assertSame(10049, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(5001, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertSame(1, $this->transfers->added[0]->amount->cents());
    }

    public function testItMovesTheWholeBalanceLeavingThePayerAtZero(): void
    {
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(10050)));

        $this->assertSame(0, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(15050, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertSame(10050, $this->transfers->added[0]->amount->cents());
    }

    public function testItAsksTheAuthorizerToClearTheTransferBeforeItIsPersisted(): void
    {
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertCount(1, $this->authorizer->authorized);
        $this->assertSame(11, $this->authorizer->authorized[0]->payerWalletId);
        $this->assertSame(22, $this->authorizer->authorized[0]->payeeWalletId);
        $this->assertSame(2550, $this->authorizer->authorized[0]->amount->cents());
    }

    public function testItNotifiesThePayeeExactlyOnceWithThePersistedTransfer(): void
    {
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertCount(1, $this->notifier->notified);
        $this->assertSame($this->transfers->added[0]->id, $this->notifier->notified[0]->id);
        $this->assertSame(22, $this->notifier->notified[0]->payeeWalletId);
        $this->assertSame(2550, $this->notifier->notified[0]->amount->cents());
    }

    public function testItRejectsATransferToTheSameUser(): void
    {
        $this->executeExpecting(SelfTransferNotAllowed::class, new TransferFundsInput(1, 1, Money::fromCents(2550)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAMerchantPayer(): void
    {
        $this->executeExpecting(MerchantCannotTransfer::class, new TransferFundsInput(3, 2, Money::fromCents(500)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAZeroAmount(): void
    {
        $this->executeExpecting(InvalidAmount::class, new TransferFundsInput(1, 2, Money::fromCents(0)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAnAmountAboveThePayerBalance(): void
    {
        $this->executeExpecting(InsufficientBalance::class, new TransferFundsInput(1, 2, Money::fromCents(10051)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAnUnknownPayer(): void
    {
        $this->executeExpecting(UserNotFound::class, new TransferFundsInput(99, 2, Money::fromCents(2550)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAnUnknownPayee(): void
    {
        $this->executeExpecting(UserNotFound::class, new TransferFundsInput(1, 99, Money::fromCents(2550)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAPayerWithoutAWallet(): void
    {
        $this->executeExpecting(UserNotFound::class, new TransferFundsInput(4, 2, Money::fromCents(2550)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsAPayeeWithoutAWallet(): void
    {
        $this->executeExpecting(UserNotFound::class, new TransferFundsInput(1, 4, Money::fromCents(2550)));

        $this->assertNothingMovedAndNoOutwardCall();
    }

    public function testItRejectsADeclinedTransferWithoutMovingMoney(): void
    {
        $this->authorizer->authorizes = false;

        $this->executeExpecting(TransferUnauthorized::class, new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertCount(1, $this->authorizer->authorized);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->notifier->notified);
    }

    public function testItLetsANotifierFailureEscapeTheTransaction(): void
    {
        $this->notifier->fails = true;

        $this->executeExpecting(NotificationFailed::class, new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertCount(1, $this->notifier->notified);
    }

    private function user(int $id, UserType $type): User
    {
        return new User(
            $id,
            'User ' . $id,
            str_pad((string) $id, 11, '0', STR_PAD_LEFT),
            sprintf('user%d@tally.test', $id),
            $type,
        );
    }

    /**
     * Runs the use case expecting it to fail, and proves the failure crossed
     * the transaction boundary — which is what rolls the transfer back.
     *
     * @param class-string<DomainException> $expected
     */
    private function executeExpecting(string $expected, TransferFundsInput $input): void
    {
        try {
            $this->transferFunds->execute($input);
            $this->fail('Executing the transfer should have thrown ' . $expected);
        } catch (DomainException $thrown) {
            $this->assertInstanceOf($expected, $thrown);
            $this->assertSame($thrown, $this->runner->thrown);
        }
    }

    private function assertNothingMovedAndNoOutwardCall(): void
    {
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame([], $this->notifier->notified);
    }

    private function assertSeededBalances(): void
    {
        $this->assertSame(10050, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(5000, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertSame(700, $this->wallets->walletsByUserId[3]->balance()->cents());
    }
}
