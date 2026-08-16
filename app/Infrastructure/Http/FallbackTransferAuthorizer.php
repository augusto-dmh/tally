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

/**
 * Ordered try of AuthorizerAttempt leaves. Advances only on AuthorizerUnavailable;
 * a clear or explicit decline stops the chain. Empty list fails closed.
 */
final class FallbackTransferAuthorizer implements TransferAuthorizer
{
    /**
     * @param list<AuthorizerAttempt> $leaves
     */
    public function __construct(
        private readonly array $leaves,
    ) {
    }

    public function authorize(Transfer $transfer): bool
    {
        foreach ($this->leaves as $leaf) {
            try {
                return $leaf->attempt($transfer);
            } catch (AuthorizerUnavailable) {
                continue;
            }
        }

        return false;
    }
}
