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

namespace HyperfTest\Feature;

use App\Application\DrainOutbox;
use App\Application\DrainOutboxResult;
use App\Domain\LedgerDirection;
use App\Domain\Port\Outbox;
use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use App\Infrastructure\Persistence\OpeningLedgerBackfill;
use DateTimeImmutable;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\Client;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;
use HyperfTest\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function Hyperf\Support\make;

/**
 * The whole feature over HTTP, against the real database and the real
 * transaction. Only the two services that would leave the machine are faked
 * (AD-004), so every "nothing moved" assertion below is a real rollback.
 *
 * @internal
 * @coversNothing
 */
final class TransferEndpointTest extends HttpTestCase
{
    private const PAYER = 1;

    private const PAYEE = 2;

    private const MERCHANT = 3;

    private const PAYER_BALANCE = 100000;

    private const PAYEE_BALANCE = 50000;

    private const MERCHANT_BALANCE = 70000;

    protected function setUp(): void
    {
        parent::setUp();

        // Other test cases swap the application container as they run
        // (Hyperf\Testing\TestCase refreshes it in setUp), and a client built
        // against an older one would answer from services this test cannot
        // see. Build it from the container that is current now, so the fakes
        // read below are the very objects the request handler uses.
        $this->client = make(Client::class);

        Db::table('ledger_entries')->delete();
        Db::table('outbox')->delete();
        Db::table('transfers')->delete();
        Db::table('wallets')->delete();
        Db::table('users')->delete();
        Db::table('idempotency_keys')->delete();

        $this->insertUser(self::PAYER, 'Alice Ramos', '11111111111', 'alice@tally.test', 'common');
        $this->insertUser(self::PAYEE, 'Bruno Teixeira', '22222222222', 'bruno@tally.test', 'common');
        $this->insertUser(self::MERCHANT, 'Mercado Central', '33333333333', 'mercado@tally.test', 'merchant');
        $this->insertWallet(self::PAYER, self::PAYER_BALANCE);
        $this->insertWallet(self::PAYEE, self::PAYEE_BALANCE);
        $this->insertWallet(self::MERCHANT, self::MERCHANT_BALANCE);

        $this->authorizer()->authorizes = true;
        $this->authorizer()->authorized = [];
        $this->notifier()->fails = false;
        $this->notifier()->notified = [];
    }

