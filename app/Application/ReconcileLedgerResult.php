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

/**
 * Outcome of a read-only ledger reconcile: ok when global Σ0 and every wallet
 * projection matches its journal net; otherwise a list of violation messages.
 */
final class ReconcileLedgerResult
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $violations,
    ) {
    }
}
