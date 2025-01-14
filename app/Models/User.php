<?php

namespace App\Models;

use App\Enums\PurchasableType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Paddle\Billable;
use Spatie\Mailcoach\Domain\Audience\Models\EmailList;

class User extends Authenticatable
{
    use HasFactory;
    use Billable;
    use Notifiable;

    protected $hidden = [
        'password', 'remember_token',
    ];

    public $casts = [
        'is_admin' => 'bool',
        'next_purchase_discount_period_ends_at' => 'datetime',
        'sponsor_gift_given_at' => 'datetime',
    ];

    public function getPayLinkForProductId(string $paddleProductId, License $license = null)
    {
        $purchasable = Purchasable::findForPaddleProductId($paddleProductId);

        $displayablePrice = $purchasable->getPriceForIp(request()->ip());
        $prices[] = $displayablePrice->toPaddleFormat();
        if ($displayablePrice->currencyCode !== 'USD') {
            $dollarDisplayablePrice = $purchasable->getPriceForCountryCode('US');
            $prices[] = $dollarDisplayablePrice->toPaddleFormat();
        }

        $passthrough = [];

        if ($referrer = Referrer::findActive()) {
            $passthrough['referrer_uuid'] = $referrer->uuid;
        }

        if ($license) {
            $passthrough['license_id'] = $license->id;
        }

        return $this->chargeProduct($paddleProductId, [
            'quantity_variable' => ! $purchasable->isRenewal(),
            'customer_email' => auth()->user()->email,
            'marketing_consent' => true,
            'prices' => $prices,
            'passthrough' => $passthrough,
        ]);
    }

    public function isSponsoring(): bool
    {
        if ($this->isSpatieMember()) {
            return true;
        }

        return (bool)$this->is_sponsor;
    }

    public function isSubscribedToNewsletter(): bool
    {
        if (! $this->email) {
            return false;
        }

        /** @var EmailList $emailList */
        $emailList = EmailList::firstWhere('name', 'Spatie');

        if (! $emailList) {
            return false;
        }

        return $emailList->isSubscribed($this->email);
    }

    public function isSpatieMember(): bool
    {
        return Member::where('github', $this->github_username)->exists();
    }

    public function owns(Purchasable $purchasable): bool
    {
        return $this->purchases()->where('purchasable_id', $purchasable->id)->exists();
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function licensesWithoutRenewals(): HasMany
    {
        return $this->hasMany(License::class)->whereHas('purchasable', function (Builder $query): void {
            $query->whereNotIn('type', [
                PurchasableType::TYPE_STANDARD_RENEWAL,
                PurchasableType::TYPE_UNLIMITED_DOMAINS_RENEWAL,
            ]);
        });
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class)->with('purchasable.product');
    }

    public function purchasesWithoutRenewals(): HasMany
    {
        return $this->hasMany(Purchase::class)->whereHas('purchasable', function (Builder $query): void {
            $query->whereNotIn('type', [
                PurchasableType::TYPE_STANDARD_RENEWAL,
                PurchasableType::TYPE_UNLIMITED_DOMAINS_RENEWAL,
            ]);
        });
    }

    public function completedVideos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'video_completions')->withTimestamps();
    }

    public function hasAccessToUnreleasedPurchasables(): bool
    {
        return true;

        $earlyAccessUserIds = [
            15,
            1137,
            769,
            762,
            3081,
            3507,
            783,
            5750,
            1324,
            76,
            1068,
            91,
            1688,
            1068,
            7999,
        ];

        if (in_array($this->id, $earlyAccessUserIds)) {
            return true;
        }

        return $this->isSpatieMember();
    }

    public function enjoysExtraDiscountOnNextPurchase(): bool
    {
        if (! $this->next_purchase_discount_period_ends_at) {
            return false;
        }

        return $this->next_purchase_discount_period_ends_at->isFuture();
    }

    public function nextPurchaseDiscountPercentage(): int
    {
        return 10;
    }

    public function canImpersonate(): bool
    {
        return $this->isSpatieMember();
    }
}
