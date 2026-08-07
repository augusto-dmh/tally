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

namespace App\Exception\Handler;

use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\Exception\InsufficientBalance;
use App\Domain\Exception\InvalidAmount;
use App\Domain\Exception\MerchantCannotTransfer;
use App\Domain\Exception\SelfTransferNotAllowed;
use App\Domain\Exception\TransferUnauthorized;
use App\Domain\Exception\UserNotFound;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Gives every refusal the domain can express its HTTP meaning. An exception
 * that is not listed here is not a business outcome, so it keeps falling
 * through to the handler that answers 500.
 */
class DomainExceptionHandler extends ExceptionHandler
{
    /**
     * @var array<class-string<Throwable>, array{int, string}>
     */
    private const OUTCOMES = [
        InvalidAmount::class => [422, 'invalid_amount'],
        SelfTransferNotAllowed::class => [422, 'self_transfer_not_allowed'],
        MerchantCannotTransfer::class => [422, 'merchant_cannot_transfer'],
        InsufficientBalance::class => [422, 'insufficient_balance'],
        UserNotFound::class => [404, 'user_not_found'],
        TransferUnauthorized::class => [403, 'transfer_unauthorized'],
        IdempotencyKeyConflict::class => [422, 'idempotency_key_conflict'],
    ];

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        [$status, $error] = self::OUTCOMES[$throwable::class];

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status)
            ->withBody(new SwooleStream((string) json_encode([
                'error' => $error,
                'message' => $throwable->getMessage(),
            ])));
    }

    public function isValid(Throwable $throwable): bool
    {
        return isset(self::OUTCOMES[$throwable::class]);
    }
}
