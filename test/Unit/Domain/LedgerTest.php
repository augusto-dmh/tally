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

namespace HyperfTest\Unit\Domain;

use App\Domain\AccountKind;
use App\Domain\LedgerDirection;
use App\Domain\Money;
use DateTimeImmutable;
use HyperfTest\Fake\FakeLedger;
use PHPUnit\Framework\TestCase;

/**
 * Spec anchors: LEDG-01 (balanced transfer journal), LEDG-02 (append-only),
 * LEDG-05 (opening journal API surface via FakeLedger).
 *
 * @internal
 * @coversNothing
 */
class LedgerTest extends TestCase
{
    public function testAppendTransferLegsPostsABalancedDebitCreditPair(): void
    {
        $ledger = new FakeLedger();
        $createdAt = new DateTimeImmutable('2026-08-07T12:00:00+00:00');

        $ledger->appendTransferLegs(
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            42,
            1,
            2,
            Money::fromCents(15050),
            $createdAt,
        );

        $this->assertCount(2, $ledger->entries);

        $debit = $ledger->entries[0];
        $credit = $ledger->entries[1];

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $debit['journal_id']);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $credit['journal_id']);
        $this->assertSame(42, $debit['transfer_id']);
        $this->assertSame(42, $credit['transfer_id']);
        $this->assertSame(15050, $debit['amount_cents']);
        $this->assertSame(15050, $credit['amount_cents']);
        $this->assertSame(LedgerDirection::Debit, $debit['direction']);
        $this->assertSame(1, $debit['wallet_id']);
        $this->assertSame(AccountKind::Wallet, $debit['account_kind']);
        $this->assertSame(LedgerDirection::Credit, $credit['direction']);
        $this->assertSame(2, $credit['wallet_id']);
        $this->assertSame(AccountKind::Wallet, $credit['account_kind']);
        $this->assertEquals($createdAt, $debit['created_at']);
        $this->assertEquals($createdAt, $credit['created_at']);

        $this->assertSame(15050, $ledger->totalCreditsCents());
        $this->assertSame(15050, $ledger->totalDebitsCents());
        $this->assertSame([1 => -15050, 2 => 15050], $ledger->walletNetsCents());
    }

    public function testAppendsAccumulateWithoutReplacingEarlierLegs(): void
    {
        $ledger = new FakeLedger();
        $createdAt = new DateTimeImmutable('2026-08-07T12:00:00+00:00');

        $ledger->appendTransferLegs('journal-1', 1, 1, 2, Money::fromCents(100), $createdAt);
        $firstPair = $ledger->entries;

        $ledger->appendTransferLegs('journal-2', 2, 2, 1, Money::fromCents(40), $createdAt);

        $this->assertCount(4, $ledger->entries);
        $this->assertSame($firstPair[0], $ledger->entries[0]);
        $this->assertSame($firstPair[1], $ledger->entries[1]);
        $this->assertSame(140, $ledger->totalCreditsCents());
        $this->assertSame(140, $ledger->totalDebitsCents());
        $this->assertSame([1 => -60, 2 => 60], $ledger->walletNetsCents());
    }

    public function testAppendOpeningLegsPostsWalletCreditAndSystemOpeningDebit(): void
    {
        $ledger = new FakeLedger();
        $createdAt = new DateTimeImmutable('2026-08-07T12:00:00+00:00');

        $ledger->appendOpeningLegs(
            'opening-journal-uuid',
            7,
            Money::fromCents(25000),
            $createdAt,
        );

        $this->assertCount(2, $ledger->entries);

        $walletLeg = $ledger->entries[0];
        $systemLeg = $ledger->entries[1];

        $this->assertSame('opening-journal-uuid', $walletLeg['journal_id']);
        $this->assertSame('opening-journal-uuid', $systemLeg['journal_id']);
        $this->assertNull($walletLeg['transfer_id']);
        $this->assertNull($systemLeg['transfer_id']);
        $this->assertSame(25000, $walletLeg['amount_cents']);
        $this->assertSame(25000, $systemLeg['amount_cents']);

        $this->assertSame(LedgerDirection::Credit, $walletLeg['direction']);
        $this->assertSame(7, $walletLeg['wallet_id']);
        $this->assertSame(AccountKind::Wallet, $walletLeg['account_kind']);

        $this->assertSame(LedgerDirection::Debit, $systemLeg['direction']);
        $this->assertNull($systemLeg['wallet_id']);
        $this->assertSame(AccountKind::SystemOpening, $systemLeg['account_kind']);

        $this->assertSame(25000, $ledger->totalCreditsCents());
        $this->assertSame(25000, $ledger->totalDebitsCents());
        $this->assertSame([7 => 25000], $ledger->walletNetsCents());
    }
}
