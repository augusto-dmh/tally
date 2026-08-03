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

use App\Domain\Exception\InvalidAmount;
use App\Domain\Exception\MerchantCannotTransfer;
use App\Domain\Exception\SelfTransferNotAllowed;
use App\Domain\Exception\TransferUnauthorized;
use App\Domain\Exception\UserNotFound;
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

final class TransferFunds
{
    public function __construct(
        private readonly TransactionRunner $transactionRunner,
        private readonly UserRepository $userRepository,
        private readonly WalletRepository $walletRepository,
        private readonly TransferRepository $transferRepository,
        private readonly TransferAuthorizer $authorizer,
        private readonly TransferNotifier $notifier,
    ) {
    }

    /**
     * Runs the whole flow — authorization and notification included — inside
     * the transaction, so anything that throws leaves both balances and the
     * transfers table untouched. For now the flow accepts holding a connection while
     * two external calls happen (AD-003).
     */
    public function execute(TransferFundsInput $input): TransferFundsOutput
    {
        return $this->transactionRunner->run(function () use ($input): TransferFundsOutput {
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

            $payerWallet->debit($input->amount);
            $payeeWallet->credit($input->amount);

            $transfer = new Transfer(
                null,
                $payerWallet->id,
                $payeeWallet->id,
                $input->amount,
                new DateTimeImmutable(),
            );

            if (! $this->authorizer->authorize($transfer)) {
                throw new TransferUnauthorized(sprintf(
                    'The authorizer did not clear a transfer of %d cents from wallet %d.',
                    $input->amount->cents(),
                    $payerWallet->id
                ));
            }

            $this->walletRepository->save($payerWallet);
            $this->walletRepository->save($payeeWallet);
            $persisted = $this->transferRepository->add($transfer);

            $this->notifier->notify($persisted);

            return new TransferFundsOutput(
                $persisted->id,
                $payer->id,
                $payee->id,
                $persisted->amount,
                $persisted->createdAt,
            );
        });
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
