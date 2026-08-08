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

use App\Domain\OutboxEventType;
use App\Domain\Port\Outbox;
use App\Infrastructure\Persistence\DbOutbox;
use App\Infrastructure\Persistence\DbTransactionRunner;
use DateTimeImmutable;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Parallel;
use Hyperf\Database\Exception\UniqueConstraintViolationException;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;
use RuntimeException;

/**
 * Spec anchors: OUTB-01 (enqueue), OUTB-04 (claim → processing / SKIP LOCKED),
 * OUTB-05 (done), OUTB-06/OUTB-07 (retry vs dead), OUTB-08 (unique), OUTB-10 (lease reclaim).
 *
 * @internal
 * @coversNothing
 */
final class DbOutboxTest extends IntegrationTestCase
{
    public function testEnqueuePersistsAPendingRow(): void
    {
        [$payerWalletId, $payeeWalletId, $transferId] = $this->seedTransferPair(100000, 50000, 15050);
        $outbox = new DbOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');
        $payload = [
            'transfer_id' => $transferId,
            'payer_wallet_id' => $payerWalletId,
            'payee_wallet_id' => $payeeWalletId,
            'amount_cents' => 15050,
        ];

        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            $payload,
            $createdAt,
        );

        $row = Db::table('outbox')->where('transfer_id', $transferId)->first();
        $this->assertNotNull($row);
        $this->assertSame('transfer.completed', $row->event_type);
        $this->assertSame($transferId, (int) $row->transfer_id);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame('2026-08-08 12:00:00', $row->available_at);
        $this->assertSame('2026-08-08 12:00:00', $row->created_at);
        $this->assertSame('2026-08-08 12:00:00', $row->updated_at);
        $this->assertNull($row->last_error);

