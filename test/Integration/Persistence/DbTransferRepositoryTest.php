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

namespace HyperfTest\Integration\Persistence;

use App\Domain\Money;
use App\Domain\Transfer;
use App\Infrastructure\Persistence\DbTransferRepository;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
final class DbTransferRepositoryTest extends IntegrationTestCase
{
    public function testAddReturnsTheTransferCarryingTheIdItWasStoredUnder(): void
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerId, 100000);
        $payeeWalletId = $this->insertWallet($payeeId, 50000);

        $persisted = (new DbTransferRepository())->add(new Transfer(
            null,
            $payerWalletId,
            $payeeWalletId,
            Money::fromCents(10050),
            new DateTimeImmutable('2026-01-02 03:04:05'),
        ));

        $this->assertNotNull($persisted->id);
        $this->assertSame($payerWalletId, $persisted->payerWalletId);
        $this->assertSame($payeeWalletId, $persisted->payeeWalletId);
        $this->assertSame(10050, $persisted->amount->cents());
        $this->assertSame('2026-01-02 03:04:05', $persisted->createdAt->format('Y-m-d H:i:s'));

        $row = Db::table('transfers')->where('id', $persisted->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($payerWalletId, (int) $row->payer_wallet_id);
        $this->assertSame($payeeWalletId, (int) $row->payee_wallet_id);
        $this->assertSame(10050, (int) $row->amount_cents);
        $this->assertSame('2026-01-02 03:04:05', $row->created_at);
    }

    public function testEachAddStoresItsOwnRowUnderItsOwnId(): void
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerId, 100000);
        $payeeWalletId = $this->insertWallet($payeeId, 50000);
        $repository = new DbTransferRepository();

        $first = $repository->add(new Transfer(null, $payerWalletId, $payeeWalletId, Money::fromCents(100), new DateTimeImmutable('2026-01-02 03:04:05')));
        $second = $repository->add(new Transfer(null, $payerWalletId, $payeeWalletId, Money::fromCents(200), new DateTimeImmutable('2026-01-02 03:04:06')));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Db::table('transfers')->count());
        $this->assertSame(100, (int) Db::table('transfers')->where('id', $first->id)->value('amount_cents'));
        $this->assertSame(200, (int) Db::table('transfers')->where('id', $second->id)->value('amount_cents'));
    }
}
