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

namespace App\Domain;

use DateTimeImmutable;

/**
 * A durable notify (or other) intent row claimed by the outbox drain path.
 */
final class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload Frozen event payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $eventType,
        public readonly int $transferId,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $status,
        public readonly DateTimeImmutable $availableAt,
        public readonly ?string $lastError,
    ) {
    }
}
