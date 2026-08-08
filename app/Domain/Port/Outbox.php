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

namespace App\Domain\Port;

use App\Domain\OutboxMessage;
use DateTimeImmutable;

interface Outbox
{
    /**
     * Inserts a pending row; unique on (transfer_id, event_type).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $eventType,
        int $transferId,
        array $payload,
        DateTimeImmutable $createdAt,
    ): void;

    /**
     * Claims due pending (and stale processing) rows, marking them processing.
     *
     * @return list<OutboxMessage>
     */
    public function claimDue(int $limit, DateTimeImmutable $now): array;

    public function markDone(int $id): void;

    /**
     * Records a delivery failure: pending + backoff, or dead when $dead is true.
     */
    public function markFailure(
        int $id,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $lastError,
        bool $dead,
    ): void;
}
