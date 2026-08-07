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
 * A terminal HTTP outcome stored under a client Idempotency-Key so retries
 * can replay the same response without executing the transfer again.
 */
final class IdempotencyRecord
{
    /**
     * @param array<string, mixed> $responseBody Exact JSON object returned to the client
     */
    public function __construct(
        public readonly string $key,
        public readonly string $requestHash,
        public readonly int $statusCode,
        public readonly array $responseBody,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
