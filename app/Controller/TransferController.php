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

namespace App\Controller;

use App\Application\TransferFunds;
use App\Application\TransferFundsInput;
use App\Domain\Money;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns a JSON request into a transfer. Whatever the request is not — not
 * JSON, no parties, no amount — is answered here, so the exceptions that
 * reach the client from deeper down always mean a business refusal.
 */
class TransferController extends AbstractController
{
    public function __construct(private readonly TransferFunds $transferFunds)
    {
    }

    public function store(): ResponseInterface
    {
        $payload = json_decode((string) $this->request->getBody(), true);

        if (! is_array($payload)) {
            return $this->unusable('The request body must be a JSON object.');
        }

        $payer = $payload['payer'] ?? null;
        $payee = $payload['payee'] ?? null;
        $value = $payload['value'] ?? null;

        if (! is_int($payer)) {
            return $this->unusable('The payer must be given as a user id.');
        }

        if (! is_int($payee)) {
            return $this->unusable('The payee must be given as a user id.');
        }

        if (! is_numeric($value)) {
            return $this->unusable('The value must be given as an amount in reais.');
        }

        $idempotencyKey = $this->idempotencyKeyFromHeader();
        if ($idempotencyKey === false) {
            return $this->unusable('The Idempotency-Key header must not be empty.');
        }

        $amount = Money::fromDecimalString((string) $value);
        $requestHash = $idempotencyKey === null
            ? null
            : $this->requestHash($payer, $payee, $amount);

        $result = $this->transferFunds->execute(
            new TransferFundsInput($payer, $payee, $amount, $idempotencyKey, $requestHash)
        );

        return $this->response->json($result->body)->withStatus($result->statusCode);
    }

    /**
     * @return string|null|false null when absent; false when present but empty/whitespace
     */
    private function idempotencyKeyFromHeader(): string|null|false
    {
        if (! $this->request->hasHeader('Idempotency-Key')) {
            return null;
        }

        $key = trim($this->request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            return false;
        }

        return $key;
    }

    private function requestHash(int $payer, int $payee, Money $amount): string
    {
        $value = sprintf('%d.%02d', intdiv($amount->cents(), 100), $amount->cents() % 100);
        $canonical = json_encode(
            ['payee' => $payee, 'payer' => $payer, 'value' => $value],
            JSON_THROW_ON_ERROR
        );

        return hash('sha256', $canonical);
    }

    private function unusable(string $message): ResponseInterface
    {
        return $this->response->json([
            'error' => 'invalid_request',
            'message' => $message,
        ])->withStatus(422);
    }
}
