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

use App\Domain\Port\TransferAuthorizer;
use App\Domain\Transfer;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

/**
 * Asks util.devi.tools whether a transfer may happen. Every answer that is not
 * an explicit authorization — a refusal, a broken service, an unreadable body,
 * an unreachable host — is a no.
 */
final class DeviToolsAuthorizer implements TransferAuthorizer
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

    public function authorize(Transfer $transfer): bool
    {
        try {
            $response = $this->client->get($this->baseUri . '/api/v2/authorize');
        } catch (Throwable) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body)
            && ($body['status'] ?? null) === 'success'
            && ($body['data']['authorization'] ?? null) === true;
    }
}
