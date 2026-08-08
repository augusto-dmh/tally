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

use App\Domain\OutboxMessage;
use App\Domain\Port\Outbox;
use DateTimeImmutable;
use RuntimeException;

final class FakeOutbox implements Outbox
{
    /**
     * @var list<array{
     *     id: int,
     *     event_type: string,
     *     transfer_id: int,
     *     payload: array<string, mixed>,
     *     attempts: int,
     *     status: string,
     *     available_at: DateTimeImmutable,
     *     last_error: ?string,
     *     updated_at: DateTimeImmutable
     * }>
     */
    public array $messages = [];

    public int $claimLeaseSeconds = 60;

    private int $nextId = 1;

    public function enqueue(
        string $eventType,
        int $transferId,
        array $payload,
        DateTimeImmutable $createdAt,
    ): void {
        foreach ($this->messages as $message) {
            if ($message['transfer_id'] === $transferId && $message['event_type'] === $eventType) {
                throw new RuntimeException(sprintf(
                    'Duplicate outbox row for transfer_id=%d event_type=%s.',
                    $transferId,
                    $eventType,
                ));
            }
        }

        $this->messages[] = [
            'id' => $this->nextId++,
            'event_type' => $eventType,
            'transfer_id' => $transferId,
            'payload' => $payload,
            'attempts' => 0,
            'status' => 'pending',
            'available_at' => $createdAt,
            'last_error' => null,
            'updated_at' => $createdAt,
        ];
    }

    public function claimDue(int $limit, DateTimeImmutable $now): array
    {
        $claimed = [];
        $leaseCutoff = $now->modify(sprintf('-%d seconds', $this->claimLeaseSeconds));

        foreach ($this->messages as $index => $message) {
            if (count($claimed) >= $limit) {
                break;
            }

            $duePending = $message['status'] === 'pending' && $message['available_at'] <= $now;
            $staleProcessing = $message['status'] === 'processing' && $message['updated_at'] <= $leaseCutoff;

            if (! $duePending && ! $staleProcessing) {
                continue;
            }

            $this->messages[$index]['status'] = 'processing';
            $this->messages[$index]['updated_at'] = $now;

            $claimed[] = $this->toMessage($this->messages[$index]);
        }

        return $claimed;
    }

    public function markDone(int $id): void
    {
        $index = $this->indexOf($id);
        $this->messages[$index]['status'] = 'done';
        $this->messages[$index]['last_error'] = null;
    }

    public function markFailure(
        int $id,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $lastError,
        bool $dead,
    ): void {
        $index = $this->indexOf($id);
        $this->messages[$index]['attempts'] = $attempts;
        $this->messages[$index]['last_error'] = $lastError;

        if ($dead) {
            $this->messages[$index]['status'] = 'dead';

            return;
        }

        $this->messages[$index]['status'] = 'pending';
        $this->messages[$index]['available_at'] = $availableAt;
    }

    private function indexOf(int $id): int
    {
        foreach ($this->messages as $index => $message) {
            if ($message['id'] === $id) {
                return $index;
            }
        }

        throw new RuntimeException(sprintf('Outbox message id=%d not found.', $id));
    }

    /**
     * @param array{
     *     id: int,
     *     event_type: string,
     *     transfer_id: int,
     *     payload: array<string, mixed>,
     *     attempts: int,
     *     status: string,
     *     available_at: DateTimeImmutable,
     *     last_error: ?string,
     *     updated_at: DateTimeImmutable
     * } $row
     */
    private function toMessage(array $row): OutboxMessage
    {
        return new OutboxMessage(
            $row['id'],
            $row['event_type'],
            $row['transfer_id'],
            $row['payload'],
            $row['attempts'],
            $row['status'],
            $row['available_at'],
            $row['last_error'],
        );
    }
}
