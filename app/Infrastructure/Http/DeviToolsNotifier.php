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

namespace App\Infrastructure\Http;

use App\Domain\Exception\NotificationFailed;
use App\Domain\Port\TransferNotifier;
use App\Domain\Transfer;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

/**
 * Tells util.devi.tools that a payee was paid. Anything short of an accepted
 * notification is a failure the caller must see.
 */
final class DeviToolsNotifier implements TransferNotifier
{
    public const DEFAULT_BASE_URI = 'https://util.devi.tools';

    private readonly Client $client;

    public function __construct(
        ClientFactory $clientFactory,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        $this->client = $clientFactory->create([
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
            'http_errors' => false,
        ]);
    }

    public function notify(Transfer $transfer): void
    {
        try {
            $response = $this->client->post($this->baseUri . '/api/v1/notify', [
                'json' => [
                    'transfer_id' => $transfer->id,
                    'payee_wallet_id' => $transfer->payeeWalletId,
                    'amount_cents' => $transfer->amount->cents(),
                ],
            ]);
        } catch (Throwable $exception) {
            throw new NotificationFailed(
                sprintf('The notification service could not be reached: %s', $exception->getMessage()),
                0,
                $exception
            );
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new NotificationFailed(sprintf(
                'The notification service answered %d.',
                $response->getStatusCode()
            ));
        }
    }
}