    public function testMovesTheMoneyAndAnswersWithTheStoredTransfer(): void
    {
        $response = $this->postTransfer(['value' => 100.50, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame(self::PAYER, $body['payer']);
        $this->assertSame(self::PAYEE, $body['payee']);
        $this->assertSame('100.50', $body['value']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['created_at']);

        $this->assertSame(self::PAYER_BALANCE - 10050, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 10050, $this->balanceOf(self::PAYEE));

        $row = Db::table('transfers')->where('id', $body['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($this->walletOf(self::PAYER), (int) $row->payer_wallet_id);
        $this->assertSame($this->walletOf(self::PAYEE), (int) $row->payee_wallet_id);
        $this->assertSame(10050, (int) $row->amount_cents);
        $this->assertSame(1, Db::table('transfers')->count());
        $this->assertSame(1, Db::table('outbox')->where('status', 'pending')->count());
        $this->assertCount(0, $this->notifier()->notified);
    }

    /**
     * LEDG-01 / LEDG-04 / LEDG-07: successful transfer posts two balancing legs
     * tied to transfer_id; wallet balance_cents matches journal net; HTTP body
     * shape stays {id, payer, payee, value, created_at}.
     */
    public function testPostsBalancedLedgerLegsMatchingWalletProjection(): void
    {
        (new OpeningLedgerBackfill())->run();

        $response = $this->postTransfer(['value' => 100.50, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame(
            ['id', 'payer', 'payee', 'value', 'created_at'],
            array_keys($body),
        );
        $this->assertSame(self::PAYER, $body['payer']);
        $this->assertSame(self::PAYEE, $body['payee']);
        $this->assertSame('100.50', $body['value']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['created_at']);

        $payerWalletId = $this->walletOf(self::PAYER);
        $payeeWalletId = $this->walletOf(self::PAYEE);
        $legs = Db::table('ledger_entries')
            ->where('transfer_id', $body['id'])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $legs);
        $debit = $legs[0];
        $credit = $legs[1];
        $this->assertSame((string) $debit->journal_id, (string) $credit->journal_id);
        $this->assertSame($payerWalletId, (int) $debit->wallet_id);
        $this->assertSame($payeeWalletId, (int) $credit->wallet_id);
        $this->assertSame(LedgerDirection::Debit->value, (string) $debit->direction);
        $this->assertSame(LedgerDirection::Credit->value, (string) $credit->direction);
        $this->assertSame(10050, (int) $debit->amount_cents);
        $this->assertSame(10050, (int) $credit->amount_cents);

        $this->assertSame(
            self::PAYER_BALANCE - 10050,
            $this->walletNetCents($payerWalletId),
        );
        $this->assertSame(
            self::PAYEE_BALANCE + 10050,
            $this->walletNetCents($payeeWalletId),
        );
        $this->assertSame($this->balanceOf(self::PAYER), $this->walletNetCents($payerWalletId));
        $this->assertSame($this->balanceOf(self::PAYEE), $this->walletNetCents($payeeWalletId));
    }

    /** LEDG-03: authorizer decline leaves no transfer-linked ledger legs. */
    public function testLeavesNoTransferLegsWhenAuthorizerDeclines(): void
    {
        $this->authorizer()->authorizes = false;

        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertRejected($response, 403, 'transfer_unauthorized');
        $this->assertSame(0, Db::table('ledger_entries')->whereNotNull('transfer_id')->count());
        $this->assertSame(self::PAYER_BALANCE, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE, $this->balanceOf(self::PAYEE));
    }

    public function testPaysAMerchantWhoOnlyEverReceives(): void
    {
        $response = $this->postTransfer(['value' => 25.00, 'payer' => self::PAYER, 'payee' => self::MERCHANT]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(self::PAYER_BALANCE - 2500, $this->balanceOf(self::PAYER));
        $this->assertSame(self::MERCHANT_BALANCE + 2500, $this->balanceOf(self::MERCHANT));
        $this->assertSame(1, Db::table('transfers')->count());
    }

    public function testMovesTheWholeBalanceLeavingThePayerAtZero(): void
    {
        $response = $this->postTransfer(['value' => '1000.00', 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(0, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 100000, $this->balanceOf(self::PAYEE));
    }

    public function testMovesASingleCent(): void
    {
        $response = $this->postTransfer(['value' => '0.01', 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('0.01', $this->bodyOf($response)['value']);
        $this->assertSame(self::PAYER_BALANCE - 1, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1, $this->balanceOf(self::PAYEE));
    }

    public function testRefusesATransferToOneself(): void
    {
        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYER]);

        $this->assertRejected($response, 422, 'self_transfer_not_allowed');
    }

    public function testRefusesAMerchantAsThePayerEvenWithTheMoneyToSpare(): void
    {
        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::MERCHANT, 'payee' => self::PAYEE]);

        $this->assertRejected($response, 422, 'merchant_cannot_transfer');
        $this->assertSame(self::MERCHANT_BALANCE, $this->balanceOf(self::MERCHANT));
    }

    public function testRefusesATransferTheBalanceCannotCover(): void
    {
        $response = $this->postTransfer(['value' => '1000.01', 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertRejected($response, 422, 'insufficient_balance');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unusableRequestProvider')]
    public function testRefusesARequestItCannotAct($payload, string $expectedError): void
    {
        $response = $this->postTransfer($payload);

        $this->assertRejected($response, 422, $expectedError);
        $this->assertCount(0, $this->authorizer()->authorized);
    }

    public static function unusableRequestProvider(): array
    {
        return [
            'value missing' => [['payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_request'],
            'value null' => [['value' => null, 'payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_request'],
            'value not a number' => [['value' => 'ten reais', 'payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_request'],
            'value negative' => [['value' => -10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_amount'],
            'value zero' => [['value' => 0, 'payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_amount'],
            'value below a cent' => [['value' => '10.505', 'payer' => self::PAYER, 'payee' => self::PAYEE], 'invalid_amount'],
            'payer missing' => [['value' => 10.00, 'payee' => self::PAYEE], 'invalid_request'],
            'payee missing' => [['value' => 10.00, 'payer' => self::PAYER], 'invalid_request'],
            'payer not an id' => [['value' => 10.00, 'payer' => 'alice', 'payee' => self::PAYEE], 'invalid_request'],
            'payee not an id' => [['value' => 10.00, 'payer' => self::PAYER, 'payee' => 2.5], 'invalid_request'],
        ];
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $response = $this->request('POST', '/transfers', [
            'headers' => ['Content-Type' => 'application/json'],
            'form_params' => ['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE],
        ]);

        $this->assertRejected($response, 422, 'invalid_request');
    }

    public function testAnswersNotFoundForAPayerWhoDoesNotExist(): void
    {
        $response = $this->postTransfer(['value' => 10.00, 'payer' => 99, 'payee' => self::PAYEE]);

        $this->assertRejected($response, 404, 'user_not_found');
    }

    public function testAnswersNotFoundForAPayeeWhoDoesNotExist(): void
    {
        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => 99]);

        $this->assertRejected($response, 404, 'user_not_found');
    }

    public function testRefusesTheTransferTheAuthorizerDoesNotClear(): void
    {
        $this->authorizer()->authorizes = false;

        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertRejected($response, 403, 'transfer_unauthorized');
        $this->assertCount(1, $this->authorizer()->authorized);
        $this->assertCount(0, $this->notifier()->notified);
    }

    /**
     * OUTB-01 / OUTB-06 / OUTB-07: money and outbox commit on the request path;
     * notify failure only surfaces when DrainOutbox runs (retry then dead).
     */
    public function testCommitsTransferAndOutboxEvenWhenLaterNotifyFails(): void
    {
        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame(self::PAYER, $body['payer']);
        $this->assertSame(self::PAYEE, $body['payee']);
        $this->assertSame('10.00', $body['value']);
        $this->assertSame(self::PAYER_BALANCE - 1000, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1000, $this->balanceOf(self::PAYEE));
        $this->assertSame(1, Db::table('transfers')->count());
        $this->assertSame(2, Db::table('ledger_entries')->whereNotNull('transfer_id')->count());
        $this->assertSame(1, Db::table('outbox')->where('status', 'pending')->count());
        $this->assertCount(0, $this->notifier()->notified);

        $this->notifier()->fails = true;
        $now = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $first = $this->drainOutbox($now, maxAttempts: 2);
        $this->assertSame(1, $first->failed);
        $this->assertSame(0, $first->dead);
        $row = Db::table('outbox')->where('transfer_id', $body['id'])->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);

        $second = $this->drainOutbox(new DateTimeImmutable((string) $row->available_at), maxAttempts: 2);
        $this->assertSame(1, $second->dead);
        $this->assertSame('dead', Db::table('outbox')->where('transfer_id', $body['id'])->value('status'));
        $this->assertSame(1, Db::table('transfers')->count());
        $this->assertSame(self::PAYER_BALANCE - 1000, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1000, $this->balanceOf(self::PAYEE));
    }

    /** CONC-05: same Idempotency-Key + body returns the stored outcome once. */
    public function testReplaysAnIdenticalTransferUnderTheSameIdempotencyKey(): void
    {
        $payload = ['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE];
        $headers = ['Idempotency-Key' => 'demo-key-1'];

        $first = $this->postTransfer($payload, $headers);
        $second = $this->postTransfer($payload, $headers);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $firstBody = $this->bodyOf($first);
        $secondBody = $this->bodyOf($second);
        $this->assertSame($firstBody['id'], $secondBody['id']);
        $this->assertSame($firstBody['payer'], $secondBody['payer']);
        $this->assertSame($firstBody['payee'], $secondBody['payee']);
        $this->assertSame($firstBody['value'], $secondBody['value']);
        $this->assertSame($firstBody['created_at'], $secondBody['created_at']);
        $this->assertSame(self::PAYER_BALANCE - 1000, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1000, $this->balanceOf(self::PAYEE));
        $this->assertSame(1, Db::table('transfers')->count());
        $this->assertCount(1, $this->authorizer()->authorized);
        $this->assertCount(0, $this->notifier()->notified);
        $this->assertSame(1, Db::table('outbox')->count());
        $this->assertSame(1, Db::table('outbox')->where('status', 'pending')->count());
        $this->assertSame(
            2,
            Db::table('ledger_entries')->where('transfer_id', $firstBody['id'])->count(),
        );
    }

    /** CONC-06: same key with a different body is a conflict. */
    public function testRejectsTheSameIdempotencyKeyWithADifferentBody(): void
    {
        $headers = ['Idempotency-Key' => 'demo-key-conflict'];

        $first = $this->postTransfer(
            ['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE],
            $headers
        );
        $this->assertSame(201, $first->getStatusCode());

        $second = $this->postTransfer(
            ['value' => 20.00, 'payer' => self::PAYER, 'payee' => self::PAYEE],
            $headers
        );

        $this->assertSame(422, $second->getStatusCode());
        $this->assertSame('idempotency_key_conflict', $this->bodyOf($second)['error']);
        $this->assertSame(self::PAYER_BALANCE - 1000, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1000, $this->balanceOf(self::PAYEE));
        $this->assertSame(1, Db::table('transfers')->count());
    }

    /** CONC-07: without a key each request is independent. */
    public function testTreatsRequestsWithoutAnIdempotencyKeyAsIndependent(): void
    {
        $first = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);
        $second = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertNotSame($this->bodyOf($first)['id'], $this->bodyOf($second)['id']);
        $this->assertSame(self::PAYER_BALANCE - 2000, $this->balanceOf(self::PAYER));
        $this->assertSame(2, Db::table('transfers')->count());
        $this->assertCount(2, $this->authorizer()->authorized);
    }

    public function testRejectsAnEmptyIdempotencyKey(): void
    {
        $response = $this->postTransfer(
            ['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE],
            ['Idempotency-Key' => '   ']
        );

        $this->assertRejected($response, 422, 'invalid_request');
        $this->assertCount(0, $this->authorizer()->authorized);
    }

    /**
     * OUTB-01 / OUTB-04 / OUTB-05: POST enqueues once with notifier idle;
     * DrainOutbox delivers once and marks the row done.
     */
    public function testDrainsTheOutboxToNotifyThePayeeOnce(): void
    {
        $this->assertInstanceOf(FakeTransferAuthorizer::class, ApplicationContext::getContainer()->get(TransferAuthorizer::class));
        $this->assertInstanceOf(FakeTransferNotifier::class, ApplicationContext::getContainer()->get(TransferNotifier::class));

        $response = $this->postTransfer(['value' => 100.50, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertCount(1, $this->authorizer()->authorized);
        $this->assertCount(0, $this->notifier()->notified);
        $this->assertSame(1, Db::table('outbox')->where('status', 'pending')->count());

        $result = $this->drainOutbox();

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->done);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->dead);
        $this->assertSame('done', Db::table('outbox')->where('transfer_id', $body['id'])->value('status'));
        $this->assertCount(1, $this->notifier()->notified);
        $notified = $this->notifier()->notified[0];
        $this->assertSame($body['id'], $notified->id);
        $this->assertSame($this->walletOf(self::PAYER), $notified->payerWalletId);
        $this->assertSame($this->walletOf(self::PAYEE), $notified->payeeWalletId);
        $this->assertSame(10050, $notified->amount->cents());
    }

    /**
     * Every refusal has to leave the money and the ledger exactly as it found
     * them — the half of the rollback guarantee a status code cannot show.
     */
    private function assertRejected(ResponseInterface $response, int $status, string $error): void
    {
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($error, $this->bodyOf($response)['error']);
        $this->assertSame(self::PAYER_BALANCE, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE, $this->balanceOf(self::PAYEE));
        $this->assertSame(0, Db::table('transfers')->count());
        $this->assertSame(0, Db::table('ledger_entries')->whereNotNull('transfer_id')->count());
        $this->assertSame(0, Db::table('outbox')->count());
    }

    private function drainOutbox(?DateTimeImmutable $now = null, int $maxAttempts = 8): DrainOutboxResult
    {
        $container = ApplicationContext::getContainer();

        return (new DrainOutbox(
            $container->get(Outbox::class),
            $this->notifier(),
            $container->get(LoggerInterface::class),
            $maxAttempts,
            10,
            300,
        ))->execute($now);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $extraHeaders
     */
    private function postTransfer(array $payload, array $extraHeaders = []): ResponseInterface
    {
        return $this->request('POST', '/transfers', [
            'headers' => array_merge(['Content-Type' => 'application/json'], $extraHeaders),
            'json' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function authorizer(): FakeTransferAuthorizer
    {
        return ApplicationContext::getContainer()->get(TransferAuthorizer::class);
    }

    private function notifier(): FakeTransferNotifier
    {
        return ApplicationContext::getContainer()->get(TransferNotifier::class);
    }

    private function balanceOf(int $userId): int
    {
        return (int) Db::table('wallets')->where('user_id', $userId)->value('balance_cents');
    }

    private function walletOf(int $userId): int
    {
        return (int) Db::table('wallets')->where('user_id', $userId)->value('id');
    }

    private function walletNetCents(int $walletId): int
    {
        $credit = LedgerDirection::Credit->value;

        return (int) Db::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = '{$credit}' THEN amount_cents ELSE -amount_cents END), 0) AS net"
            )
            ->value('net');
    }

    private function insertUser(int $id, string $fullName, string $cpf, string $email, string $type): void
    {
        Db::table('users')->insert([
            'id' => $id,
            'full_name' => $fullName,
            'cpf' => $cpf,
            'email' => $email,
            'password_hash' => 'not-a-real-hash',
            'type' => $type,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function insertWallet(int $userId, int $balanceCents): void
    {
        Db::table('wallets')->insert([
            'user_id' => $userId,
            'balance_cents' => $balanceCents,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
