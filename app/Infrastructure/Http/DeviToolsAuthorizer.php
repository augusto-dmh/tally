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

use App\Domain\Transfer;
use GuzzleHttp\Client;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

/**
 * Single HTTP attempt against util.devi.tools. Distinguishes clear (true),
 * explicit decline (false), and unavailable (AuthorizerUnavailable) so a
 * resilient decorator can retry only outages.
 */
final class DeviToolsAuthorizer implements AuthorizerAttempt
{
    public const DEFAULT_BASE_URI = 'https://util.devi.tools';

    private readonly Client $client;

    public function __construct(
        ClientFactory $clientFactory,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        float $timeout = 5.0,
        float $connectTimeout = 2.0,
    ) {
        $this->client = $clientFactory->create([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @return bool true when cleared; false on explicit decline
     * @throws AuthorizerUnavailable when the service cannot answer
     */
    public function attempt(Transfer $transfer): bool
    {
        try {
            $response = $this->client->get($this->baseUri . '/api/v2/authorize');
        } catch (Throwable $e) {
            throw new AuthorizerUnavailable('Authorizer request failed.', 0, $e);
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        if (is_array($body) && ($body['data']['authorization'] ?? null) === false) {
            return false;
        }

        if ($status < 200 || $status >= 300) {
            throw new AuthorizerUnavailable(sprintf('Authorizer returned HTTP %d.', $status));
        }

        if (
            is_array($body)
            && ($body['status'] ?? null) === 'success'
            && ($body['data']['authorization'] ?? null) === true
        ) {
            return true;
        }

        throw new AuthorizerUnavailable('Authorizer response was unreadable.');
    }
}
