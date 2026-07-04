<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('kpay.table_names.kpay_transactions', 'kpay_transactions'), function (Blueprint $table) {
            $table->id();

            // Pas de contrainte FK : le nom de la table `subscriptions` du core est configurable
            // (config('subscriptions.table_names.subscriptions')). La cohérence est garantie
            // applicativement, pas au niveau base de données.
            $table->unsignedBigInteger('subscription_id')->index();

            // Identifiant envoyé à KPay lors de l'initiation (= ID de la souscription).
            $table->string('external_id')->unique();

            // Identifiant du paiement renvoyé par KPay à l'initiation.
            $table->string('kpay_payment_id')->unique()->nullable();

            // Référence propre à KPay (ex: KPAY-20260514-ABC123), utile pour le support.
            $table->string('kpay_reference')->nullable();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default(config('kpay.currency', 'XAF'));

            // pending | processing | success | failed | cancelled | expired
            $table->string('status')->default('pending')->index();

            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('kpay.table_names.kpay_transactions', 'kpay_transactions'));
    }
};

