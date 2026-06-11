<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\SweetnessRequest;
use App\Http\Resources\SweetnessResource;
use App\Models\Sweetness;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SweetnessController extends ApiController
{
    public function index(Request $request)
    {
        $filters = (array)json_decode($request->input('search'));
        $items = Sweetness::search($filters)->latest()->paginate($request->itemsPerPage ?? 30);
        return SweetnessResource::collection($items);
    }

    public function store(SweetnessRequest $request)
    {
        $data = $request->validated();
        $sweetness = tap(new Sweetness($data))->save();
        if ($request->file(Sweetness::IMAGES)) {
            foreach ($request->file(Sweetness::IMAGES) as $file) {
                $sweetness->addMedia($file)->toMediaCollection(Sweetness::IMAGES);
            }
        }
        if ($request->file(Sweetness::PHOTOS)) {
            foreach ($request->file(Sweetness::PHOTOS) as $file) {
                $sweetness->addMedia($file)->toMediaCollection(Sweetness::PHOTOS);
            }
        }
        return $this->respondSuccessfully(new SweetnessResource($sweetness));
    }

    public function show(Request $request, Sweetness $sweetness)
    {
        return $this->respondSuccessfully(new SweetnessResource($sweetness));
    }

    public function update(SweetnessRequest $request, Sweetness $sweetness)
    {
        $sweetness->update($request->validated());
        if ($request->file(Sweetness::IMAGES)) {
            foreach ($request->file(Sweetness::IMAGES) as $file) {
                $sweetness->addMedia($file)->toMediaCollection(Sweetness::IMAGES);
            }
        }
        if ($request->file(Sweetness::PHOTOS)) {
            foreach ($request->file(Sweetness::PHOTOS) as $file) {
                $sweetness->addMedia($file)->toMediaCollection(Sweetness::PHOTOS);
            }
        }
        return $this->respondSuccessfully(new SweetnessResource($sweetness));
    }

    public function destroy(Request $request, Sweetness $sweetness)
    {
        $sweetness->delete();
        $sweetness->clearMediaCollection(Sweetness::IMAGES);
        $sweetness->clearMediaCollection(Sweetness::PHOTOS);
        return $this->respondSuccessfully();
    }

    public function destroyImage(Media $media)
    {
        $media->delete();
        return $this->respondSuccessfully();
    }
}
