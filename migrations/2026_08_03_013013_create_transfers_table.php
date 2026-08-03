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
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payer_wallet_id');
            $table->unsignedBigInteger('payee_wallet_id');
            $table->unsignedBigInteger('amount_cents');
            $table->dateTime('created_at');

            $table->foreign('payer_wallet_id')->references('id')->on('wallets');
            $table->foreign('payee_wallet_id')->references('id')->on('wallets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