        $decoded = is_string($row->payload)
            ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)
            : (array) $row->payload;
        $this->assertEquals($payload, $decoded);
    }

    public function testEnqueueParticipatesInAnOpenTransactionRollback(): void
    {
        [, , $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $outbox = new DbOutbox();
        $runner = new DbTransactionRunner();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');

        $rolledBack = null;
        try {
            $runner->run(function () use ($outbox, $transferId, $createdAt): void {
                $outbox->enqueue(
                    OutboxEventType::TransferCompleted->value,
                    $transferId,
                    ['transfer_id' => $transferId],
                    $createdAt,
                );
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $rolledBack = $exception;
        }

        $this->assertNotNull($rolledBack);
        $this->assertSame(0, Db::table('outbox')->count());
    }

    public function testEnqueueRejectsDuplicateTransferIdAndEventType(): void
    {
        [, , $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $outbox = new DbOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');

        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            ['transfer_id' => $transferId],
            $createdAt,
        );

        $this->expectException(UniqueConstraintViolationException::class);
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            ['transfer_id' => $transferId],
            $createdAt,
        );
    }

    public function testClaimDueMarksRowsProcessingAndReturnsMessages(): void
    {
        [$payerWalletId, $payeeWalletId, $transferId] = $this->seedTransferPair(100000, 50000, 15050);
        $outbox = new DbOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');
        $payload = [
            'transfer_id' => $transferId,
            'payer_wallet_id' => $payerWalletId,
            'payee_wallet_id' => $payeeWalletId,
            'amount_cents' => 15050,
        ];
        $outbox->enqueue(OutboxEventType::TransferCompleted->value, $transferId, $payload, $createdAt);

        $futureTransferId = $this->insertTransfer($payerWalletId, $payeeWalletId, 200);
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $futureTransferId,
            ['transfer_id' => $futureTransferId],
            new DateTimeImmutable('2026-08-08 13:00:00'),
        );

        $now = new DateTimeImmutable('2026-08-08 12:30:00');
        $claimed = $outbox->claimDue(10, $now);

        $this->assertCount(1, $claimed);
        $message = $claimed[0];
        $this->assertSame($transferId, $message->transferId);
        $this->assertSame('transfer.completed', $message->eventType);
        $this->assertEquals($payload, $message->payload);
        $this->assertSame(0, $message->attempts);
        $this->assertSame('processing', $message->status);

        $row = Db::table('outbox')->where('transfer_id', $transferId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame('2026-08-08 12:30:00', $row->updated_at);

        $future = Db::table('outbox')->where('transfer_id', $futureTransferId)->first();
        $this->assertSame('pending', $future->status);
    }

    public function testClaimDueReclaimsStaleProcessingPastLease(): void
    {
        [, , $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $outbox = new DbOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            ['transfer_id' => $transferId],
            $createdAt,
        );

        Db::table('outbox')->where('transfer_id', $transferId)->update([
            'status' => 'processing',
            'updated_at' => '2026-08-08 12:00:00',
        ]);

        $claimed = $outbox->claimDue(10, new DateTimeImmutable('2026-08-08 12:01:01'));

        $this->assertCount(1, $claimed);
        $this->assertSame($transferId, $claimed[0]->transferId);
        $this->assertSame('processing', $claimed[0]->status);
        $this->assertSame(
            '2026-08-08 12:01:01',
            Db::table('outbox')->where('transfer_id', $transferId)->value('updated_at')
        );
    }

    public function testTwoClaimersDoNotDoubleClaimTheSameRow(): void
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerId, 100000);
        $payeeWalletId = $this->insertWallet($payeeId, 50000);

        $transferIds = [];
        for ($i = 0; $i < 2; ++$i) {
            $transferIds[] = $this->insertTransfer($payerWalletId, $payeeWalletId, 100 + $i);
        }

        $outbox = new DbOutbox();
        $createdAt = new DateTimeImmutable('2026-08-08 12:00:00');
        foreach ($transferIds as $transferId) {
            $outbox->enqueue(
                OutboxEventType::TransferCompleted->value,
                $transferId,
                ['transfer_id' => $transferId],
                $createdAt,
            );
        }

        $now = new DateTimeImmutable('2026-08-08 12:00:00');
        $parallel = new Parallel(2);
        $parallel->add(static fn (): array => $outbox->claimDue(1, $now));
        $parallel->add(static fn (): array => $outbox->claimDue(1, $now));
        $results = $parallel->wait();

        $claimedIds = [];
        foreach ($results as $batch) {
            $this->assertCount(1, $batch);
            $claimedIds[] = $batch[0]->transferId;
        }

        sort($claimedIds);
        $expected = $transferIds;
        sort($expected);
        $this->assertSame($expected, $claimedIds);
        $this->assertSame(2, Db::table('outbox')->where('status', 'processing')->count());
        $this->assertCount(2, array_unique($claimedIds));
    }

    public function testMarkDoneSetsStatusDone(): void
    {
        [, , $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $outbox = new DbOutbox();
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            ['transfer_id' => $transferId],
            new DateTimeImmutable('2026-08-08 12:00:00'),
        );

        $claimed = $outbox->claimDue(1, new DateTimeImmutable('2026-08-08 12:00:00'));
        $outbox->markDone($claimed[0]->id);

        $row = Db::table('outbox')->where('id', $claimed[0]->id)->first();
        $this->assertSame('done', $row->status);
        $this->assertNull($row->last_error);
    }

    public function testMarkFailureRetriesAsPendingOrMarksDead(): void
    {
        [, , $transferId] = $this->seedTransferPair(100000, 50000, 100);
        $outbox = new DbOutbox();
        $outbox->enqueue(
            OutboxEventType::TransferCompleted->value,
            $transferId,
            ['transfer_id' => $transferId],
            new DateTimeImmutable('2026-08-08 12:00:00'),
        );

        $claimed = $outbox->claimDue(1, new DateTimeImmutable('2026-08-08 12:00:00'));
        $id = $claimed[0]->id;
        $availableAt = new DateTimeImmutable('2026-08-08 12:00:02');

        $outbox->markFailure($id, 1, $availableAt, 'notify failed', false);

        $row = Db::table('outbox')->where('id', $id)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame('2026-08-08 12:00:02', $row->available_at);
        $this->assertSame('notify failed', $row->last_error);

        $reclaimed = $outbox->claimDue(1, new DateTimeImmutable('2026-08-08 12:00:02'));
        $outbox->markFailure($reclaimed[0]->id, 8, $availableAt, 'exhausted', true);

        $dead = Db::table('outbox')->where('id', $id)->first();
        $this->assertSame('dead', $dead->status);
        $this->assertSame(8, (int) $dead->attempts);
        $this->assertSame('exhausted', $dead->last_error);
    }

    public function testOutboxPortIsBoundToDbOutbox(): void
    {
        $resolved = ApplicationContext::getContainer()->get(Outbox::class);

        $this->assertInstanceOf(DbOutbox::class, $resolved);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function seedTransferPair(int $payerBalance, int $payeeBalance, int $amountCents): array
    {
        $payerId = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');
        $payeeId = $this->insertUser('22222222222', 'common', 'Bruno Teixeira', 'bruno@tally.test');
        $payerWalletId = $this->insertWallet($payerId, $payerBalance);
        $payeeWalletId = $this->insertWallet($payeeId, $payeeBalance);
        $transferId = $this->insertTransfer($payerWalletId, $payeeWalletId, $amountCents);

        return [$payerWalletId, $payeeWalletId, $transferId];
    }

    private function insertTransfer(int $payerWalletId, int $payeeWalletId, int $amountCents): int
    {
        return (int) Db::table('transfers')->insertGetId([
            'payer_wallet_id' => $payerWalletId,
            'payee_wallet_id' => $payeeWalletId,
            'amount_cents' => $amountCents,
            'created_at' => '2026-08-08 12:00:00',
        ]);
    }
}
