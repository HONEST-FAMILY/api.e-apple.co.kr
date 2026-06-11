<?php

use App\Enums\ProductCategory;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartProductOptionController;
use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\DeliveryAddressController;
use App\Http\Controllers\Api\ExchangeReturnController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\MainController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderProductController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\PopBannerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductInquiryController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\UserCouponController;
use App\Http\Controllers\Api\VerifyNumberController;
use Illuminate\Support\Facades\Route;

/**
 * FOR SANCTUM
 */
/*Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});*/
/**
 * FOR JWT
 */
/*Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});*/



Route::group(['prefix' => 'main', 'controller' => MainController::class], function () {
    Route::get('', 'index');
});

// 팝업 배너
Route::group(['prefix' => 'pop-banners', 'controller' => PopBannerController::class], function () {
    Route::get('', 'index');
    Route::get('{popBanner}', 'show');
});

//사용자인증
Route::group(['controller' => AuthController::class], function () {
    Route::post('login', 'login');
    Route::post('register', 'store');
    Route::post('find-id', 'findId');
    Route::post('find-password', 'findPassword');
    Route::post('reset-password', 'resetPassword');

    //번호인증
    Route::group(['controller' => VerifyNumberController::class], function () {
        Route::post('verify-numbers', 'store');
        Route::put('verify-numbers', 'update');
    });

    Route::group(['middleware' => 'auth:api'], function () {
        Route::post('logout', 'logout');
        Route::get('profile', 'profile');
        Route::put('profile', 'update');
        Route::put('password', 'updatePassword');
        Route::delete('profile', 'destroy');

        //Route::get('refresh', 'refresh');
    });
});

//상품보기
Route::group(['prefix' => 'products'], function () {
    Route::group(['controller' => ProductController::class], function () {
        Route::get('md_packages', 'mdPackages');
        Route::get('{category?}', 'index')->where('category', implode('|', ProductCategory::values()));
        Route::get('{product}', 'show');
    });

    //상품리뷰
    Route::group(['prefix' => '{id}/reviews', 'controller' => ProductReviewController::class], function () {
        Route::get('', 'index');
    });

    //상품문의
    Route::group(['prefix' => '{id}/inquiries', 'controller' => ProductInquiryController::class], function () {
        Route::get('', 'index');
        Route::group(['middleware' => ['auth:api']], function () {
            Route::post('', 'store');
            //Route::get('mine', 'mine');
        });
    });
    Route::group(['prefix' => 'inquiries', 'controller' => ProductInquiryController::class], function () {
        Route::group(['middleware' => ['auth:api']], function () {
            Route::get('mine', 'mine');
            Route::delete('{id}', 'destroy');
        });
    });
});

//상품 리뷰
Route::group(['prefix' => 'product_reviews', 'controller' => ProductReviewController::class],
    function () {
        Route::group(['middleware' => ['auth:api']], function () {
            Route::get('mine', 'myProductReviews');
            Route::get('available', 'myAvailableProductReviews');
            Route::post('', 'store');
            Route::put('{id}', 'update');
            Route::delete('{id}', 'destroy');
        });

        //상품 리뷰 상세
        Route::get('{productReview}', 'show');
    });

//카트
Route::group(['prefix' => 'carts', /*'middleware' => ['auth:api']*/],
    function () {
        Route::group(['controller' => CartController::class], function () {
            Route::get('', 'index');
            Route::post('', 'store');
            Route::put('{id}', 'update');//장바구니 상품 옵션 추가
            Route::delete('ids', 'destroys');
            Route::delete('sold-out', 'destroySoldOut');
            Route::delete('{id}', 'destroy');
        });

        Route::group(['prefix' => '{id}/options', 'controller' => CartProductOptionController::class],
            function () {
                //Route::post('', 'store'); @deprecated
                Route::put('{option}', 'update');
                Route::delete('{option}', 'destroy');
            });
    });

//배송지
Route::group(['prefix' => 'delivery_addresses', 'middleware' => ['auth:api'], 'controller' => DeliveryAddressController::class],
    function () {
        Route::get('', 'index');
        Route::post('', 'store');
        Route::get('{deliveryAddress}', 'show');
        Route::put('{deliveryAddress}', 'update');
        Route::delete('{deliveryAddress}', 'destroy');
    });

Route::group(['prefix' => 'coupons', 'controller' => CouponController::class],
    function () {
        Route::get('', 'index');
        Route::group(['middleware' => ['auth:api']], function () {
            Route::put('{coupon}/download', 'download');
        });
    });

Route::group(['prefix' => 'user_coupons', 'middleware' => ['auth:api'], 'controller' => UserCouponController::class],
    function () {
        Route::get('', 'index');
        //Route::get('test', 'test');
    });

//주문
Route::group(['prefix' => 'orders', 'controller' => OrderController::class],
    function () {
        Route::get('', 'index');
        Route::post('', 'store');
        Route::post('carts', 'cartsStore');//장바구니 상품 구매
        Route::get('guest', 'showGuest');
        Route::get('{order}', 'show');
        Route::put('{id}', 'update');
        Route::post('complete', 'paymentComplete');
        Route::get('complete/mobile', 'paymentComplete');
        Route::post('complete/webhook', 'paymentComplete');
        Route::get('complete/webhook', 'paymentComplete');
        //Route::put('{id}/confirm', 'confirm');//orderProducts 별로 구매확정
        Route::put('{id}/cancel', 'cancel');
    });

//주문제품상세
Route::group(['prefix' => 'order_products', 'controller' => OrderProductController::class],
    function () {
        Route::get('{id}', 'show');
        Route::put('{id}/confirm', 'confirm');
    });

//주문상품 교환반품
Route::group(['prefix' => 'exchange_returns', 'controller' => ExchangeReturnController::class],
    function () {
        Route::get('', 'index');
        Route::post('', 'store');
    });

//1:1문의
Route::group(['prefix' => 'inquiries', 'middleware' => ['auth:api'], 'controller' => InquiryController::class],
    function () {
        Route::get('', 'index');
        Route::post('', 'store');
        Route::delete('{inquiry}', 'destroy');
    });

//적립금
Route::group(['prefix' => 'points', 'middleware' => ['auth:api'], 'controller' => PointController::class],
    function () {
        Route::get('', 'index');
    });

//당도
Route::group(['prefix' => 'sweetnesses', 'controller' => \App\Http\Controllers\Api\SweetnessController::class],
    function () {
        Route::get('', 'index');
    });

//상품 카테고리
Route::group(['prefix' => 'categories', 'controller' => CodeController::class],
    function () {
        Route::group(['prefix' => 'products'],
            function () {
                Route::get('', 'products');
                Route::get('{id}/subcategories', 'products');
            });

        Route::get('packages/{type}', 'packages');
    });



require __DIR__.'/admin.php';

require __DIR__.'/post.php';
