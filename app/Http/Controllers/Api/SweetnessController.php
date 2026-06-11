<?php

namespace App\Http\Controllers\Api;

use App\Models\Sweetness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Sweetness
 */
class SweetnessController extends ApiController
{
    /**
     * 날짜별 당도 측정 기록 (date 생략 시 최신 측정일, id 지정 시 해당 측정 건의 날짜)
     */
    public function index(Request $request)
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'id' => ['nullable', 'integer'],
        ]);

        $date = $request->input('date');

        //메인에서 특정 과일 카드로 진입한 경우 그 측정 건이 속한 날짜를 보여줌
        if (!$date && $request->input('id')) {
            $date = Sweetness::where('is_display', true)
                ->where('id', $request->input('id'))
                ->value(DB::raw('DATE(created_at)'));
        }

        $date = $date
            ?: Sweetness::where('is_display', true)->max(DB::raw('DATE(created_at)'));

        if (!$date) {
            return $this->respondSuccessfully([
                'date' => null,
                'prev_date' => null,
                'next_date' => null,
                'items' => [],
            ]);
        }

        $prevDate = Sweetness::where('is_display', true)
            ->whereDate('created_at', '<', $date)
            ->max(DB::raw('DATE(created_at)'));
        $nextDate = Sweetness::where('is_display', true)
            ->whereDate('created_at', '>', $date)
            ->min(DB::raw('DATE(created_at)'));

        $items = Sweetness::with('media')
            ->where('is_display', true)
            ->whereDate('created_at', $date)
            ->orderBy('fruit_name')
            ->get();

        //같은 과일의 직전 측정값
        $prevBrixes = Sweetness::where('is_display', true)
            ->whereIn('fruit_name', $items->pluck('fruit_name'))
            ->whereDate('created_at', '<', $date)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('fruit_name')
            ->map(fn($rows) => $rows->first()->sweetness);

        return $this->respondSuccessfully([
            'date' => $date,
            'prev_date' => $prevDate,
            'next_date' => $nextDate,
            'items' => $items->map(function ($item) use ($prevBrixes) {
                $img = $item->getFirstMedia(Sweetness::IMAGES);
                return [
                    'id' => $item->id,
                    'name' => $item->fruit_name,
                    'brix' => $item->sweetness,
                    'standard_brix' => $item->standard_sweetness,
                    'prev_brix' => $prevBrixes[$item->fruit_name] ?? null,
                    'measured_at' => $item->created_at?->format('Y-m-d H:i:s'),
                    'description' => $item->description,
                    'curator' => $item->curator,
                    'img' => $img ? ['url' => $img->getFullUrl()] : null,
                    'photos' => $item->getMedia(Sweetness::PHOTOS)
                        ->map(fn($media) => ['url' => $media->getFullUrl()])
                        ->values(),
                ];
            })->values(),
        ]);
    }
}
