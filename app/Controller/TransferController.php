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
use DateTimeInterface;
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

        $transfer = $this->transferFunds->execute(
            new TransferFundsInput($payer, $payee, Money::fromDecimalString((string) $value))
        );

        return $this->response->json([
            'id' => $transfer->id,
            'payer' => $transfer->payerId,
            'payee' => $transfer->payeeId,
            'value' => $this->reais($transfer->amount),
            'created_at' => $transfer->createdAt->format(DateTimeInterface::ATOM),
        ])->withStatus(201);
    }

    private function unusable(string $message): ResponseInterface
    {
        return $this->response->json([
            'error' => 'invalid_request',
            'message' => $message,
        ])->withStatus(422);
    }

    /**
     * Cents back to the decimal reais the caller sent, by integer arithmetic:
     * the amount never becomes a float on the way out either.
     */
    private function reais(Money $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount->cents(), 100), $amount->cents() % 100);
    }
}
