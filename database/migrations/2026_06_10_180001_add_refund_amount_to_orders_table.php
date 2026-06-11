<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Order::cancel 등 기존 코드가 orders.refund_amount 를 갱신하지만 컬럼 생성 이력이 없어 보강.
     * 실DB에 수동 추가되어 있을 수 있어 hasColumn 으로 가드.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'refund_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('refund_amount')->nullable()->default(0)
                    ->after('refund_delivery_fee_sum')->comment('환불금액 합계');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'refund_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('refund_amount');
            });
        }
    }
};
