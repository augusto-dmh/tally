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

namespace HyperfTest\Fake;

use App\Domain\Port\WalletRepository;
use App\Domain\Wallet;

/**
 * Stores wallets the way a database does: reads hydrate a fresh entity and
 * writes only land through save(), so unsaved in-memory debits and credits
 * stay invisible to whoever inspects the stored state.
 */
final class FakeWalletRepository implements WalletRepository
{
    /** @var array<int, Wallet> */
    public array $walletsByUserId = [];

    public function findByUserId(int $userId): ?Wallet
    {
        $stored = $this->walletsByUserId[$userId] ?? null;

        return $stored === null ? null : $this->copyOf($stored);
    }

    public function save(Wallet $wallet): void
    {
        $this->walletsByUserId[$wallet->userId] = $this->copyOf($wallet);
    }

    private function copyOf(Wallet $wallet): Wallet
    {
        return new Wallet($wallet->id, $wallet->userId, $wallet->balance());
    }
}
