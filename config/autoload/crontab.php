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
use Hyperf\Crontab\Crontab;

use function Hyperf\Support\env;

/*
 * Schedules ledger:reconcile in-process. Disabled under APP_ENV=testing so
 * co-phpunit does not spawn the dispatcher. Override with CRONTAB_ENABLE outside
 * testing when needed.
 */
return [
    'enable' => env('APP_ENV') !== 'testing' && (bool) env('CRONTAB_ENABLE', true),
    'crontab' => [
        (new Crontab())
            ->setType('command')
            ->setName('ledger-reconcile')
            ->setRule('*/5 * * * *')
            ->setCallback([
                'command' => 'ledger:reconcile',
                '--disable-event-dispatcher' => true,
            ])
            ->setMemo('Read-only ledger vs wallet projection check'),
    ],
];
