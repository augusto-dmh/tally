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

namespace App\Infrastructure\Persistence;

use App\Domain\Exception\IdempotencyDuplicateKey;
use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\IdempotencyRecord;
use App\Domain\Port\IdempotencyStore;
use DateTimeImmutable;
use Hyperf\Database\Exception\UniqueConstraintViolationException;
use Hyperf\DbConnection\Db;

final class DbIdempotencyStore implements IdempotencyStore
{
    public function find(string $key): ?IdempotencyRecord
    {
        $row = Db::table('idempotency_keys')->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        $body = $row->response_body;
        if (is_string($body)) {
            $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        return new IdempotencyRecord(
            (string) $row->key,
            (string) $row->request_hash,
            (int) $row->status_code,
            (array) $body,
            new DateTimeImmutable((string) $row->created_at),
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        try {
            Db::table('idempotency_keys')->insert([
                'key' => $record->key,
                'request_hash' => $record->requestHash,
                'status_code' => $record->statusCode,
                'response_body' => json_encode($record->responseBody, JSON_THROW_ON_ERROR),
                'created_at' => $record->createdAt->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->find($record->key);
            if ($existing !== null && $existing->requestHash !== $record->requestHash) {
                throw new IdempotencyKeyConflict(
                    'Idempotency-Key was already used with a different request body.'
                );
            }

            throw new IdempotencyDuplicateKey(
                'Idempotency key was claimed by a concurrent request; re-read and replay.'
            );
        }
    }
}
