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
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;

/*
 * Only the two services that would leave the machine are faked. Everything a
 * transfer touches inside tally — the database, the transaction — stays real.
 */
return [
    TransferAuthorizer::class => FakeTransferAuthorizer::class,
    TransferNotifier::class => FakeTransferNotifier::class,
];
