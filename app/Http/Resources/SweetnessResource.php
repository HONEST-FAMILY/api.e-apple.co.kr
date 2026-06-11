<?php

namespace App\Http\Resources;

use App\Models\Sweetness;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SweetnessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        //return parent::toArray($request);
        Carbon::setLocale('ko');
        $additionalFields = ($request->user()?->is_admin) ? [
            'is_display' => $this->is_display,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ] : [];
        $return = [
            ...$additionalFields,
            ...$this->only(['id', 'fruit_name', 'sweetness', 'standard_sweetness', 'description', 'curator']),
            //'standard_datetime' => Carbon::parse($this->standard_datetime)->translatedFormat('d일(D) H시'),
            'img' => ($img = $this->getFirstMedia(Sweetness::IMAGES)) ? MediaResource::make($img) : null,
            'imgs' => $this->getMedia(Sweetness::IMAGES)->isNotEmpty() ? MediaResource::collection($this->getMedia(Sweetness::IMAGES)) : null,
            'photos' => MediaResource::collection($this->getMedia(Sweetness::PHOTOS)),
        ];

        //*
        if (config('scribe.response_file')) {
            $comments = [];
            return getScribeResponseFile($return, 'sweetnesses', $comments);
        }
        //*/
        return $return;
        //*/

    }
}
