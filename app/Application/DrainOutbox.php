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

namespace App\Application;

use App\Domain\Exception\NotificationFailed;
use App\Domain\Money;
use App\Domain\OutboxEventType;
use App\Domain\OutboxMessage;
use App\Domain\Port\Outbox;
use App\Domain\Port\TransferNotifier;
use App\Domain\Transfer;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * One drain pass: claim due outbox rows, notify outside the claim transaction,
 * then mark done / retry with backoff / dead.
 */
final class DrainOutbox
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly TransferNotifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 8,
        private readonly int $batchSize = 10,
        private readonly int $backoffCapSeconds = 300,
    ) {
    }

    public function execute(?DateTimeImmutable $now = null): DrainOutboxResult
    {
        $now ??= new DateTimeImmutable();
        $messages = $this->outbox->claimDue($this->batchSize, $now);

        $done = 0;
        $failed = 0;
        $dead = 0;

        foreach ($messages as $message) {
            if ($message->eventType !== OutboxEventType::TransferCompleted->value) {
                $this->outbox->markFailure(
                    $message->id,
                    $message->attempts,
                    $now,
                    'unknown_event_type',
                    true,
                );
                ++$dead;
                continue;
            }

            $transfer = $this->transferFromPayload($message);

            try {
                $this->notifier->notify($transfer);
                $this->outbox->markDone($message->id);
                ++$done;
            } catch (NotificationFailed $exception) {
                $newAttempts = $message->attempts + 1;
                if ($newAttempts >= $this->maxAttempts) {
                    $this->logger->error(sprintf(
                        'Outbox message %d exhausted notify attempts (%d): %s',
                        $message->id,
                        $newAttempts,
                        $exception->getMessage(),
                    ));
                    $this->outbox->markFailure(
                        $message->id,
                        $newAttempts,
                        $now,
                        $exception->getMessage(),
                        true,
                    );
                    ++$dead;
                    continue;
                }

                $delaySeconds = min($this->backoffCapSeconds, 2 ** ($newAttempts - 1));
                $availableAt = $now->modify(sprintf('+%d seconds', $delaySeconds));
                $this->outbox->markFailure(
                    $message->id,
                    $newAttempts,
                    $availableAt,
                    $exception->getMessage(),
                    false,
                );
                ++$failed;
            }
        }

        return new DrainOutboxResult(count($messages), $done, $failed, $dead);
    }

    private function transferFromPayload(OutboxMessage $message): Transfer
    {
        $payload = $message->payload;

        return new Transfer(
            (int) $payload['transfer_id'],
            (int) $payload['payer_wallet_id'],
            (int) $payload['payee_wallet_id'],
            Money::fromCents((int) $payload['amount_cents']),
            new DateTimeImmutable(),
        );
    }
}
