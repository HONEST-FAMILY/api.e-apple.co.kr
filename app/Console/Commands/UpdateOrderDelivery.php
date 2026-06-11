<?php

namespace App\Console\Commands;

use App\Enums\DeliveryCompany;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderProduct;
use Illuminate\Console\Command;

class UpdateOrderDelivery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-order-delivery';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /**
         * 배송중인 상품
         */
        $orderProducts = OrderProduct::where('status', OrderStatus::DELIVERY)->get();
        foreach ($orderProducts as $orderProduct) {

            if (!$orderProduct->delivery_company || !$orderProduct->delivery_tracking_number) {
                continue;
            }

            if (DeliveryCompany::tryFrom($orderProduct->delivery_company)
                ?->isDelivered($orderProduct->delivery_tracking_number)) {

                $orderProduct->status = OrderStatus::DELIVERY_COMPLETE;
                $orderProduct->save();

            }
        }

        $this->syncOrderStatus();
    }

    /**
     * 주문 상태 동기화: 모든 상품이 완료 계열이고 배송완료 상품이 있으면 주문도 배송완료로
     */
    private function syncOrderStatus()
    {
        $doneStatuses = [
            OrderStatus::DELIVERY_COMPLETE->value,
            OrderStatus::PURCHASE_CONFIRM->value,
            OrderStatus::RETURN_COMPLETE->value,
            OrderStatus::EXCHANGE_COMPLETE->value,
            OrderStatus::CANCELLATION_COMPLETE->value,
        ];

        Order::whereIn('status', [OrderStatus::DELIVERY_PREPARING->value, OrderStatus::DELIVERY->value])
            ->whereHas('orderProducts', fn($query) => $query->where('status', OrderStatus::DELIVERY_COMPLETE->value))
            ->whereDoesntHave('orderProducts', fn($query) => $query->whereNotIn('status', $doneStatuses))
            ->update(['status' => OrderStatus::DELIVERY_COMPLETE->value]);
    }
}
