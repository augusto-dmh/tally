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
use App\Domain\Port\TransactionRunner;
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use App\Domain\Port\TransferRepository;
use App\Domain\Port\UserRepository;
use App\Domain\Port\WalletRepository;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use App\Infrastructure\Http\DeviToolsNotifier;
use App\Infrastructure\Persistence\DbTransactionRunner;
use App\Infrastructure\Persistence\DbTransferRepository;
use App\Infrastructure\Persistence\DbUserRepository;
use App\Infrastructure\Persistence\DbWalletRepository;
use Hyperf\Guzzle\ClientFactory;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\env;

$bindings = [
    UserRepository::class => DbUserRepository::class,
    WalletRepository::class => DbWalletRepository::class,
    TransferRepository::class => DbTransferRepository::class,
    TransactionRunner::class => DbTransactionRunner::class,
    TransferAuthorizer::class => static fn (ContainerInterface $container) => new DeviToolsAuthorizer(
        $container->get(ClientFactory::class),
        env('AUTHORIZER_URL', DeviToolsAuthorizer::DEFAULT_BASE_URI),
    ),
    TransferNotifier::class => static fn (ContainerInterface $container) => new DeviToolsNotifier(
        $container->get(ClientFactory::class),
        env('NOTIFIER_URL', DeviToolsNotifier::DEFAULT_BASE_URI),
    ),
];

/*
 * Tests rebuild the container for every test case, so runtime rebinding never
 * survives: the fakes for the two external services have to be part of the
 * configuration itself (AD-004). Persistence stays real — the rollback
 * guarantees are only worth asserting against a real transaction.
 */
if (env('APP_ENV') === 'testing' && is_file(BASE_PATH . '/test/dependencies.php')) {
    $bindings = array_merge($bindings, require BASE_PATH . '/test/dependencies.php');
}

return $bindings;
