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

namespace App\Application;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\IdempotencyDuplicateKey;
use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\Exception\InsufficientBalance;
use App\Domain\Exception\InvalidAmount;
use App\Domain\Exception\MerchantCannotTransfer;
use App\Domain\Exception\NotificationFailed;
use App\Domain\Exception\SelfTransferNotAllowed;
use App\Domain\Exception\TransferUnauthorized;
use App\Domain\Exception\UserNotFound;
use App\Domain\IdempotencyRecord;
use App\Domain\Port\IdempotencyStore;
use App\Domain\Port\Ledger;
use App\Domain\Port\TransactionRunner;
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use App\Domain\Port\TransferRepository;
use App\Domain\Port\UserRepository;
use App\Domain\Port\WalletRepository;
use App\Domain\Transfer;
use App\Domain\User;
use App\Domain\Wallet;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Authorize outside the money transaction, lock wallets inside it, notify
 * best-effort after commit. Optional idempotency stores terminal outcomes.
 */
final class TransferFunds
{
    /**
     * @var array<class-string<DomainException>, array{int, string}>
     */
    private const KEYED_OUTCOMES = [
        InvalidAmount::class => [422, 'invalid_amount'],
        SelfTransferNotAllowed::class => [422, 'self_transfer_not_allowed'],
        MerchantCannotTransfer::class => [422, 'merchant_cannot_transfer'],
        InsufficientBalance::class => [422, 'insufficient_balance'],
        UserNotFound::class => [404, 'user_not_found'],
        TransferUnauthorized::class => [403, 'transfer_unauthorized'],
    ];

    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly UserRepository $userRepository,
        private readonly WalletRepository $walletRepository,
        private readonly TransferRepository $transferRepository,
        private readonly TransferAuthorizer $authorizer,
        private readonly TransferNotifier $notifier,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly Ledger $ledger,
    ) {
    }

    public function execute(TransferFundsInput $input): TransferResult
    {
        if ($input->idempotencyKey !== null) {
            $existing = $this->idempotencyStore->find($input->idempotencyKey);
            if ($existing !== null) {
                if ($existing->requestHash !== $input->requestHash) {
                    throw new IdempotencyKeyConflict(
                        'Idempotency-Key was already used with a different request body.'
                    );
                }

                return new TransferResult($existing->statusCode, $existing->responseBody);
            }
        }

        try {
            return $this->executeFresh($input);
        } catch (DomainException $exception) {
            if ($input->idempotencyKey === null) {
                throw $exception;
            }

            $result = $this->resultFromDomainException($exception);

            try {
                $this->storeTerminalOutcome($input, $result);
            } catch (IdempotencyDuplicateKey) {
                // Winner already stored the terminal outcome for this key+body.
                return $this->replayStored((string) $input->idempotencyKey);
            }

            return $result;
        }
    }

    private function executeFresh(TransferFundsInput $input): TransferResult
    {
        $payer = $this->userOrFail($input->payerId);
        $payee = $this->userOrFail($input->payeeId);

        if ($input->amount->cents() === 0) {
            throw new InvalidAmount('A transfer amount must be greater than zero.');
        }

        if ($payer->id === $payee->id) {
            throw new SelfTransferNotAllowed(sprintf('User %d cannot transfer to itself.', $payer->id));
        }

        if (! $payer->canTransfer()) {
            throw new MerchantCannotTransfer(sprintf('User %d is a merchant and merchants only receive.', $payer->id));
        }

        $payerWallet = $this->walletOrFail($payer);
        $payeeWallet = $this->walletOrFail($payee);

        if ($input->amount->cents() > $payerWallet->balance()->cents()) {
            throw new InsufficientBalance(sprintf(
                'Wallet %d holds %d cents, which cannot cover a debit of %d cents.',
                $payerWallet->id,
                $payerWallet->balance()->cents(),
                $input->amount->cents()
            ));
        }

        $draft = new Transfer(
            null,
            $payerWallet->id,
            $payeeWallet->id,
            $input->amount,
            new DateTimeImmutable(),
        );

        if (! $this->authorizer->authorize($draft)) {
            throw new TransferUnauthorized(sprintf(
                'The authorizer did not clear a transfer of %d cents from wallet %d.',
                $input->amount->cents(),
                $payerWallet->id
            ));
        }

        try {
            $committed = $this->transactionRunner->run(
                function () use ($input, $payer, $payee, $payerWallet, $payeeWallet): array {
                    [$lockedPayer, $lockedPayee] = $this->lockWalletsInIdOrder(
                        $payer,
                        $payee,
                        $payerWallet,
                        $payeeWallet,
                    );

                    $lockedPayer->debit($input->amount);
                    $lockedPayee->credit($input->amount);

                    $transfer = new Transfer(
                        null,
                        $lockedPayer->id,
                        $lockedPayee->id,
                        $input->amount,
                        new DateTimeImmutable(),
                    );

                    $this->walletRepository->save($lockedPayer);
                    $this->walletRepository->save($lockedPayee);
                    $persisted = $this->transferRepository->add($transfer);

                    $this->ledger->appendTransferLegs(
                        $this->newJournalId(),
                        $persisted->id,
                        $lockedPayer->id,
                        $lockedPayee->id,
                        $input->amount,
                        $persisted->createdAt,
                    );

                    $output = new TransferFundsOutput(
                        $persisted->id,
                        $payer->id,
                        $payee->id,
                        $persisted->amount,
                        $persisted->createdAt,
                    );
                    $result = new TransferResult(201, $this->successBody($output), $output);

                    if ($input->idempotencyKey !== null) {
                        $this->idempotencyStore->save(new IdempotencyRecord(
                            $input->idempotencyKey,
                            (string) $input->requestHash,
                            $result->statusCode,
                            $result->body,
                            new DateTimeImmutable(),
                        ));
                    }

                    return ['result' => $result, 'transfer' => $persisted];
                }
            );
        } catch (IdempotencyDuplicateKey) {
            return $this->replayStored((string) $input->idempotencyKey);
        }

        try {
            $this->notifier->notify($committed['transfer']);
        } catch (NotificationFailed) {
            // Best-effort: money is already committed.
        }

        return $committed['result'];
    }

    /**
     * @return array{0: Wallet, 1: Wallet} payer then payee, after FOR UPDATE in ascending wallet id order
     */
    private function lockWalletsInIdOrder(User $payer, User $payee, Wallet $payerWallet, Wallet $payeeWallet): array
    {
        $order = [
            $payerWallet->id => $payer->id,
            $payeeWallet->id => $payee->id,
        ];
        ksort($order);

        $lockedByUserId = [];
        foreach ($order as $userId) {
            $wallet = $this->walletRepository->findByUserIdForUpdate($userId);
            if ($wallet === null) {
                throw new UserNotFound(sprintf('User %d has no wallet.', $userId));
            }
            $lockedByUserId[$userId] = $wallet;
        }

        return [$lockedByUserId[$payer->id], $lockedByUserId[$payee->id]];
    }

    private function storeTerminalOutcome(TransferFundsInput $input, TransferResult $result): void
    {
        $this->idempotencyStore->save(new IdempotencyRecord(
            (string) $input->idempotencyKey,
            (string) $input->requestHash,
            $result->statusCode,
            $result->body,
            new DateTimeImmutable(),
        ));
    }

    private function replayStored(string $key): TransferResult
    {
        $existing = $this->idempotencyStore->find($key);
        if ($existing === null) {
            throw new IdempotencyKeyConflict(
                'Idempotency key race lost but no stored outcome was found.'
            );
        }

        return new TransferResult($existing->statusCode, $existing->responseBody);
    }

    private function resultFromDomainException(DomainException $exception): TransferResult
    {
        [$status, $error] = self::KEYED_OUTCOMES[$exception::class]
            ?? [422, 'invalid_request'];

        return new TransferResult($status, [
            'error' => $error,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array{id: int, payer: int, payee: int, value: string, created_at: string}
     */
    private function successBody(TransferFundsOutput $output): array
    {
        return [
            'id' => $output->id,
            'payer' => $output->payerId,
            'payee' => $output->payeeId,
            'value' => sprintf('%d.%02d', intdiv($output->amount->cents(), 100), $output->amount->cents() % 100),
            'created_at' => $output->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    private function newJournalId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function userOrFail(int $id): User
    {
        $user = $this->userRepository->findById($id);

        if ($user === null) {
            throw new UserNotFound(sprintf('There is no user %d.', $id));
        }

        return $user;
    }

    /**
     * A user without a wallet cannot take part in a transfer; the schema makes
     * it impossible, so it is treated as the party not existing.
     */
    private function walletOrFail(User $user): Wallet
    {
        $wallet = $this->walletRepository->findByUserId($user->id);

        if ($wallet === null) {
            throw new UserNotFound(sprintf('User %d has no wallet.', $user->id));
        }

        return $wallet;
    }
}
