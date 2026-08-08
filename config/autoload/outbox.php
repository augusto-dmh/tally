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

use function Hyperf\Support\env;

return [
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 8),
    'backoff_cap_seconds' => (int) env('OUTBOX_BACKOFF_CAP', 300),
    'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 10),
    'process_sleep_seconds' => (int) env('OUTBOX_PROCESS_SLEEP', 1),
    'claim_lease_seconds' => (int) env('OUTBOX_CLAIM_LEASE', 60),
    'relay_enable' => filter_var(env('OUTBOX_RELAY_ENABLE', true), FILTER_VALIDATE_BOOLEAN),
];
