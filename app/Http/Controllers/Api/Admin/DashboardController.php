<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExchangeReturnStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\ApiController;
use App\Models\ExchangeReturn;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Point;
use App\Models\Product;
use App\Models\ProductInquiry;
use App\Models\ProductReview;
use App\Models\Sweetness;
use App\Models\User;
use App\Models\UserCoupon;
use App\Models\VisitorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends ApiController
{
    //매출 집계 대상 주문 상태
    const REVENUE_STATUSES = [
        OrderStatus::PAYMENT_COMPLETE,
        OrderStatus::DELIVERY_PREPARING,
        OrderStatus::DELIVERY,
        OrderStatus::DELIVERY_COMPLETE,
        OrderStatus::PURCHASE_CONFIRM,
    ];

    /**
     * 대시보드 요약
     */
    public function summary(Request $request)
    {
        $days = (int)$request->input('days', 7);
        if (!in_array($days, [7, 30])) $days = 7;

        //매출
        $revenue = [
            'today' => (int)Order::whereDate('created_at', now()->toDateString())
                ->whereIn('status', self::REVENUE_STATUSES)
                ->where('is_test', false)
                ->sum('price'),
            'month' => (int)Order::where('created_at', '>=', now()->startOfMonth())
                ->whereIn('status', self::REVENUE_STATUSES)
                ->where('is_test', false)
                ->sum('price'),
            'refund_month' => (int)Order::where('status', OrderStatus::CANCELLATION_COMPLETE)
                ->where('is_test', false)
                ->where('payment_canceled_at', '>=', now()->startOfMonth())
                ->sum('refund_amount'),
        ];

        //일별 매출 추이 (없는 날짜는 0 채움)
        $startDate = now()->subDays($days - 1)->startOfDay();
        $dailySales = Order::selectRaw('DATE(created_at) as date, SUM(price) as amount, COUNT(*) as orders_count')
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('is_test', false)
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $dailySales->get($date);
            $trend[] = [
                'date' => $date,
                'amount' => (int)($row->amount ?? 0),
                'orders_count' => (int)($row->orders_count ?? 0),
            ];
        }

        //주문 단계별 건수
        $countOrders = fn($status) => Order::where('status', $status)->where('is_test', false)->count();
        $orderStages = [
            'new_orders' => $countOrders(OrderStatus::PAYMENT_COMPLETE),
            'preparing' => $countOrders(OrderStatus::DELIVERY_PREPARING),
            'shipping' => $countOrders(OrderStatus::DELIVERY),
            'delivered' => $countOrders(OrderStatus::DELIVERY_COMPLETE),
            'confirmed' => $countOrders(OrderStatus::PURCHASE_CONFIRM),
        ];

        //CS
        $cs = [
            'inquiries_unanswered' => Inquiry::whereNull('answered_at')->count(),
            'product_inquiries_unanswered' => ProductInquiry::whereNull('answered_at')->count(),
            'low_reviews' => ProductReview::where('rating', '<=', 3)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];

        //클레임 (취소요청 주문 + 미완료 교환/반품 접수)
        $pendingExchangeReturnStatuses = [
            ExchangeReturnStatus::RECEIVED->value,
            ExchangeReturnStatus::APPROVED->value,
            ExchangeReturnStatus::PROCESSING->value,
        ];
        $claims = [
            'cancel' => $countOrders(OrderStatus::CANCELLATION_REQUESTED),
            'return' => ExchangeReturn::where('type', ExchangeReturn::TYPE_RETURN)
                ->whereIn('status', $pendingExchangeReturnStatuses)->count(),
            'exchange' => ExchangeReturn::where('type', ExchangeReturn::TYPE_EXCHANGE)
                ->whereIn('status', $pendingExchangeReturnStatuses)->count(),
        ];

        //출고지연: 결제완료 후 N일 경과했는데 아직 출고 전(결제완료/배송준비중)인 주문상품
        $countDelayed = function ($daysAgo) {
            return OrderProduct::join('orders', 'order_products.order_id', '=', 'orders.id')
                ->whereIn('order_products.status', [OrderStatus::PAYMENT_COMPLETE->value, OrderStatus::DELIVERY_PREPARING->value])
                ->where('orders.is_test', false)
                ->whereNull('orders.deleted_at')
                ->whereNotNull('orders.payment_completed_at')
                ->where('orders.payment_completed_at', '<=', now()->subDays($daysAgo))
                ->count();
        };
        $delayed = [
            'd3' => $countDelayed(3),
            'd5' => $countDelayed(5),
            'd7' => $countDelayed(7),
        ];

        //상품 (품절 = 노출중이지만 재고 있는 옵션이 없음)
        $hasStockOption = fn($query) => $query->where('stock_quantity', '>', 0);
        $products = [
            'selling' => Product::where('is_display', true)->whereHas('options', $hasStockOption)->count(),
            'soldout' => Product::where('is_display', true)->whereDoesntHave('options', $hasStockOption)->count(),
            'hidden' => Product::where('is_display', false)->count(),
            'new_week' => Product::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        //당도 (missing = 판매중 상품 중 최신 측정일에 측정된 과일이 없는 상품 수)
        $latestSweetnessDate = Sweetness::where('is_display', true)->max(DB::raw('DATE(created_at)'));
        $avgBrix = $latestSweetnessDate
            ? round((float)Sweetness::where('is_display', true)->whereDate('created_at', $latestSweetnessDate)->avg('sweetness'), 1)
            : null;
        $measuredFruitNames = $latestSweetnessDate
            ? Sweetness::where('is_display', true)->whereDate('created_at', $latestSweetnessDate)->pluck('fruit_name')
            : collect();
        $missing = Product::where('is_display', true)->whereHas('options', $hasStockOption)->pluck('name')
            ->filter(function ($productName) use ($measuredFruitNames) {
                return !$measuredFruitNames->contains(function ($fruitName) use ($productName) {
                    return $fruitName !== '' && (str_contains($productName, $fruitName) || str_contains($fruitName, $productName));
                });
            })->count();
        $sweetness = [
            'avg_brix' => $avgBrix,
            'missing' => $missing,
        ];

        //회원
        $members = [
            'new_week' => User::where('is_admin', false)->where('created_at', '>=', now()->subDays(7))->count(),
            'total' => User::where('is_admin', false)->count(),
            'points_used_week' => (int)Point::where('created_at', '>=', now()->subDays(7))->sum('withdrawal'),
            'coupons_used_week' => UserCoupon::whereNotNull('used_at')->where('used_at', '>=', now()->subDays(7))->count(),
        ];

        return $this->respondSuccessfully([
            'revenue' => $revenue,
            'trend' => $trend,
            'order_stages' => $orderStages,
            'cs' => $cs,
            'claims' => $claims,
            'delayed' => $delayed,
            'products' => $products,
            'sweetness' => $sweetness,
            'members' => $members,
        ]);
    }

    /**
     * 사이드바 뱃지 카운트
     */
    public function badges()
    {
        return $this->respondSuccessfully([
            'orders_new' => Order::where('status', OrderStatus::PAYMENT_COMPLETE)->where('is_test', false)->count(),
            'shipments_waiting' => OrderProduct::where('status', OrderStatus::DELIVERY_PREPARING)
                ->whereHas('order', fn($query) => $query->where('is_test', false))
                ->count(),
            'product_inquiries_unanswered' => ProductInquiry::whereNull('answered_at')->count(),
            'product_reviews_new' => ProductReview::where('created_at', '>=', now()->subDays(7))->count(),
            'inquiries_unanswered' => Inquiry::whereNull('answered_at')->count(),
        ]);
    }

    /**
     * 대시보드 통계 데이터 조회
     */
    public function statistics(Request $request)
    {
        $startDate = now()->subDays(29)->startOfDay(); // 최근 30일
        $today = now()->toDateString();
        
        // 1. 최근 30일 일일 방문자수
        $dailyVisitors = VisitorLog::select(
                DB::raw('DATE(visit_date) as date'),
                DB::raw('COUNT(DISTINCT ip_address) as visitors')
            )
            ->where('visit_date', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'visitors' => $item->visitors
                ];
            });
        
        // 2. 최근 30일 일일 매출액
        $dailySales = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as sales')
            )
            ->whereIn('status', [
                OrderStatus::PAYMENT_COMPLETE->value,
                OrderStatus::DELIVERY_PREPARING->value,
                OrderStatus::DELIVERY->value,
                OrderStatus::DELIVERY_COMPLETE->value,
                OrderStatus::PURCHASE_CONFIRM->value
            ])
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'sales' => (int)$item->sales
                ];
            });
        
        // 3. 오늘의 주문건수
        $todayOrderCount = Order::whereDate('created_at', $today)
            ->whereNotIn('status', [
                OrderStatus::ORDER_PENDING->value,
                OrderStatus::PAYMENT_FAIL->value
            ])
            ->count();
        
        // 4. 오늘의 주문액
        $todayOrderAmount = Order::whereDate('created_at', $today)
            ->whereIn('status', [
                OrderStatus::PAYMENT_COMPLETE->value,
                OrderStatus::DELIVERY_PREPARING->value,
                OrderStatus::DELIVERY->value,
                OrderStatus::DELIVERY_COMPLETE->value,
                OrderStatus::PURCHASE_CONFIRM->value
            ])
            ->sum('total_amount');
        
        // 5. 전체 방문자수 (최근 30일)
        $totalVisitors = VisitorLog::where('visit_date', '>=', $startDate)
            ->distinct('ip_address')
            ->count('ip_address');
        
        // 6. 전체 매출액 (최근 30일)
        $totalSales = Order::whereIn('status', [
                OrderStatus::PAYMENT_COMPLETE->value,
                OrderStatus::DELIVERY_PREPARING->value,
                OrderStatus::DELIVERY->value,
                OrderStatus::DELIVERY_COMPLETE->value,
                OrderStatus::PURCHASE_CONFIRM->value
            ])
            ->where('created_at', '>=', $startDate)
            ->sum('total_amount');
        
        // 날짜 범위 생성 (데이터가 없는 날짜도 0으로 표시)
        $dateRange = [];
        for ($i = 29; $i >= 0; $i--) {
            $dateRange[] = now()->subDays($i)->format('Y-m-d');
        }
        
        // 방문자 데이터 채우기
        $visitorsByDate = $dailyVisitors->pluck('visitors', 'date')->toArray();
        $filledVisitors = [];
        foreach ($dateRange as $date) {
            $filledVisitors[] = [
                'date' => $date,
                'visitors' => $visitorsByDate[$date] ?? 0
            ];
        }
        
        // 매출 데이터 채우기
        $salesByDate = $dailySales->pluck('sales', 'date')->toArray();
        $filledSales = [];
        foreach ($dateRange as $date) {
            $filledSales[] = [
                'date' => $date,
                'sales' => $salesByDate[$date] ?? 0
            ];
        }
        
        return $this->respondSuccessfully([
            'daily_visitors' => $filledVisitors,
            'daily_sales' => $filledSales,
            'today_order_count' => $todayOrderCount,
            'today_order_amount' => (int)$todayOrderAmount,
            'total_visitors' => $totalVisitors,
            'total_sales' => (int)$totalSales,
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => now()->format('Y-m-d')
            ]
        ]);
    }
}
