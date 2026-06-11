<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductCategory;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Code;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @group 관리자
 */
class ProductController extends ApiController
{

    public function init()
    {
        $categoryItems = ProductCategory::getItems();
        $productCategoryItems = Code::getItems(Code::PRODUCT_CATEGORY_ID);
        return $this->respondSuccessfully(compact(['categoryItems', 'productCategoryItems']));
    }

    /** 목록
     * @subgroup Product(상품)
     * @priority 1
     * @responseFile storage/responses/products.json
     */
    public function index(Request $request)
    {
        $filters = (array)json_decode($request->input('search'));
        $items = Product::with(['media', 'options'])->search($filters)->latest()->paginate($request->itemsPerPage ?? 30);
        return ProductResource::collection($items);
    }

    /** 상세
     * @subgroup Product(상품)
     * @priority 1
     * @responseFile storage/responses/product.json
     */
    public function show(Product $product)
    {
        $product->load('options');
        return $this->respondSuccessfully(ProductResource::make($product));
    }

    /**
     * 생성
     * @subgroup Product(상품)
     * @priority 1
     * @responseFile storage/responses/product.json
     */
    public function store(ProductRequest $request)
    {
        $data = $request->validated();

        $product = tap(new Product($data))->save();
        $product->options()->createMany($data['options']);

        if ($request->file(Product::IMAGES)) {
            foreach ($request->file(Product::IMAGES) as $file) {
                $product->addMedia($file)->toMediaCollection(Product::IMAGES);
            }
        }
        /*if ($request->file(Product::DESC_IMAGES)) {
            foreach ($request->file(Product::DESC_IMAGES) as $file) {
                $product->addMedia($file)->toMediaCollection(Product::DESC_IMAGES);
            }
        }*/

        return $this->respondSuccessfully(ProductResource::make($product));
    }

    /** 수정
     * @subgroup Product(상품)
     * @priority 1
     * @responseFile storage/responses/product.json
     */
    public function update(ProductRequest $request, Product $product)
    {
        $data = $request->validated();
        $product->update($data);
        $options = $data['options'];
        //$product->options()->createMany($data['options']);
        $options = array_map(function ($item, $index) use ($product) {
            $item['id'] = $item['id'] ?? null;
            $item['product_id'] = $product->id;
            return $item;
        }, $options, array_keys($options));
        ProductOption::upsert($options, ['id'], ['product_id', 'name', 'price', 'original_price', 'stock_quantity']);

        if ($request->file(Product::IMAGES)) {
            foreach ($request->file(Product::IMAGES) as $file) {
                $product->addMedia($file)->toMediaCollection(Product::IMAGES);
            }
        }
        /*if ($request->file(Product::DESC_IMAGES)) {
            foreach ($request->file(Product::DESC_IMAGES) as $file) {
                $product->addMedia($file)->toMediaCollection(Product::DESC_IMAGES);
            }
        }*/

        return $this->respondSuccessfully(ProductResource::make($product));
    }

    /** 삭제
     * @subgroup Product(상품)
     * @priority 1
     */
    public function destroy(Product $product)
    {
        if ($product->orderProducts()->exists()) {
            abort(500, '주문된 상품이여서 삭제할 수 없습니다.');
        }
        $product->delete();
        $product->clearMediaCollection(Product::IMAGES);
        return $this->respondSuccessfully();
    }

    /**
     * 상품별 판매 집계 (상품+옵션 단위, 취소/환불/결제실패 제외)
     */
    public function sales(Request $request)
    {
        $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d'],
            'keyword' => ['nullable', 'string'],
        ]);

        $validStatuses = [
            OrderStatus::PAYMENT_COMPLETE->value,
            OrderStatus::DELIVERY_PREPARING->value,
            OrderStatus::DELIVERY->value,
            OrderStatus::DELIVERY_COMPLETE->value,
            OrderStatus::PURCHASE_CONFIRM->value,
        ];

        $query = OrderProduct::query()
            ->join('orders', 'order_products.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_products.product_id', '=', 'products.id')
            ->leftJoin('product_options', 'order_products.product_option_id', '=', 'product_options.id')
            ->whereNull('orders.deleted_at')
            ->where('orders.is_test', false)
            ->whereIn('order_products.status', $validStatuses)
            ->whereBetween('orders.created_at', [$request->start . ' 00:00:00', $request->end . ' 23:59:59'])
            ->when($request->filled('keyword'), function ($query) use ($request) {
                $query->where('products.name', 'like', '%' . $request->keyword . '%');
            });

        $rows = (clone $query)
            ->selectRaw('order_products.product_id,'
                . ' products.name as product_name,'
                . ' product_options.name as option_name,'
                . ' SUM(order_products.quantity) as quantity,'
                . ' SUM(order_products.price * order_products.quantity) as amount,'
                . ' COUNT(DISTINCT order_products.order_id) as orders_count')
            ->groupBy('order_products.product_id', 'order_products.product_option_id', 'products.name', 'product_options.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn($row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'option_name' => $row->option_name,
                'quantity' => (int)$row->quantity,
                'amount' => (int)$row->amount,
                'orders_count' => (int)$row->orders_count,
            ])
            ->values();

        $totals = [
            'quantity' => $rows->sum('quantity'),
            'amount' => $rows->sum('amount'),
            'orders_count' => (clone $query)->distinct()->count('order_products.order_id'),
        ];

        return $this->respondSuccessfully(['data' => $rows, 'totals' => $totals]);
    }

    public function destroyImage(Media $media)
    {
        $media->delete();
        return $this->respondSuccessfully();
    }

    public function destroyOption(ProductOption $productOption)
    {
        $productOption->delete();
        return $this->respondSuccessfully();
    }

    public function storeImages(Request $request)
    {
        // 파일 검증
        //$request->validate(['upload' => 'required|file|mimes:jpeg,png,jpg,gif|max:5120',]);
        $request->validate(['upload' => 'required|file|mimes:jpeg,png,jpg,gif']);

        // 파일 저장
        $path = $request->file('upload')->store('products', 'public');

        // CKEditor에 반환할 URL
        $url = asset(Storage::url($path));

        return response()->json(['uploaded' => true, 'url' => $url,]);
    }


}
