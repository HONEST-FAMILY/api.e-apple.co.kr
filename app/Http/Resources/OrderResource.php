<?php

namespace App\Http\Resources;

use App\Enums\IamportMethod;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        //return parent::toArray($request);
        $additionalFields = ($request->user()?->is_admin) ? [
            'user' => UserResource::make($this->whenLoaded('user')),
            'pay_method_method_label' => ($this->pay_method_method) ? IamportMethod::tryFrom($this->pay_method_method)?->label() : '',
            'can_cancel' => in_array($this->status, OrderStatus::ADMIN_CAN_ORDER_CANCELS)
                && $this->orderProducts->every(fn($e) => in_array($e->status, OrderStatus::ADMIN_CAN_ORDER_CANCELS)),
            'can_delivery_preparing' => $this->status === OrderStatus::PAYMENT_COMPLETE,
            'is_test' => (bool)$this->is_test,
        ] : [];
        $return = [
            ...$additionalFields,
            ...$this->only([
                'id', 'buyer_name', 'buyer_email', 'buyer_contact', 'buyer_address_zipcode', 'buyer_address', 'buyer_address_detail',
                'delivery_name', 'delivery_phone', 'delivery_postal_code', 'delivery_address', 'delivery_address_detail', 'delivery_request', 'common_entrance_method',
                'total_amount', 'user_coupon_id', 'coupon_discount_amount', 'use_points', 'delivery_fee', 'price',
                'imp_uid', 'merchant_uid',
                'pay_method_pg', 'pay_method_method',
                'created_at', 'updated_at', 'delivery_started_at', 'purchase_confirmed_at',
                'delivery_tracking_number',
                'refund_amount_sum', 'refund_delivery_fee_sum',
                'cancel_reason'
            ]),
            //'status' => OrderStatus::from($this->status)->label(),
            'status' => OrderStatus::from($this->status->value)->label('order'),
            'orderProducts' => OrderProductResource::collection($this->whenLoaded('orderProducts')),
        ];

        //*
        if (config('scribe.response_file')) {
            $comments = [ 'created_at'=>'등록일자', 'updated_at'=>'수정일자' ];
            return getScribeResponseFile($return, 'orders', $comments);
        }
        //*/
        return $return;
        //*/
    }
}
