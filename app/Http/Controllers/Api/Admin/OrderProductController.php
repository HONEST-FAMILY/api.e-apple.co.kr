<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DeliveryCompany;
use App\Enums\ExchangeReturnStatus;
use App\Enums\OrderStatus;
use App\Exports\OrderProductInvoiceTemplateExport;
use App\Exports\OrderProductsExport;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\OrderProductRequest;
use App\Http\Resources\OrderProductResource;
use App\Imports\OrderProductInvoicesImport;
use App\Models\ExchangeReturn;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\SMS;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class OrderProductController extends ApiController
{
    public function init()
    {
        $deliveryCompanyItems = DeliveryCompany::getItems();
        return response()->json(compact('deliveryCompanyItems'));
    }

    public function index(Request $request)
    {
        $filters = $request->input('search');
        $items = OrderProduct::with(['order.user', 'product', 'productOption'])
            ->whereIn('status', OrderStatus::CAN_DELVERY_MANAGES)
            ->search($filters)
            ->latest()->paginate($request->get('itemsPerPage', 10));
        return OrderProductResource::collection($items);
    }

    public function update(OrderProductRequest $request, $id = null)
    {
        $data = $request->validated();

        // 배송중 상태로 변경되는 경우 SMS 발송 처리
        $isShippingStart = isset($data['status']) && $data['status'] === OrderStatus::DELIVERY->value;

        if ($id > 0) {
            $this->registerInvoice($id, $data);
        }

        if (!empty($data['ids']) && count($data['ids']) > 0) {
            $ids = $data['ids'];
            unset($data['ids']);

            OrderProduct::where('status', OrderStatus::DELIVERY_PREPARING)
                ->whereIn('id', $ids)
                ->update($data);

            // 배송 시작 SMS 발송 (일괄처리) - 업데이트 후 다시 조회하여 최신 데이터로 발송
            if ($isShippingStart) {
                $orderProducts = OrderProduct::whereIn('id', $ids)->get();
                foreach ($orderProducts as $orderProduct) {
                    $this->sendShippingNotification($orderProduct);
                }
            }
        }

        return $this->respondSuccessfully();
    }

    /**
     * 출고처리(운송장 등록): 배송준비중 건만 처리, 배송중 전환 시 SMS 발송
     */
    private function registerInvoice($id, array $data): OrderProduct
    {
        $orderProduct = OrderProduct::where('status', OrderStatus::DELIVERY_PREPARING)->findOrFail($id);
        $orderProduct->update($data);

        // 배송 시작 SMS 발송
        if (($data['status'] ?? null) === OrderStatus::DELIVERY->value) {
            $this->sendShippingNotification($orderProduct);
        }

        return $orderProduct;
    }

    /**
     * 일괄 반품접수: 선택한 출고(배송중)에 반품 요청을 등록 (고객 접수와 동일 단위, 환불 처리는 교환/반품관리에서 진행)
     */
    public function bulkReturn(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:order_products,id',
            'problem' => 'required|string',
            'description' => 'required|string|max:1000',
        ]);

        $orderProducts = OrderProduct::with('order')->whereIn('id', $request->input('ids'))->get();

        $requested = 0;
        $skipped = 0;

        DB::transaction(function () use ($orderProducts, $request, &$requested, &$skipped) {
            foreach ($orderProducts as $orderProduct) {
                //고객 접수와 동일하게 배송중만 가능, 비회원 주문은 회원 연결이 없어 제외
                if ($orderProduct->status !== OrderStatus::DELIVERY || !$orderProduct->order?->user_id) {
                    $skipped++;
                    continue;
                }

                $orderProduct->exchangeReturns()->create([
                    'user_id' => $orderProduct->order->user_id,
                    'order_id' => $orderProduct->order_id,
                    'type' => ExchangeReturn::TYPE_RETURN,
                    'problem' => $request->input('problem'),
                    'description' => $request->input('description'),
                    'status' => ExchangeReturnStatus::RECEIVED->value,
                ]);
                $orderProduct->update(['status' => OrderStatus::RETURN_REQUESTED]);
                $requested++;
            }
        });

        return $this->respondSuccessfully(['requested' => $requested, 'skipped' => $skipped]);
    }

    /**
     * 운송장 일괄등록 (A=출고ID, B=택배사, C=운송장번호 / 1행 헤더)
     */
    public function importInvoices(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt'],
        ]);

        $sheets = Excel::toArray(new OrderProductInvoicesImport(), $request->file('file'));
        $rows = $sheets[0] ?? [];

        $success = 0;
        $failed = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; //1행은 헤더

            $id = trim((string)($row[0] ?? ''));
            $companyInput = trim((string)($row[1] ?? ''));
            //엑셀 숫자 셀은 float 으로 읽히므로 지수표기 없이 문자열로 환원
            $trackingNumber = is_numeric($row[2] ?? null)
                ? number_format((float)$row[2], 0, '', '')
                : trim((string)($row[2] ?? ''));

            if ($id === '' && $companyInput === '' && $trackingNumber === '') continue; //빈 행 무시

            if (!is_numeric($id)) {
                $failed[] = ['row' => $rowNumber, 'message' => '출고ID를 확인해주세요.'];
                continue;
            }

            $deliveryCompany = DeliveryCompany::tryFrom(strtolower($companyInput))
                ?? collect(DeliveryCompany::cases())->first(fn($case) => $case->label() === $companyInput);
            if (!$deliveryCompany) {
                $failed[] = ['row' => $rowNumber, 'message' => '택배사를 확인해주세요. (' . implode(', ', DeliveryCompany::values()) . ' 또는 택배사명)'];
                continue;
            }

            if ($trackingNumber === '') {
                $failed[] = ['row' => $rowNumber, 'message' => '운송장번호를 확인해주세요.'];
                continue;
            }

            try {
                $this->registerInvoice((int)$id, [
                    'status' => OrderStatus::DELIVERY->value,
                    'delivery_company' => $deliveryCompany->value,
                    'delivery_tracking_number' => $trackingNumber,
                    'delivery_started_at' => now(),
                ]);
                $success++;
            } catch (ModelNotFoundException $e) {
                $failed[] = ['row' => $rowNumber, 'message' => '배송준비중 상태의 출고건을 찾을 수 없습니다.'];
            } catch (\Throwable $e) {
                $failed[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }

        return $this->respondSuccessfully(['success' => $success, 'failed' => $failed]);
    }

    /**
     * 운송장 일괄등록 양식 다운로드
     */
    public function importTemplate()
    {
        return Excel::download(new OrderProductInvoiceTemplateExport(), 'invoice_import_template.xlsx');
    }

    /**
     * 배송 시작 알림 문자 발송
     */
    private function sendShippingNotification(OrderProduct $orderProduct)
    {
        try {
            // 관련 정보 로드 (이미 로드되어 있지 않은 경우에만)
            if (!$orderProduct->relationLoaded('order')) {
                $orderProduct->load(['order.user', 'product', 'productOption']);
            }
            $order = $orderProduct->order;
            
            // 수신자 전화번호 확인
            $phone = $order->buyer_phone ?? $order->user?->phone;
            if (!$phone) {
                return;
            }
            
            // 택배사 정보
            $deliveryCompanyName = '';
            if ($orderProduct->delivery_company) {
                $deliveryCompany = DeliveryCompany::tryFrom($orderProduct->delivery_company);
                $deliveryCompanyName = $deliveryCompany?->label() ?? $orderProduct->delivery_company;
            }
            
            // 상품명 구성
            $productName = $orderProduct->product->name;
            if ($orderProduct->productOption) {
                $productName .= ' (' . $orderProduct->productOption->name . ')';
            }
            
            // 메시지 구성
            $message = "안녕하세요 고객님, 열매나무를 이용해주셔서 감사합니다. 고객님의 상품이 아래와 같이 출고될 예정이니 참고 부탁드립니다.\n\n";
            $message .= "# 출고정보\n";
            $message .= "- 택배사 : {$deliveryCompanyName}\n";
            $message .= "- 운송장번호 : {$orderProduct->delivery_tracking_number}\n";
            $message .= "- 출고상품 : {$productName}";
            
            // SMS 발송
            $sms = new SMS();
            $result = $sms->send($phone, '열매나무 배송안내', $message);
            
            // 디버깅을 위한 로그
            \Log::info('SMS 발송 결과', [
                'phone' => $phone,
                'result' => $result instanceof \Illuminate\Http\JsonResponse ? $result->getData() : $result
            ]);
            
        } catch (\Exception $e) {
            // SMS 발송 실패 시 로그 기록 (비즈니스 로직은 계속 진행)
            \Log::error('배송 알림 SMS 발송 실패', [
                'order_product_id' => $orderProduct->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 개별 상품 취소
     */
    public function cancel(Request $request, $id)
    {
        // 취소사유 검증
        $request->validate([
            'cancel_reason' => 'required|string|max:500'
        ]);
        
        $orderProduct = OrderProduct::with(['order', 'product', 'productOption'])
            ->whereIn('status', OrderStatus::ADMIN_CAN_ORDER_CANCELS)
            ->findOrFail($id);

        if (!in_array($orderProduct->status, OrderStatus::ADMIN_CAN_ORDER_CANCELS)) {
            $m = '상품이 ' . implode(', ', OrderStatus::getAdminCanOrderCancelValues()) . ' 상태일 때만 취소할 수 있습니다.';
            abort(response()->json(['message' => $m, 'errors' => ['order_product' => $m]], 403));
        }

        DB::transaction(function () use ($orderProduct, $request) {
            $order = $orderProduct->order;

            // 부분환불 금액 및 상세 내역 계산
            $refundData = $this->calculatePartialRefundAmount($order, $orderProduct);

            // 개별 상품 취소 처리
            $orderProduct->update([
                'status' => OrderStatus::CANCELLATION_COMPLETE,
                'cancel_reason' => $request->cancel_reason,
                'refund_amount' => $refundData['refund_amount'],
                'cancel_detail' => $refundData['cancel_detail']
            ]);

            // 재고 복원
            $orderProduct->productOption()->increment('stock_quantity', $orderProduct->quantity);

            // 주문 상태 및 환불 금액 업데이트
            $this->updateOrderStatus($order);

            // 아임포트 부분환불 처리
            $this->processPartialRefund($order, $refundData['refund_amount']);

            // SMS 발송
            $this->sendOrderProductCancellationNotification($orderProduct, $request->cancel_reason);
        });

        return $this->respondSuccessfully(OrderProductResource::make($orderProduct));
    }

    /**
     * 부분환불 금액 계산
     */
    private function calculatePartialRefundAmount(Order $order, OrderProduct $orderProduct): array
    {
        // 전체 상품 가격 계산 (취소되지 않은 상품들)
        $totalProductPrice = $order->orderProducts
            ->where('status', '!=', OrderStatus::CANCELLATION_COMPLETE->value)
            ->sum('price');

        // 취소할 상품의 가격 비율 계산
        $cancelRatio = $totalProductPrice > 0 ? $orderProduct->price / $totalProductPrice : 0;

        // 할인 금액 배분 계산
        $couponDiscount = $order->coupon_discount_amount ?? 0;
        $pointsUsed = $order->use_points ?? 0;
        $totalDiscount = $couponDiscount + $pointsUsed;

        $allocatedCouponDiscount = round($couponDiscount * $cancelRatio);
        $allocatedPointsDiscount = round($pointsUsed * $cancelRatio);
        $allocatedTotalDiscount = $allocatedCouponDiscount + $allocatedPointsDiscount;

        // 부분 환불 금액 = 상품가격 - 할당된 할인금액 (정수 변환)
        $refundAmount = (int) max(0, $orderProduct->price - $allocatedTotalDiscount);

        // 상세 내역 생성
        $cancelDetail = $this->generateCancelDetail($order, $orderProduct, [
            'product_price' => $orderProduct->price,
            'cancel_ratio' => $cancelRatio,
            'coupon_discount' => $allocatedCouponDiscount,
            'points_discount' => $allocatedPointsDiscount,
            'total_discount' => $allocatedTotalDiscount,
            'refund_amount' => $refundAmount
        ]);

        return [
            'refund_amount' => $refundAmount,
            'cancel_detail' => $cancelDetail
        ];
    }

    /**
     * 취소 상세 내역 생성 (고객 안내용)
     */
    private function generateCancelDetail(Order $order, OrderProduct $orderProduct, array $calculation): string
    {
        $productName = $orderProduct->product->name ?? '상품';
        $optionName = $orderProduct->productOption->name ?? '';
        $fullProductName = $optionName ? "{$productName} ({$optionName})" : $productName;

        $detail = "■ 부분취소 환불 상세내역\n\n";
        $detail .= "▶ 취소 상품: {$fullProductName}\n";
        $detail .= "▶ 취소 수량: {$orderProduct->quantity}개\n";
        $detail .= "▶ 상품 가격: " . number_format($calculation['product_price']) . "원\n\n";

        if ($calculation['coupon_discount'] > 0 || $calculation['points_discount'] > 0) {
            $detail .= "▶ 할인 배분 (전체 주문 대비 " . number_format($calculation['cancel_ratio'] * 100, 1) . "% 비율)\n";

            if ($calculation['coupon_discount'] > 0) {
                $detail .= "  • 쿠폰 할인: -" . number_format($calculation['coupon_discount']) . "원\n";
            }

            if ($calculation['points_discount'] > 0) {
                $detail .= "  • 적립금 사용: -" . number_format($calculation['points_discount']) . "원\n";
            }

            $detail .= "  • 총 할인: -" . number_format($calculation['total_discount']) . "원\n\n";
        }

        $detail .= "▶ 실제 환불금액: " . number_format($calculation['refund_amount']) . "원\n\n";
        $detail .= "※ 본 환불은 비례 계산에 의해 산정되었습니다.\n";
        $detail .= "※ 환불은 원결제 수단으로 처리됩니다.\n";
        $detail .= "※ 환불 처리까지 영업일 기준 3-5일 소요될 수 있습니다.";

        return $detail;
    }

    /**
     * 주문 상태 및 환불 금액 업데이트
     */
    private function updateOrderStatus(Order $order): void
    {
        // 현재 주문의 모든 상품 상태 확인
        $order->refresh();
        $orderProducts = $order->orderProducts;

        $canceledProducts = $orderProducts->where('status', OrderStatus::CANCELLATION_COMPLETE->value);
        $totalProducts = $orderProducts->count();

        // 환불 총액 계산
        $totalRefundAmount = $canceledProducts->sum('refund_amount');

        if ($canceledProducts->count() === $totalProducts) {
            // 모든 상품이 취소된 경우
            $order->update([
                'status' => OrderStatus::CANCELLATION_COMPLETE,
                'refund_amount' => $totalRefundAmount
            ]);
        } else {
            // 일부 상품만 취소된 경우
            $order->update([
                'refund_amount' => $totalRefundAmount
            ]);
        }
    }

    /**
     * 아임포트 부분환불 처리
     */
    private function processPartialRefund(Order $order, int $refundAmount): void
    {
        // 아임포트 결제 연동이 활성화되어 있고, 환불할 금액이 있는 경우에만 처리
        if (!config('iamport.payment_integration') || $refundAmount <= 0) {
            return;
        }

        try {
            $accessToken = \App\Models\Iamport::getAccessToken();
            $result = \App\Models\Iamport::cancel($accessToken, $order->imp_uid, $refundAmount, "부분취소 환불");

            if (!$result['response']) {
                // 부분환불 실패시 로그 기록 (실제 환불은 수동 처리)
                \Log::warning("부분환불 실패 - 주문: {$order->id}, 금액: {$refundAmount}원, 사유: " . ($result['message'] ?? '알 수 없음'));
            }
        } catch (\Exception $e) {
            \Log::error("부분환불 API 오류 - 주문: {$order->id}, 오류: " . $e->getMessage());
        }
    }

    /**
     * 개별 상품 취소 알림 SMS 발송
     */
    private function sendOrderProductCancellationNotification(OrderProduct $orderProduct, $cancelReason)
    {
        try {
            $order = $orderProduct->order;
            
            // 수신자 전화번호 확인
            $phone = $order->buyer_contact ?? $order->user?->phone;
            if (!$phone) {
                return;
            }
            
            // 상품명 구성
            $productName = $orderProduct->product->name;
            if ($orderProduct->productOption) {
                $productName .= ' (' . $orderProduct->productOption->name . ')';
            }
            
            // 메시지 구성
            $message = "[열매나무] 주문 취소 안내\n";
            $message .= "주문번호: {$order->merchant_uid}\n";
            $message .= "취소상품: {$productName}\n";
            $message .= "취소사유: {$cancelReason}\n";
            $message .= "문의: 010-xxxx-xxxx";
            
            // SMS 발송
            $sms = new SMS();
            $result = $sms->send($phone, '열매나무 주문취소 안내', $message);
            
            // 디버깅을 위한 로그
            \Log::info('개별 상품 취소 SMS 발송 결과', [
                'order_product_id' => $orderProduct->id,
                'phone' => $phone,
                'result' => $result instanceof \Illuminate\Http\JsonResponse ? $result->getData() : $result
            ]);
            
        } catch (\Exception $e) {
            // SMS 발송 실패 시 로그 기록 (비즈니스 로직은 계속 진행)
            \Log::error('개별 상품 취소 SMS 발송 실패', [
                'order_product_id' => $orderProduct->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function export(Request $request)
    {
        return Excel::download(new OrderProductsExport($request), 'order_products_' . date('Y-m-d_His') . '.xlsx');
    }

    /**
     * 배송정보(택배사, 송장번호) 수정 - 배송중 상태에서도 수정 가능
     */
    public function updateDeliveryInfo(Request $request, $id)
    {
        $request->validate([
            'delivery_company' => 'required|string',
            'delivery_tracking_number' => 'required|string',
        ]);

        // 출고준비 또는 배송중 상태에서만 수정 가능
        $orderProduct = OrderProduct::whereIn('status', [
            OrderStatus::DELIVERY_PREPARING,
            OrderStatus::DELIVERY
        ])->findOrFail($id);

        $orderProduct->update([
            'delivery_company' => $request->delivery_company,
            'delivery_tracking_number' => $request->delivery_tracking_number,
        ]);

        return $this->respondSuccessfully(OrderProductResource::make($orderProduct));
    }

}
