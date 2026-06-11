<?php

use App\Console\Commands\TestTodayDataSeed;
use App\Console\Commands\UpdateOrderDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


// 개발에서만 실행
Schedule::command(TestTodayDataSeed::class)
    ->withoutOverlapping()
    ->daily()//자정에 실행
    ->environments(['local'])
;

// 배송중 건 택배사 조회 → 배송완료 자동 전환 + 주문 상태 동기화
Schedule::command(UpdateOrderDelivery::class)
    ->withoutOverlapping()
    ->hourly()
;


/*Schedule::command(ExpirePoints::class)
    ->withoutOverlapping()
    //->everySecond();
    ->daily()//자정에 실행
;*/


