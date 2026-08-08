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

use App\Domain\OutboxMessage;
use App\Domain\Port\Outbox;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;
use stdClass;

final class DbOutbox implements Outbox
{
    private const CLAIM_LEASE_SECONDS = 60;

    public function enqueue(
        string $eventType,
        int $transferId,
        array $payload,
        DateTimeImmutable $createdAt,
    ): void {
        $createdAtSql = $createdAt->format('Y-m-d H:i:s');

        Db::table('outbox')->insert([
            'event_type' => $eventType,
            'transfer_id' => $transferId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $createdAtSql,
            'last_error' => null,
            'created_at' => $createdAtSql,
            'updated_at' => $createdAtSql,
        ]);
    }

    public function claimDue(int $limit, DateTimeImmutable $now): array
    {
        if ($limit < 1) {
            return [];
        }

        $nowSql = $now->format('Y-m-d H:i:s');
        $leaseCutoffSql = $now->modify(sprintf('-%d seconds', self::CLAIM_LEASE_SECONDS))
            ->format('Y-m-d H:i:s');

        return Db::transaction(function () use ($limit, $nowSql, $leaseCutoffSql): array {
            $rows = Db::table('outbox')
                ->where(function ($query) use ($nowSql, $leaseCutoffSql): void {
                    $query->where(function ($pending) use ($nowSql): void {
                        $pending->where('status', 'pending')
                            ->where('available_at', '<=', $nowSql);
                    })->orWhere(function ($stale) use ($leaseCutoffSql): void {
                        $stale->where('status', 'processing')
                            ->where('updated_at', '<=', $leaseCutoffSql);
                    });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row->id;
            }

            Db::table('outbox')->whereIn('id', $ids)->update([
                'status' => 'processing',
                'updated_at' => $nowSql,
            ]);

            $messages = [];
            foreach ($rows as $row) {
                $messages[] = $this->toMessage($row, 'processing');
            }

            return $messages;
        });
    }

    public function markDone(int $id): void
    {
        Db::table('outbox')->where('id', $id)->update([
            'status' => 'done',
            'last_error' => null,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function markFailure(
        int $id,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $lastError,
        bool $dead,
    ): void {
        $values = [
            'attempts' => $attempts,
            'last_error' => $lastError,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        if ($dead) {
            $values['status'] = 'dead';
        } else {
            $values['status'] = 'pending';
            $values['available_at'] = $availableAt->format('Y-m-d H:i:s');
        }

        Db::table('outbox')->where('id', $id)->update($values);
    }

    private function toMessage(stdClass $row, ?string $statusOverride = null): OutboxMessage
    {
        $payload = $row->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        return new OutboxMessage(
            (int) $row->id,
            (string) $row->event_type,
            (int) $row->transfer_id,
            (array) $payload,
            (int) $row->attempts,
            $statusOverride ?? (string) $row->status,
            new DateTimeImmutable((string) $row->available_at),
            $row->last_error !== null ? (string) $row->last_error : null,
        );
    }
}
