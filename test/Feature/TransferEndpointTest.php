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

use App\Domain\Port\TransferAuthorizer;
use App\Domain\Port\TransferNotifier;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\Client;
use HyperfTest\Fake\FakeTransferAuthorizer;
use HyperfTest\Fake\FakeTransferNotifier;
use HyperfTest\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

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

    public function testKeepsTheTransferWhenThePayeeCannotBeNotified(): void
    {
        $this->notifier()->fails = true;

        $response = $this->postTransfer(['value' => 10.00, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->bodyOf($response);
        $this->assertSame(self::PAYER, $body['payer']);
        $this->assertSame(self::PAYEE, $body['payee']);
        $this->assertSame('10.00', $body['value']);
        $this->assertSame(self::PAYER_BALANCE - 1000, $this->balanceOf(self::PAYER));
        $this->assertSame(self::PAYEE_BALANCE + 1000, $this->balanceOf(self::PAYEE));
        $this->assertSame(1, Db::table('transfers')->count());
        $this->assertCount(1, $this->notifier()->notified);
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
        $this->assertCount(1, $this->notifier()->notified);
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

    public function testNotifiesThePayeeOnceThroughTheFakeBoundToThePort(): void
    {
        $this->assertInstanceOf(FakeTransferAuthorizer::class, ApplicationContext::getContainer()->get(TransferAuthorizer::class));
        $this->assertInstanceOf(FakeTransferNotifier::class, ApplicationContext::getContainer()->get(TransferNotifier::class));

        $response = $this->postTransfer(['value' => 100.50, 'payer' => self::PAYER, 'payee' => self::PAYEE]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(1, $this->authorizer()->authorized);
        $this->assertCount(1, $this->notifier()->notified);
        $notified = $this->notifier()->notified[0];
        $this->assertSame($this->bodyOf($response)['id'], $notified->id);
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
