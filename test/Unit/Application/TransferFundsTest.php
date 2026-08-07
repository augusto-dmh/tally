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
use App\Domain\Exception\IdempotencyDuplicateKey;
use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\Exception\InsufficientBalance;
use App\Domain\Exception\InvalidAmount;
use App\Domain\Exception\MerchantCannotTransfer;
use App\Domain\Exception\SelfTransferNotAllowed;
use App\Domain\Exception\TransferUnauthorized;
use App\Domain\Exception\UserNotFound;
use App\Domain\IdempotencyRecord;
use App\Domain\LedgerDirection;
use App\Domain\Money;
use App\Domain\Port\IdempotencyStore;
use App\Domain\Port\TransferRepository;
use App\Domain\Transfer;
use App\Domain\User;
use App\Domain\UserType;
use App\Domain\Wallet;
use DateTimeImmutable;
use HyperfTest\Fake\FakeIdempotencyStore;
use HyperfTest\Fake\FakeLedger;
use HyperfTest\Fake\FakeTransactionRunner;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;
use HyperfTest\Fake\FakeTransferRepository;
use HyperfTest\Fake\FakeUserRepository;
use HyperfTest\Fake\FakeWalletRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Seeded scenario shared by every test: user 1 is a common payer holding
 * 10050 cents in wallet 11, user 2 a common payee holding 5000 in wallet 22,
 * user 3 a merchant holding 700 in wallet 33, and user 4 a common user with
 * no wallet at all.
 *
 * Spec anchors: CONC-03 (authorize decline persists nothing), CONC-04 (notify
 * fail after commit still succeeds), CONC-05/06/07 (idempotent replay, key
 * conflict, no-key independence).
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

    private FakeIdempotencyStore $idempotency;

    private FakeLedger $ledger;

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
        $this->idempotency = new FakeIdempotencyStore();
        $this->ledger = new FakeLedger();

        $this->transferFunds = new TransferFunds(
            $this->runner,
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->authorizer,
            $this->notifier,
            $this->idempotency,
            $this->ledger,
        );
    }

    public function testItMovesTheExactAmountBetweenTwoCommonUsers(): void
    {
        $before = new DateTimeImmutable();

        $result = $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(7550, $this->wallets->walletsByUserId[2]->balance()->cents());

        $this->assertCount(1, $this->transfers->added);
        $transfer = $this->transfers->added[0];
        $this->assertSame(11, $transfer->payerWalletId);
        $this->assertSame(22, $transfer->payeeWalletId);
        $this->assertSame(2550, $transfer->amount->cents());
        $this->assertGreaterThanOrEqual($before, $transfer->createdAt);
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $transfer->createdAt);

        $output = $result->output;
        $this->assertNotNull($output);
        $this->assertSame($transfer->id, $output->id);
        $this->assertSame(1, $output->payerId);
        $this->assertSame(2, $output->payeeId);
        $this->assertSame(2550, $output->amount->cents());
        $this->assertEquals($transfer->createdAt, $output->createdAt);

        $this->assertSame(1, $this->runner->runs);
        $this->assertSame([1, 2], $this->wallets->forUpdateUserIds);
        $this->assertTransferLegsPostedOnce(11, 22, 2550, $transfer->id);
    }

    /** LEDG-01: successful transfer posts opposing debit/credit legs once. */
    public function testItPostsBalancedLedgerLegsOnSuccessfulTransfer(): void
    {
        $result = $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertSame(201, $result->statusCode);
        $this->assertTransferLegsPostedOnce(11, 22, 2550, $this->transfers->added[0]->id);
    }

    /** LEDG-03: authorizer decline never reaches the money txn — no ledger append. */
    public function testItDoesNotAppendLedgerLegsWhenAuthorizerDeclines(): void
    {
        $this->authorizer->authorizes = false;

        $this->executeExpecting(TransferUnauthorized::class, new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertSame([], $this->ledger->entries);
        $this->assertSame(0, $this->runner->runs);
        $this->assertSame([], $this->transfers->added);
    }

    /** LEDG-08: keyed replay returns stored outcome without appending again. */
    public function testItDoesNotAppendLedgerLegsOnKeyedReplay(): void
    {
        $first = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-ledger-replay',
            'hash-ledger-replay',
        ));

        $this->assertSame(201, $first->statusCode);
        $this->assertCount(2, $this->ledger->entries);

        $second = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-ledger-replay',
            'hash-ledger-replay',
        ));

        $this->assertSame(201, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertCount(1, $this->transfers->added);
        $this->assertCount(2, $this->ledger->entries);
    }

    /**
     * LEDG-03: mid-txn failure before append leaves FakeLedger empty
     * (FakeTransactionRunner has no ledger rollback coupling).
     */
    public function testItDoesNotLeaveLedgerEntriesWhenTransferPersistFailsMidTxn(): void
    {
        $transfers = new class implements TransferRepository {
            public function add(Transfer $transfer): Transfer
            {
                throw new RuntimeException('transfer persist failed mid-txn');
            }
        };

        $transferFunds = new TransferFunds(
            $this->runner,
            $this->users,
            $this->wallets,
            $transfers,
            $this->authorizer,
            $this->notifier,
            $this->idempotency,
            $this->ledger,
        );

        try {
            $transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));
            $this->fail('Expected RuntimeException from mid-txn transfer persist');
        } catch (RuntimeException $thrown) {
            $this->assertSame('transfer persist failed mid-txn', $thrown->getMessage());
            $this->assertSame($thrown, $this->runner->thrown);
        }

        $this->assertSame([], $this->ledger->entries);
        $this->assertSame(1, $this->runner->runs);
    }

    public function testItMovesMoneyToAMerchantPayee(): void
    {
        $result = $this->transferFunds->execute(new TransferFundsInput(1, 3, Money::fromCents(2550)));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(3250, $this->wallets->walletsByUserId[3]->balance()->cents());

        $this->assertCount(1, $this->transfers->added);
        $this->assertSame(11, $this->transfers->added[0]->payerWalletId);
        $this->assertSame(33, $this->transfers->added[0]->payeeWalletId);
        $this->assertSame(2550, $this->transfers->added[0]->amount->cents());

        $this->assertNotNull($result->output);
        $this->assertSame(3, $result->output->payeeId);
        $this->assertSame(2550, $result->output->amount->cents());
        $this->assertSame([1, 3], $this->wallets->forUpdateUserIds);
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
        $this->assertSame(11, $this->authorizer->authorized[0]->payerWalletId);
        $this->assertSame(22, $this->authorizer->authorized[0]->payeeWalletId);
        $this->assertSame(0, $this->runner->runs);
        $this->assertSame([], $this->wallets->forUpdateUserIds);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->notifier->notified);
        $this->assertSame([], $this->ledger->entries);
    }

    /** CONC-04: notify failure after commit must not undo the money move. */
    public function testItKeepsTheTransferWhenTheNotifierFailsAfterCommit(): void
    {
        $this->notifier->fails = true;

        $result = $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(7550, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertCount(1, $this->transfers->added);
        $this->assertCount(1, $this->notifier->notified);
        $this->assertNull($this->runner->thrown);
        $this->assertSame(1, $this->runner->runs);
    }

    /** CONC-05: same key + body replays the stored terminal outcome. */
    public function testItReplaysAStoredIdempotentSuccessWithoutSideEffects(): void
    {
        $storedBody = [
            'id' => 99,
            'payer' => 1,
            'payee' => 2,
            'value' => '25.50',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ];
        $this->idempotency->save(new IdempotencyRecord(
            'key-1',
            'hash-abc',
            201,
            $storedBody,
            new DateTimeImmutable('2026-01-01 00:00:00'),
        ));

        $result = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-1',
            'hash-abc',
        ));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($storedBody, $result->body);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame([], $this->notifier->notified);
        $this->assertSame(0, $this->runner->runs);
        $this->assertSame([], $this->ledger->entries);
    }

    /** CONC-05: a live success under a key is stored and replayed on retry. */
    public function testItStoresASuccessfulOutcomeAndReplaysItOnTheSameKey(): void
    {
        $first = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-live',
            'hash-live',
        ));

        $this->assertSame(201, $first->statusCode);
        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertCount(1, $this->transfers->added);
        $this->assertCount(1, $this->authorizer->authorized);

        $second = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-live',
            'hash-live',
        ));

        $this->assertSame(201, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertSame(7500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertCount(1, $this->transfers->added);
        $this->assertCount(1, $this->authorizer->authorized);
        $this->assertCount(1, $this->notifier->notified);
    }

    /** CONC-06: same key with a different body is a conflict. */
    public function testItRejectsTheSameIdempotencyKeyWithADifferentBody(): void
    {
        $this->idempotency->save(new IdempotencyRecord(
            'key-conflict',
            'hash-one',
            201,
            ['id' => 1],
            new DateTimeImmutable('2026-01-01 00:00:00'),
        ));

        try {
            $this->transferFunds->execute(new TransferFundsInput(
                1,
                2,
                Money::fromCents(100),
                'key-conflict',
                'hash-two',
            ));
            $this->fail('Expected IdempotencyKeyConflict');
        } catch (IdempotencyKeyConflict $thrown) {
            $this->assertInstanceOf(IdempotencyKeyConflict::class, $thrown);
        }

        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame(0, $this->runner->runs);
    }

    /** CONC-03 + keyed: authorize decline stores a terminal 403 for replay. */
    public function testItStoresAKeyedAuthorizerDeclineAndReplaysIt(): void
    {
        $this->authorizer->authorizes = false;

        $first = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-403',
            'hash-403',
        ));

        $this->assertSame(403, $first->statusCode);
        $this->assertSame('transfer_unauthorized', $first->body['error']);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame(0, $this->runner->runs);

        $this->authorizer->authorizes = true;
        $second = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-403',
            'hash-403',
        ));

        $this->assertSame(403, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertSeededBalances();
        $this->assertCount(1, $this->authorizer->authorized);
        $this->assertSame([], $this->transfers->added);
    }

    /**
     * When a keyed attempt fails locally but another worker already stored the
     * terminal outcome for the same key+body, replay the winner — do not return
     * the local failure (false 422 while money moved under that key).
     */
    public function testItReplaysTheWinnerWhenStoringAKeyedFailureHitsADuplicateKey(): void
    {
        $winnerBody = [
            'id' => 99,
            'payer' => 1,
            'payee' => 2,
            'value' => '100.50',
            'created_at' => '2026-08-04T12:00:00+00:00',
        ];
        $winner = new IdempotencyRecord(
            'key-race-fail',
            'hash-race-fail',
            201,
            $winnerBody,
            new DateTimeImmutable('2026-08-04 12:00:00'),
        );

        $store = new class ($winner) implements IdempotencyStore {
            private int $finds = 0;

            public function __construct(private readonly IdempotencyRecord $winner)
            {
            }

            public function find(string $key): ?IdempotencyRecord
            {
                // Miss on entry so executeFresh runs; hit on replayStored.
                return $this->finds++ === 0 ? null : $this->winner;
            }

            public function save(IdempotencyRecord $record): void
            {
                throw new IdempotencyDuplicateKey(
                    'Idempotency key was claimed by a concurrent request; re-read and replay.'
                );
            }
        };

        $this->authorizer->authorizes = false;
        $transferFunds = new TransferFunds(
            $this->runner,
            $this->users,
            $this->wallets,
            $this->transfers,
            $this->authorizer,
            $this->notifier,
            $store,
            $this->ledger,
        );

        $result = $transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(2550),
            'key-race-fail',
            'hash-race-fail',
        ));

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($winnerBody, $result->body);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame(0, $this->runner->runs);
        $this->assertSame([], $this->ledger->entries);
    }

    /** Keyed insufficient-balance stores a terminal 422 for replay. */
    public function testItStoresAKeyedInsufficientBalanceAndReplaysIt(): void
    {
        $first = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(10051),
            'key-422',
            'hash-422',
        ));

        $this->assertSame(422, $first->statusCode);
        $this->assertSame('insufficient_balance', $first->body['error']);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame(0, $this->runner->runs);

        $second = $this->transferFunds->execute(new TransferFundsInput(
            1,
            2,
            Money::fromCents(10051),
            'key-422',
            'hash-422',
        ));

        $this->assertSame(422, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame([], $this->notifier->notified);
        $this->assertSame(0, $this->runner->runs);
    }

    /** Keyed user-not-found stores a terminal 404 for replay. */
    public function testItStoresAKeyedUserNotFoundAndReplaysIt(): void
    {
        $first = $this->transferFunds->execute(new TransferFundsInput(
            99,
            2,
            Money::fromCents(2550),
            'key-404',
            'hash-404',
        ));

        $this->assertSame(404, $first->statusCode);
        $this->assertSame('user_not_found', $first->body['error']);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame(0, $this->runner->runs);

        $second = $this->transferFunds->execute(new TransferFundsInput(
            99,
            2,
            Money::fromCents(2550),
            'key-404',
            'hash-404',
        ));

        $this->assertSame(404, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame([], $this->notifier->notified);
        $this->assertSame(0, $this->runner->runs);
    }

    /** CONC-07: without a key each request is independent. */
    public function testItTreatsRequestsWithoutAnIdempotencyKeyAsIndependent(): void
    {
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(2550)));
        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(1000)));

        $this->assertSame(6500, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(8550, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertCount(2, $this->transfers->added);
        $this->assertCount(2, $this->authorizer->authorized);
        $this->assertCount(2, $this->notifier->notified);
    }

    /** Locks lower wallet id first even when payee wallet id is smaller. */
    public function testItLocksWalletsInAscendingIdOrder(): void
    {
        $this->wallets->save(new Wallet(5, 2, Money::fromCents(5000)));

        $this->transferFunds->execute(new TransferFundsInput(1, 2, Money::fromCents(100)));

        $this->assertSame([2, 1], $this->wallets->forUpdateUserIds);
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
     * @param class-string<DomainException> $expected
     */
    private function executeExpecting(string $expected, TransferFundsInput $input): void
    {
        try {
            $this->transferFunds->execute($input);
            $this->fail('Executing the transfer should have thrown ' . $expected);
        } catch (DomainException $thrown) {
            $this->assertInstanceOf($expected, $thrown);
            if ($this->runner->thrown !== null) {
                $this->assertSame($thrown, $this->runner->thrown);
            }
        }
    }

    private function assertNothingMovedAndNoOutwardCall(): void
    {
        $this->assertSeededBalances();
        $this->assertSame([], $this->transfers->added);
        $this->assertSame([], $this->authorizer->authorized);
        $this->assertSame([], $this->notifier->notified);
        $this->assertSame(0, $this->runner->runs);
        $this->assertSame([], $this->ledger->entries);
    }

    private function assertTransferLegsPostedOnce(
        int $payerWalletId,
        int $payeeWalletId,
        int $amountCents,
        int $transferId,
    ): void {
        $this->assertCount(2, $this->ledger->entries);

        $debit = $this->ledger->entries[0];
        $credit = $this->ledger->entries[1];

        $this->assertSame($debit['journal_id'], $credit['journal_id']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $debit['journal_id'],
        );
        $this->assertSame($transferId, $debit['transfer_id']);
        $this->assertSame($transferId, $credit['transfer_id']);
        $this->assertSame($payerWalletId, $debit['wallet_id']);
        $this->assertSame($payeeWalletId, $credit['wallet_id']);
        $this->assertSame(LedgerDirection::Debit, $debit['direction']);
        $this->assertSame(LedgerDirection::Credit, $credit['direction']);
        $this->assertSame($amountCents, $debit['amount_cents']);
        $this->assertSame($amountCents, $credit['amount_cents']);
    }

    private function assertSeededBalances(): void
    {
        $this->assertSame(10050, $this->wallets->walletsByUserId[1]->balance()->cents());
        $this->assertSame(5000, $this->wallets->walletsByUserId[2]->balance()->cents());
        $this->assertSame(700, $this->wallets->walletsByUserId[3]->balance()->cents());
    }
}
