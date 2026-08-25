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
use App\Application\DrainOutbox;
use App\Domain\Port\IdempotencyStore;
use App\Domain\Port\InFlightTransfer;
use App\Domain\Port\Ledger;
use App\Domain\Port\Outbox;
use App\Domain\Port\TransactionRunner;
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use App\Domain\Port\TransferRepository;
use App\Domain\Port\UserRepository;
use App\Domain\Port\WalletRepository;
use App\Infrastructure\Http\AuthorizerProviders;
use App\Infrastructure\Http\ContextInFlightTransfer;
use App\Infrastructure\Http\DeviToolsAuthorizer;
use App\Infrastructure\Http\DeviToolsNotifier;
use App\Infrastructure\Http\FallbackTransferAuthorizer;
use App\Infrastructure\Http\ResilientTransferAuthorizer;
use App\Infrastructure\Persistence\DbIdempotencyStore;
use App\Infrastructure\Persistence\DbLedger;
use App\Infrastructure\Persistence\DbOutbox;
use App\Infrastructure\Persistence\DbTransactionRunner;
use App\Infrastructure\Persistence\DbTransferRepository;
use App\Infrastructure\Persistence\DbUserRepository;
use App\Infrastructure\Persistence\DbWalletRepository;
use Hyperf\CircuitBreaker\CircuitBreakerFactory;
use Hyperf\Guzzle\ClientFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

$bindings = [
    UserRepository::class => DbUserRepository::class,
    WalletRepository::class => DbWalletRepository::class,
    TransferRepository::class => DbTransferRepository::class,
    TransactionRunner::class => DbTransactionRunner::class,
    IdempotencyStore::class => DbIdempotencyStore::class,
    Ledger::class => DbLedger::class,
    InFlightTransfer::class => ContextInFlightTransfer::class,
    Outbox::class => static fn () => new DbOutbox(
        (int) config('outbox.claim_lease_seconds', 60),
    ),
    TransferAuthorizer::class => static function (ContainerInterface $container) {
        $defaults = (array) config('authorizer.defaults', []);
        $leaves = [];
        foreach (AuthorizerProviders::usable((array) config('authorizer.providers', [])) as $provider) {
            $settings = array_merge($defaults, $provider);
            $leaves[] = new ResilientTransferAuthorizer(
                new DeviToolsAuthorizer(
                    $container->get(ClientFactory::class),
                    (string) $settings['base_uri'],
                    (float) ($settings['timeout'] ?? 5.0),
                    (float) ($settings['connect_timeout'] ?? 2.0),
                ),
                $container->get(CircuitBreakerFactory::class),
                $container,
                (string) ($settings['breaker_name'] ?? 'transfer.authorizer.primary'),
                (int) ($settings['max_attempts'] ?? 3),
                (int) ($settings['backoff_base_ms'] ?? 50),
                (int) ($settings['backoff_cap_ms'] ?? 500),
                (int) ($settings['fail_counter'] ?? 5),
                (int) ($settings['success_counter'] ?? 1),
                (float) ($settings['duration'] ?? 10.0),
            );
        }

        return new FallbackTransferAuthorizer($leaves);
    },
    TransferNotifier::class => static fn (ContainerInterface $container) => new DeviToolsNotifier(
        $container->get(ClientFactory::class),
        env('NOTIFIER_URL', DeviToolsNotifier::DEFAULT_BASE_URI),
    ),
    DrainOutbox::class => static fn (ContainerInterface $container) => new DrainOutbox(
        $container->get(Outbox::class),
        $container->get(TransferNotifier::class),
        $container->get(LoggerInterface::class),
        (int) config('outbox.max_attempts'),
        (int) config('outbox.batch_size'),
        (int) config('outbox.backoff_cap_seconds'),
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
