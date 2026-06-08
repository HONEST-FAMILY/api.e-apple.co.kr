<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    const MIN_PAYMENT_AMOUNT = 1000;//최소결제금액

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OrderStatus::class, // Enum 캐스팅
        'is_test' => 'boolean',
    ];

    public function scopeSearch(Builder $query, $filters): Builder
    {
        $filters = json_decode($filters);
        if (!empty($filters->keyword)) {
            return $query->where('merchant_uid', 'like', '%' . $filters->keyword . '%')
                ->orWhere('buyer_name', 'like', '%' . $filters->keyword . '%')
                ->orWhere('buyer_contact', 'like', '%' . $filters->keyword . '%')
                ->orWhere('buyer_email', 'like', '%' . $filters->keyword . '%');
        }
        return $query;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getCartsData($data, $cartProductOptions)
    {
        return [
            ...$data,
            'total_amount' => $cartProductOptions->sum(function ($cartProductOption) {
                return $cartProductOption->price * $cartProductOption->quantity;
            }),
            'delivery_fee' => $cartProductOptions->pluck('productOption.product.delivery_fee')->filter()->max(),
            'order_products' => $cartProductOptions->map(function ($cartProductOption) use ($data) {
                return self::setOrderProducts([
                    $data['status'],
                    auth()->id() ?? null,
                    $data['guest_id'] ?? null,
                    $cartProductOption->productOption->product_id,
                    $cartProductOption->productOption->id,
                    $cartProductOption->quantity,
                    $cartProductOption->productOption->price,
                    $cartProductOption->productOption->original_price,
                ]);
            })->toArray(),
        ];
    }

    public static function setOrderProducts($orderProduct)
    {
        return [
            'status' => $orderProduct[0], 'user_id' => $orderProduct[1], 'guest_id' => $orderProduct[2],
            'product_id' => $orderProduct[3], 'product_option_id' => $orderProduct[4],
            'quantity' => $orderProduct[5], 'price' => $orderProduct[6],
            'original_price' => $orderProduct[7],
        ];
    }

    /**
     * 수량만큼 주문상품을 1개씩 개별 레코드로 분리 (각 quantity=1)
     * 출고를 개수별로 관리하기 위함. 재고/금액 검증(checkOrderProducts) 통과 후 저장 직전에만 호출할 것
     */
    public static function splitOrderProductsByQuantity(array $orderProducts): array
    {
        $split = [];
        foreach ($orderProducts as $orderProduct) {
            $quantity = (int)($orderProduct['quantity'] ?? 1);
            for ($i = 0; $i < max(1, $quantity); $i++) {
                $split[] = array_merge($orderProduct, ['quantity' => 1]);
            }
        }
        return $split;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    public static function checkOrderProducts($data)
    {
        $orderProducts = (!empty($data['order_products'])) ? collect($data['order_products']) : $data['orderProducts'];
        $productOptionIds = $orderProducts->pluck('product_option_id');
        $productOptions = ProductOption::with('product')->whereIn('id', $productOptionIds)->get();

        if (empty($productOptions)) {
            //abort(422, '상품을 확인해주세요.');
            abort(response()->json(['message' => '상품을 확인해주세요.', 'errors' => ['orders' => '상품을 확인해주세요.']], 422));
        }

        /**
         * 상품가격(total_amount) 및 상품재고 확인
         */
        $totalAmount = 0;
        foreach ($orderProducts as $e) {
            $productOption = $productOptions->where('product_id', $e['product_id'])->where('id', $e['product_option_id'])->first();
            if ($e['quantity'] > $productOption['stock_quantity']) {
                //abort(422, '상품재고를 확인해주세요.');
                abort(response()->json([
                    'message' => '상품재고를 확인해주세요.',
                    'errors' => ['quantity' => '상품재고를 확인해주세요.'],
                ], 422));
            }
            $totalAmount += $productOption['price'] * $e['quantity'];
        }
        /**
         * 상품가격 확인 total_amount
         */
        /*$totalAmount = $orderProducts->sum(function ($e) use ($productOptions) {
            $productOption = $productOptions->where('product_id', $e['product_id'])->findOrFail($e['product_option_id']);
            return $e['quantity'] * $productOption->price;
        });*/
        if ((int)$data['total_amount'] !== $totalAmount) {
            //abort(422, '상품금액을 확인해주세요.');
            abort(response()->json([
                'message' => '상품금액을 확인해주세요.',
                'errors' => ['total_amount' => '상품금액을 확인해주세요.'],
            ], 422));
        }

        /**
         * 배송비 확인
         */
        {
            $deliveryFee = $productOptions->pluck('product.delivery_fee')->filter()->max();
            if ($deliveryFee > 0) {
                if ((int)$data['delivery_fee'] !== $deliveryFee) abort(422, '배송비를 확인해주세요.');
            }
        }

        return true;
    }

    public function checkOrderAmount($data, $coupon = null)
    {
        self::checkOrderProducts($this->only('total_amount', 'orderProducts', 'delivery_fee'));

        /**
         * 쿠폰 확인
         */
        $couponDiscountAmount = 0;
        if ($data['user_coupon_id'] > 0) {
            if ((int)$data['user_coupon_id'] !== $coupon?->pivot->id)
            {
                //abort(422, '쿠폰을 확인해주세요.');
                abort(response()->json([
                    'message' => '쿠폰을 확인해주세요.',
                    'errors' => ['user_coupon_id' => '쿠폰을 확인해주세요.'],
                    /*'errors' => [
                        'data_user_coupon_id' => $data['user_coupon_id'],
                        'user_coupon_id' => $coupon?->pivot->id,
                        ...$coupon->toArray(),
                    ]*/
                ], 422));
            }
            $couponDiscountAmount = $coupon?->getDiscountAmountByType($this->total_amount) ?? 0;
            if ((int)$data['coupon_discount_amount'] !== $couponDiscountAmount)
            {
                //abort(422, '쿠폰 할인금액을 확인해주세요.');
                abort(response()->json([
                    'message' => '쿠폰 할인금액을 확인해주세요.',
                    'errors' => ['coupon_discount_amount' => '쿠폰 할인금액을 확인해주세요.'],
                    /*'errors' => [
                        'coupon_discount_amount' => '쿠폰 할인금액을 확인해주세요.',
                        //TODO 추후 삭제
                        'debug' => $data['coupon_discount_amount'] . ':' . $couponDiscountAmount,
                        'order' => $this->toArray(),
                        'coupon' => $coupon->toArray(),
                    ],*/
                ], 422));
            }
        }


        /**
         * 적립금 확인
         */
        $usePoint = 0;
        if ($data['use_points'] > 0) {
            if ($data['use_points'] > auth()->user()->points) {
                //abort(422, '적립금 사용액을 확인해주세요.');
                abort(response()->json([
                    'message' => '적립금 사용액을 확인해주세요.',
                    'errors' => ['use_points' => '적립금 사용액을 확인해주세요.'],
                ], 422));
            }
            $usePoint = $data['use_points'];
        }


        /**
         * 최종결제금액 확인
         */
        $paymentAmount = $this->total_amount - $couponDiscountAmount - $usePoint + $this->delivery_fee;
        if ((int)$data['price'] !== $paymentAmount)
        {
            //abort(422, '최종 결제금액을 확인해주세요.');
            abort(response()->json([
                'message' => '최종 결제금액을 확인해주세요.',
                'errors' => ['price' => '최종 결제금액을 확인해주세요.'],
                /*'errors' => [
                    'price' => '최종 결제금액을 확인해주세요.',
                    //TODO 추후 삭제
                    'debug' => $data['price'] . ':' . $paymentAmount,
                    'order' => $this->toArray(),
                ],*/
            ], 422));
        }
        if ($data['price'] < Order::MIN_PAYMENT_AMOUNT) {
            //abort(422, '최소결제금액은 ' . number_format(Order::MIN_PAYMENT_AMOUNT) . '원 입니다.');
            $m = '최소결제금액은 ' . number_format(Order::MIN_PAYMENT_AMOUNT) . '원 입니다.';
            abort(response()->json([
                'message' => $m,
                'errors' => ['price' => $m],
            ], 422));
        }

        return true;
    }

    public function scopeMine(Builder $query, $request)
    {
        if (auth()->check()) {
            $query->where('user_id', auth()->id());
        } else {
            if (!($request->guest_id)) {
                //abort(403, '비회원 아이디가 없습니다.');
                abort(response()->json(['message' => '비회원 아이디가 없습니다.',
                    'errors' => ['guest_id' => '비회원 아이디가 없습니다.']],
                    403));
            }
            $query->where('guest_id', $request->guest_id);
        }
    }

    public function scopePending(Builder $query)
    {
        $query->where('status', OrderStatus::ORDER_PENDING);
    }

    public function scopeAfterOrderPending(Builder $query)
    {
        $query->whereNotIn('status', [OrderStatus::ORDER_PENDING, OrderStatus::ORDER_COMPLETE]);

    }

    public function scopeDelivery(Builder $query)
    {
        //if (config('env.app' === 'local')) return; //FORTEST
        $query->where('status', OrderStatus::DELIVERY);//배송중인 경우
    }

    public function scopeDeliveryBefore(Builder $query)
    {
        //if (config('env.app' === 'local')) return; //FORTEST
        $query->whereIn('status', OrderStatus::DELIVERY_BEFORES);//배송중 이전
    }


    /**
     * 주문결제 가능한 상태
     */
    public function scopeCanOrderPayment(Builder $query)
    {
        $query->whereIn('status', [OrderStatus::ORDER_PENDING, OrderStatus::ORDER_COMPLETE]);//주문접수, 주문완료
    }

    /**
     * 주문취소 가능한 상태
     */
    public function scopeCanOrderCancel(Builder $query)
    {
        $query->whereIn('status', OrderStatus::CAN_ORDER_CANCELS);//결제완료, 배송준비중
    }

    public function canOrderCancel()
    {
        return $this->orderProducts->every(fn($e) => in_array($e->status, OrderStatus::CAN_ORDER_CANCELS));
    }

    /**
     * 관리자 주문취소 가능한 상태 (배송중까지 허용)
     */
    public function scopeAdminCanOrderCancel(Builder $query)
    {
        $query->whereIn('status', OrderStatus::ADMIN_CAN_ORDER_CANCELS);
    }

    public function adminCanOrderCancel()
    {
        return $this->orderProducts->every(fn($e) => in_array($e->status, OrderStatus::ADMIN_CAN_ORDER_CANCELS));
    }



    public function getDepositPoints()
    {
        if (auth()->check()) {
            //주문취소시
            if ($this->status === OrderStatus::CANCELLATION_COMPLETE) {
                return [$this->use_points, '주문취소 적립금 반환'];
            }
        }
        return null;
    }

    public function getWithdrawalPoints()
    {
        if ($this->user_id || auth()->check()) {
            if ($this->status === OrderStatus::ORDER_COMPLETE && $this->use_points > 0) {
                return [$this->use_points, '주문사용'];
            }

            /*if ($this->status === OrderStatus::CANCELLATION_COMPLETE) {
                return [$this->purchase_deposit_point, '구매 적립금 차감'];
            }*/
        }
        return null;
    }

    public function complete($data)
    {
        return DB::transaction(function () use ($data) {

            //중복처리 방지
            if ($this->status === OrderStatus::ORDER_COMPLETE) {
                $user = $this->user ?? auth()->user();

                //쿠폰사용
                if ($user && $this->user_coupon_id > 0) {
                    $coupon = $user->availableCoupons()->wherePivot('id', $this->user_coupon_id)->first();
                    $coupon?->pivot->update(['order_id' => $this->id, 'used_at' => now()]);
                }

                //적립금 차감
                if ($user && $this->use_points > 0) {
                    $user->withdrawalPoint($this);
                }

                //재고처리 stock_quantity
                $this->orderProducts->each(function ($e) {
                    $e->productOption()->decrement('stock_quantity', $e->quantity);
                });
            }

            $this->update($data);
            $this->syncStatusOrderProducts();
            return $this;
        });
    }

    public function syncStatusOrderProducts($cancelReason = null)
    {
        $updateData = ['status' => $this->status];
        if ($cancelReason && $this->status === OrderStatus::CANCELLATION_COMPLETE) {
            $updateData['cancel_reason'] = $cancelReason;
        }
        $this->orderProducts()->update($updateData);
    }

    public function cancel($cancelReason = null)
    {
        $this->update([
            'refund_amount' => $this->price,
            'status' => OrderStatus::CANCELLATION_COMPLETE,
            'payment_canceled_at' => now(),
            'cancel_reason' => $cancelReason,
        ]);
        $this->syncStatusOrderProducts($cancelReason);

        //사용한 쿠폰 반환
        if ($this->user_coupon_id > 0) {
            $coupon = auth()->user()->coupons()->wherePivot('id', $this->user_coupon_id)->first();
            $coupon->pivot->update(['order_id' => null, 'used_at' => null]);
        }
        //사용한 적립금 반환
        if ($this->use_points > 0) {
            auth()->user()->depositPoint($this);
        }
        //재고처리 stock_quantity
        $this->orderProducts->each(function ($e) {
            $e->productOption()->increment('stock_quantity', $e->quantity);
        });
    }

}
