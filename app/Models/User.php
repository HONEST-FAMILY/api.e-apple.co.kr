<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrderStatus;
use App\Enums\UserLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'nickname',
        'is_agree_promotion',
        'username', 'social_id', 'social_platform',
        'postal_code', 'address', 'address_detail',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_agree_promotion' => 'boolean',
        ];
    }

    protected $casts = [
        'level' => UserLevel::class,
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }


    public function scopeSearch(Builder $query, $filters): Builder
    {
        $filters = json_decode($filters);
        if (!empty($filters->keyword)) {
            return $query->where(function($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters->keyword . '%')
                  ->orWhere('phone', 'like', '%' . $filters->keyword . '%')
                  ->orWhere('email', 'like', '%' . $filters->keyword . '%');
            });
        }
        return $query;
    }

    public function scopeMember(Builder $query): Builder
    {
        return $query->where('is_admin', false);
    }



    /**
     * 내 주문들
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * 내 상품 리뷰
     */
    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * 작성 가능한 상품 리뷰
     */
    public function availableProductReviews(): HasMany
    {
        return $this->hasMany(OrderProduct::class)
            //배송완료 또는 구매확정 (배송완료 후에도 리뷰 작성 가능하게)
            ->whereIn('status', [OrderStatus::DELIVERY_COMPLETE, OrderStatus::PURCHASE_CONFIRM])
            //구매후 30일 이내
            ->where('created_at', '>=', now()->subDays(ProductReview::AVAILABLE_DAYS))
            ->doesntHave('review');
    }


    public function productInquiries(): HasMany
    {
        return $this->hasMany(ProductInquiry::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function cartProductOptions(): HasMany
    {
        return $this->hasMany(CartProductOption::class);
    }

    public function coupons()
    {
        return $this->belongsToMany(Coupon::class, 'user_coupons')
            ->withTimestamps()
            ->withPivot('used_at'); // 중간 테이블의 추가 필드를 사용하려면
    }

    /**
     * 사용 가능한 쿠폰
     */
    public function availableCoupons()
    {
        return $this->belongsToMany(Coupon::class, 'user_coupons')
            ->withTimestamps()
            // 중간 테이블의 추가 필드를 사용하려면
                ->withPivot(['id', 'used_at', 'expired_at'])
            // 중간 테이블 검색
                ->wherePivot('used_at', null) // 사용하지 않은 쿠폰
                ->wherePivot('expired_at', '>=', now()); // 만료되지 않은 쿠폰
    }

    public function pointTransactions()
    {
        return $this->hasMany(Point::class);
    }

    public function depositPoint($pointable)
    {
        return DB::transaction(function () use ($pointable) {
            list($amount, $desc) = $pointable->getDepositPoints();
            $balance = $this->points + $amount;
            $this->pointTransactions()->create([
                'pointable_type' => get_class($pointable),
                'pointable_id' => $pointable->id,
                'deposit' => $amount,
                'description' => $desc,
                'balance' => $balance,
            ]);
            $this->points = $balance;
            return $this->save();
        });
    }

    public function withdrawalPoint($pointable)
    {
        list($amount, $desc) = $pointable->getWithdrawalPoints();

        if ($this->points < $amount) {
            //abort(403, '포인트가 부족합니다.');
            abort(response()->json(['message' => '포인트가 부족합니다.', 'errors' => ['points' => '포인트가 부족합니다.']],
                403));
        }
        $balance = $this->points - $amount;
        $this->pointTransactions()->create([
            'pointable_type' => get_class($pointable),
            'pointable_id' => $pointable->id,
            'withdrawal' => $amount,
            'description' => $desc,
            'balance' => $balance,
        ]);
        $this->points = $balance;
        return $this->save();
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(DeliveryAddress::class);
    }

}
