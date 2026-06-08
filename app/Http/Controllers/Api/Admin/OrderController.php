<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DeliveryCompany;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\OrderResource;
use App\Models\Iamport;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\SMS;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends ApiController
{

    /**
     * 테스트용 임시
     */
    public function test(Request $request)
    {
        if ($request->input('setup')) {
            $user = User::findOrFail(2);
            $user->update(['email' => 'test@test.com', 'password' => '123456', 'name' => '테스트', 'phone' => '01091767659']);

            $product = Product::findOrFail(2);
            $product->update(['price' => 1000, 'delivery_fee' => 0]);
            $product->options()->update(['price' => 500, 'stock_quantity' => 1000]);
        }

        if (
            $request->input('order_id') && $request->input('order_status')
        ) {

            Order::findOrFail($request->input('order_id'))
                ->update(['status' => $request->input('order_status')]);

            if (
                $request->input('order_product_id') && $request->input('order_product_status')
            ) {
                OrderProduct::findOrFail($request->input('order_product_id'))
                    ->update(['status' => $request->input('order_product_status')]);
            } else {
                OrderProduct::where('order_id', $request->input('order_id'))
                    ->update(['status' => $request->input('order_status')]);
            }

        }

        // SMS 발송 테스트
        if ($request->input('test_sms')) {
            $this->testSmsDeliveryNotification();
            return $this->respondSuccessfully(['message' => 'SMS 테스트 발송 완료']);
        }

        return $this->respondSuccessfully();
    }
    
    /**
     * SMS 배송알림 테스트
     */
    private function testSmsDeliveryNotification()
    {
        // 테스트용 더미 데이터 생성 (DB 접속 없이)
        $orderProduct = new OrderProduct();
        $orderProduct->id = 999;
        $orderProduct->delivery_company = DeliveryCompany::CJ->value;
        $orderProduct->delivery_number = 'TEST123456789';
        $orderProduct->status = OrderStatus::DELIVERY_PREPARING->value;
        
        // 테스트용 주문 데이터
        $order = new Order();
        $order->id = 999;
        $order->buyer_phone = '01030217486';
        $order->buyer_name = '테스트고객';
        
        // 테스트용 상품 데이터
        $product = new Product();
        $product->name = '테스트 사과';
        
        $productOption = new ProductOption();
        $productOption->name = '10kg';
        
        // 관계 설정 (테스트용)
        $orderProduct->setRelation('order', $order);
        $orderProduct->setRelation('product', $product);
        $orderProduct->setRelation('productOption', $productOption);
        
        // OrderProductController의 sendShippingNotification 호출
        $orderProductController = new \App\Http\Controllers\Api\Admin\OrderProductController();
        
        // 리플렉션을 사용하여 private 메서드 호출
        $reflection = new \ReflectionClass($orderProductController);
        $method = $reflection->getMethod('sendShippingNotification');
        $method->setAccessible(true);
        $method->invoke($orderProductController, $orderProduct);
    }

    public function index(Request $request)
    {
        $filters = $request->input('search');

        //테스트 주문 보기 토글: 기본은 테스트 주문 숨김
        $showTest = false;
        if ($filters) {
            $decoded = json_decode($filters);
            $showTest = !empty($decoded->show_test);
        }

        $items = Order::with(['user', 'orderProducts.productOption'])
            //->where('status', '!=', OrderStatus::ORDER_PENDING->value)
            ->whereNotIn('status', [
                OrderStatus::ORDER_PENDING->value,
                OrderStatus::ORDER_COMPLETE->value
            ])
            ->when(!$showTest, fn($q) => $q->where('is_test', false))
            ->search($filters)
            ->latest()
            ->paginate($request->get('itemsPerPage', 10));
        return OrderResource::collection($items);
    }

    /**
     * 테스트 주문 지정/해제 (관리자 수동 마킹)
     */
    public function toggleTest(Request $request, Order $order)
    {
        $data = $request->validate(['is_test' => ['required', 'boolean']]);
        $order->update(['is_test' => $data['is_test']]);
        return $this->respondSuccessfully(OrderResource::make($order));
    }

    public function show(Order $order)
    {
        $order->load(['orderProducts.product']);
        return $this->respondSuccessfully(new OrderResource($order));
    }

    public function cancel(Request $request, $id)
    {
        // 취소사유 검증
        $request->validate([
            'cancel_reason' => 'required|string|max:500'
        ]);
        
        //관리자 주문취소: 모든 주문상품상태가 [결제완료, 배송준비중, 배송중] 일 때 가능
        $order = Order::with('orderProducts')->adminCanOrderCancel()->findOrFail($id);
        if (!$order->adminCanOrderCancel()) {
            $m = '모든 상품이 ' . implode(', ', OrderStatus::getAdminCanOrderCancelValues()) . '에만 주문 취소할 수 있습니다.';
            abort(response()->json(['message' => $m, 'errors' => ['order' => $m]], 403));
        }

        $order = DB::transaction(function () use ($order, $request) {
            if (config('iamport.payment_integration')) {
                $accessToken = Iamport::getAccessToken();
                $result = Iamport::cancel($accessToken, $order->imp_uid);
                if (!$result['response']) abort(403, $result['message']);
            }
            
            // 취소사유와 함께 주문 취소
            $order->cancel($request->cancel_reason);
            
            // SMS 발송
            $this->sendCancellationNotification($order, $request->cancel_reason);
            
            return $order;
        });
        return $this->respondSuccessfully(OrderResource::make($order));
    }

    /**
     * 주문 취소 알림 SMS 발송
     */
    private function sendCancellationNotification(Order $order, $cancelReason)
    {
        try {
            // 수신자 전화번호 확인
            $phone = $order->buyer_contact ?? $order->user?->phone;
            if (!$phone) {
                return;
            }
            
            // 메시지 구성
            $message = "[열매나무] 주문 취소 안내\n";
            $message .= "주문번호: {$order->merchant_uid}\n";
            $message .= "취소사유: {$cancelReason}\n";
            $message .= "문의: 010-xxxx-xxxx";
            
            // SMS 발송
            $sms = new SMS();
            $result = $sms->send($phone, '열매나무 주문취소 안내', $message);
            
            // 디버깅을 위한 로그
            \Log::info('주문 취소 SMS 발송 결과', [
                'order_id' => $order->id,
                'phone' => $phone,
                'result' => $result instanceof \Illuminate\Http\JsonResponse ? $result->getData() : $result
            ]);
            
        } catch (\Exception $e) {
            // SMS 발송 실패 시 로그 기록 (비즈니스 로직은 계속 진행)
            \Log::error('주문 취소 SMS 발송 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate(['status' => ['required', 'in:' . implode(',', OrderStatus::values())]]);
        $order->update($data);
        $order->syncStatusOrderProducts();
        return $this->respondSuccessfully();
    }

    /**
     * 일괄 배송준비 처리
     * 선택된 주문들의 주문상품을 배송준비 상태로 변경
     */
    public function bulkPrepareDelivery(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:orders,id'
        ]);

        $orderIds = $request->input('ids');

        // 배송준비 가능한 주문만 필터링
        $orders = Order::with('orderProducts')
            ->whereIn('id', $orderIds)
            ->get();

        $successCount = 0;
        $failedOrders = [];

        DB::transaction(function () use ($orders, &$successCount, &$failedOrders) {
            foreach ($orders as $order) {
                // 주문의 모든 상품이 배송준비 가능한 상태인지 확인
                $canPrepare = $order->orderProducts->every(function ($orderProduct) {
                    return in_array($orderProduct->status, [
                        OrderStatus::PAYMENT_COMPLETE,
                    ]);
                });

                if ($canPrepare) {
                    // 주문상품들을 배송준비 상태로 변경
                    $order->orderProducts->each(function ($orderProduct) {
                        if ($orderProduct->status === OrderStatus::PAYMENT_COMPLETE) {
                            $orderProduct->update([
                                'status' => OrderStatus::DELIVERY_PREPARING
                            ]);
                        }
                    });

                    // 주문 상태도 배송준비로 변경
                    $order->update([
                        'status' => OrderStatus::DELIVERY_PREPARING
                    ]);

                    $successCount++;
                } else {
                    $failedOrders[] = [
                        'id' => $order->id,
                        'merchant_uid' => $order->merchant_uid,
                        'reason' => '결제완료 상태가 아닌 상품이 포함되어 있습니다.'
                    ];
                }
            }
        });

        return $this->respondSuccessfully([
            'success_count' => $successCount,
            'failed_orders' => $failedOrders,
            'message' => "{$successCount}건의 주문이 배송준비 상태로 변경되었습니다."
        ]);
    }
}
