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

use App\Domain\OutboxEventType;
use DateTimeImmutable;
use HyperfTest\Fake\FakeOutbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Spec anchors: OUTB-01 (enqueue surface), OUTB-04 (claim → processing),
 * OUTB-05/OUTB-11 (done retained), OUTB-06 (retry backoff), OUTB-07 (dead at max),
 * OUTB-08 (unique transfer_id + event_type).
 *
 * @internal
 * @coversNothing
 */
class OutboxTest extends TestCase
{
    public function testEnqueueStoresOnePendingRowWithZeroAttempts(): void
    {
        $outbox = new FakeOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $payload = [
            'transfer_id' => 42,
            'payer_wallet_id' => 1,
            'payee_wallet_id' => 2,
            'amount_cents' => 15050,
        ];

        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            42,
            $payload,
            $createdAt,
        );

        $this->assertCount(1, $outbox->messages);
        $row = $outbox->messages[0];
        $this->assertSame(1, $row['id']);
        $this->assertSame('transfer.completed', $row['event_type']);
        $this->assertSame(42, $row['transfer_id']);
        $this->assertSame($payload, $row['payload']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame('pending', $row['status']);
        $this->assertEquals($createdAt, $row['available_at']);
        $this->assertNull($row['last_error']);
    }

    public function testEnqueueRejectsDuplicateTransferIdAndEventType(): void
    {
        $outbox = new FakeOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08T12:00:00+00:00');

        $outbox->enqueue('transfer.completed', 7, ['transfer_id' => 7], $createdAt);

        $this->expectException(RuntimeException::class);
        $outbox->enqueue('transfer.completed', 7, ['transfer_id' => 7], $createdAt);
    }

    public function testClaimDueMarksPendingRowsProcessingAndSkipsFutureAvailableAt(): void
    {
        $outbox = new FakeOutbox();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');

        $outbox->enqueue('transfer.completed', 1, ['transfer_id' => 1], $now);
        $outbox->enqueue(
            'transfer.completed',
            2,
            ['transfer_id' => 2],
            $now->modify('+10 minutes'),
        );

        $claimed = $outbox->claimDue(10, $now);

        $this->assertCount(1, $claimed);
        $this->assertSame(1, $claimed[0]->id);
        $this->assertSame('processing', $claimed[0]->status);
        $this->assertSame('processing', $outbox->messages[0]['status']);
        $this->assertSame('pending', $outbox->messages[1]['status']);
    }

    public function testClaimDueReclaimsStaleProcessingPastClaimLease(): void
    {
        $outbox = new FakeOutbox();
        $outbox->claimLeaseSeconds = 60;
        $enqueuedAt = new DateTimeImmutable('2026-08-08T12:00:00+00:00');

        $outbox->enqueue('transfer.completed', 3, ['transfer_id' => 3], $enqueuedAt);
        $outbox->claimDue(1, $enqueuedAt);

        $stillLeased = $enqueuedAt->modify('+30 seconds');
        $this->assertSame([], $outbox->claimDue(1, $stillLeased));

        $leaseExpired = $enqueuedAt->modify('+61 seconds');
        $reclaimed = $outbox->claimDue(1, $leaseExpired);

        $this->assertCount(1, $reclaimed);
        $this->assertSame(1, $reclaimed[0]->id);
        $this->assertSame('processing', $reclaimed[0]->status);
    }

    public function testMarkDoneSetsTerminalDoneWithoutDeleting(): void
    {
        $outbox = new FakeOutbox();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');

        $outbox->enqueue('transfer.completed', 4, ['transfer_id' => 4], $now);
        $claimed = $outbox->claimDue(1, $now);
        $outbox->markDone($claimed[0]->id);

        $this->assertCount(1, $outbox->messages);
        $this->assertSame('done', $outbox->messages[0]['status']);
        $this->assertSame([], $outbox->claimDue(10, $now->modify('+1 hour')));
    }

    public function testMarkFailureWithoutDeadReturnsPendingWithBackoffAndLastError(): void
    {
        $outbox = new FakeOutbox();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');
        $availableAt = $now->modify('+2 seconds');

        $outbox->enqueue('transfer.completed', 5, ['transfer_id' => 5], $now);
        $claimed = $outbox->claimDue(1, $now);
        $outbox->markFailure($claimed[0]->id, 1, $availableAt, 'notifier unavailable', false);

        $row = $outbox->messages[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, $row['attempts']);
        $this->assertEquals($availableAt, $row['available_at']);
        $this->assertSame('notifier unavailable', $row['last_error']);
        $this->assertSame([], $outbox->claimDue(10, $now));
    }

    public function testMarkFailureWithDeadSetsTerminalDeadAndStopsRetries(): void
    {
        $outbox = new FakeOutbox();
        $now = new DateTimeImmutable('2026-08-08T12:00:00+00:00');

        $outbox->enqueue('transfer.completed', 6, ['transfer_id' => 6], $now);
        $claimed = $outbox->claimDue(1, $now);
        $outbox->markFailure($claimed[0]->id, 8, $now, 'max attempts exceeded', true);

        $this->assertCount(1, $outbox->messages);
        $this->assertSame('dead', $outbox->messages[0]['status']);
        $this->assertSame(8, $outbox->messages[0]['attempts']);
        $this->assertSame('max attempts exceeded', $outbox->messages[0]['last_error']);
        $this->assertSame([], $outbox->claimDue(10, $now->modify('+1 day')));
    }
}
