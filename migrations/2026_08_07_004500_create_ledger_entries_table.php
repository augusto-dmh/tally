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
use App\Infrastructure\Persistence\OpeningLedgerBackfill;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('journal_id', 36);
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->string('account_kind', 32);
            $table->string('direction', 8);
            $table->unsignedBigInteger('amount_cents');
            $table->dateTime('created_at');

            $table->index('journal_id');
            $table->index('transfer_id');
            $table->index('wallet_id');
            $table->index('account_kind');

            $table->foreign('transfer_id')->references('id')->on('transfers');
            $table->foreign('wallet_id')->references('id')->on('wallets');
        });

        (new OpeningLedgerBackfill())->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
