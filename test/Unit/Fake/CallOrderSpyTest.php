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

namespace HyperfTest\Unit\Fake;

use App\Domain\Money;
use App\Domain\OutboxEventType;
use App\Domain\Transfer;
use DateTimeImmutable;
use HyperfTest\Fake\FakeOutbox;
use HyperfTest\Fake\FakeTransactionRunner;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Positive controls for POOL-03/04 spies: the in-txn / in-claim window
 * actually opens, and the counters increment when HTTP runs inside it.
 *
 * @internal
 * @coversNothing
 */
final class CallOrderSpyTest extends TestCase
{
    public function testInRunIsTrueOnlyForTheDurationOfRun(): void
    {
        $runner = new FakeTransactionRunner();
        $inside = false;

        $runner->run(function () use ($runner, &$inside): string {
            $inside = $runner->inRun;

            return 'ok';
        });

        $this->assertTrue($inside);
        $this->assertSame(1, $runner->peakInRun);
        $this->assertFalse($runner->inRun);
        $this->assertSame(1, $runner->runs);
    }

    public function testInRunClearsWhenTheOperationThrows(): void
    {
        $runner = new FakeTransactionRunner();
        $inside = false;

        try {
            $runner->run(function () use ($runner, &$inside): void {
                $inside = $runner->inRun;
                throw new RuntimeException('operation failed');
            });
            $this->fail('Expected RuntimeException from the operation');
        } catch (RuntimeException $thrown) {
            $this->assertSame('operation failed', $thrown->getMessage());
        }

        $this->assertTrue($inside);
        $this->assertFalse($runner->inRun);
    }

    public function testAuthorizeWhileInRunIncrementsWhenAuthorizeRunsInsideRun(): void
    {
        $runner = new FakeTransactionRunner();
        $authorizer = new FakeTransferAuthorizer();
        $authorizer->transactionRunner = $runner;
        $transfer = new Transfer(1, 11, 22, Money::fromCents(2550), new DateTimeImmutable());

        $runner->run(function () use ($authorizer, $transfer): void {
            $authorizer->authorize($transfer);
        });

        $this->assertSame(1, $authorizer->authorizeWhileInRun);
        $this->assertSame(1, $runner->peakInRun);
    }

    public function testNotifyWhileClaimingIncrementsWhenNotifyRunsInsideClaimDue(): void
    {
        $outbox = new FakeOutbox();
        $notifier = new FakeTransferNotifier();
        $notifier->outbox = $outbox;
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            42,
            [
                'transfer_id' => 42,
                'payer_wallet_id' => 11,
                'payee_wallet_id' => 22,
                'amount_cents' => 2550,
            ],
            $now,
        );

        $outbox->inClaim = true;
        $notifier->notify(new Transfer(42, 11, 22, Money::fromCents(2550), $now));
        $outbox->inClaim = false;

        $this->assertSame(1, $notifier->notifyWhileClaiming);

        $outbox->claimDue(1, $now);
        $this->assertSame(1, $outbox->peakInClaim);
        $this->assertFalse($outbox->inClaim);
    }
}
