<?php

use App\Http\Controllers\Api\Admin\BannerController;
use App\Http\Controllers\Api\Admin\PopBannerController;
use App\Http\Controllers\Api\Admin\CodeController;
use App\Http\Controllers\Api\Admin\CouponController;
use App\Http\Controllers\Api\Admin\DeliveryAddressController;
use App\Http\Controllers\Api\Admin\ExchangeReturnController;
use App\Http\Controllers\Api\Admin\InquiryController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\OrderProductController;
use App\Http\Controllers\Api\Admin\PointController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductInquiryController;
use App\Http\Controllers\Api\Admin\ProductPackageController;
use App\Http\Controllers\Api\Admin\ProductReviewController;
use App\Http\Controllers\Api\Admin\SweetnessController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\UserCouponController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'admin'], function () {

    Route::group(['prefix' => 'auth', 'controller' => AuthController::class], function () {
        /**
         * FOR JWT
         */
        Route::middleware('auth:api')->get('/user', function (Request $request) {
            return $request->user();
        });
        Route::post('login', 'login');
    });
    
    // Test route - admin 미들웨어 밖에 배치
    Route::post('orders/test', [OrderController::class, 'test']);

    Route::group(['middleware' => 'admin'], function () {

        Route::group(['prefix' => 'auth', 'controller' => \App\Http\Controllers\Api\Admin\AuthController::class],
            function () {
                Route::get('profile', 'profile');
                Route::put('profile', 'update');
            });
        
        // 대시보드 통계
        Route::get('dashboard/statistics', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'statistics']);
        Route::get('dashboard/summary', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'summary']);
        Route::get('dashboard/badges', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'badges']);

        Route::group(['prefix' => 'users', 'controller' => UserController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{user}', 'show');
                Route::put('{user}', 'update');
                Route::delete('{user}', 'destroy');
            });

        Route::group(['prefix' => 'products', 'controller' => ProductController::class],
            function () {
                Route::get('init', 'init');
                Route::get('sales', 'sales');
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{product}', 'show');
                Route::put('{product}', 'update');
                Route::delete('{product}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
                Route::delete('options/{productOption}', 'destroyOption');
                Route::post('images', 'storeImages');
            });

        Route::group(['prefix' => 'product_inquiries', 'controller' => ProductInquiryController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{productInquiry}', 'show');
                Route::put('{productInquiry}', 'update');
                Route::delete('{productInquiry}', 'destroy');
            });

        Route::group(['prefix' => 'product_reviews', 'controller' => ProductReviewController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{productReview}', 'show');
                Route::put('{productReview}', 'update');
                Route::delete('{productReview}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'product_packages', 'controller' => ProductPackageController::class],
            function () {
                Route::get('init', 'init');
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{productPackage}', 'show');
                Route::put('{productPackage}', 'update');
                Route::delete('{productPackage}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'orders', 'controller' => OrderController::class],
            function () {
                Route::get('', 'index');
                Route::get('status-counts', 'statusCounts');
                Route::put('bulk-confirm', 'bulkConfirm');
                Route::put('bulk-cancel', 'bulkCancel');
                Route::post('bulk-sms', 'bulkSms');
                Route::get('{order}', 'show');
                Route::put('{order}', 'update');
                Route::put('{order}/cancel', 'cancel');
                Route::put('{order}/test', 'toggleTest');
                Route::post('bulk-prepare-delivery', 'bulkPrepareDelivery');
            });

        Route::group(['prefix' => 'order_products', 'controller' => OrderProductController::class],
            function () {
                Route::get('init', 'init');
                Route::get('', 'index');
                Route::get('export', 'export');
                Route::get('import-template', 'importTemplate');
                Route::post('import-invoices', 'importInvoices');
                Route::put('{id?}', 'update');
                Route::put('{id}/cancel', 'cancel');
                Route::put('{id}/delivery-info', 'updateDeliveryInfo');
            });

        Route::group(['prefix' => 'exchange_returns', 'controller' => ExchangeReturnController::class],
            function () {
                Route::get('init', 'init');
                Route::get('', 'index');
                Route::get('{exchangeReturn}', 'show');
                Route::put('{exchangeReturn}', 'update');
            });

        Route::group(['prefix' => 'banners', 'controller' => BannerController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{banner}', 'show');
                Route::put('{banner}', 'update');
                Route::delete('{banner}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'popBanners', 'controller' => PopBannerController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{popBanner}', 'show');
                Route::put('{popBanner}', 'update');
                Route::delete('{popBanner}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'sweetnesses', 'controller' => SweetnessController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{sweetness}', 'show');
                Route::put('{sweetness}', 'update');
                Route::delete('{sweetness}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'inquiries', 'controller' => InquiryController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{inquiry}', 'show');
                Route::put('{inquiry}', 'update');
                Route::delete('{inquiry}', 'destroy');
                Route::delete('images/{media}', 'destroyImage');
            });

        Route::group(['prefix' => 'delivery_addresses', 'controller' => DeliveryAddressController::class],
            function () {
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{deliveryAddress}', 'show');
                Route::put('{deliveryAddress}', 'update');
                Route::delete('{deliveryAddress}', 'destroy');
            });

        Route::group(['prefix' => 'coupons', 'controller' => CouponController::class],
            function () {
                Route::get('init', 'init');
                Route::get('', 'index');
                Route::post('', 'store');
                Route::get('{coupon}', 'show');
                Route::put('{coupon}', 'update');
                Route::delete('{coupon}', 'destroy');
            });

        Route::group(['prefix' => 'user_coupons', 'controller' => UserCouponController::class],
            function () {
                Route::get('', 'index');
            });

        Route::group(['prefix' => 'points', 'controller' => PointController::class],
            function () {
                Route::get('', 'index');
            });

        Route::group(['prefix' => 'categories', 'controller' => CodeController::class],
            function () {
                Route::get('{parentId}', 'index');
                Route::post('', 'store');
                Route::delete('{id}', 'destroy');
                Route::put('{id}', 'update');
                Route::put('update/orders', 'updateOrder');
            });

        require __DIR__ . '/post.php';
    });
});
