<?php

namespace Modules\Offers\Entities;

use App\Entities\TrackerTask;
use Awobaz\Compoships\Compoships;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Modules\Accounts\Entities\Operation;
use Modules\Affiliates\Entities\Affiliate;
use Modules\Merchants\Entities\Merchant;
use Modules\Networks\Entities\Network;
use Modules\Networks\Entities\NetworkParticipant;
use Modules\Offers\Entities\Pivots\TrackerTaskLeadPivot;

/**
 * Modules\Offers\Entities\Lead
 *
 * @mixin Lead|Eloquent
 *
 * @property string                          $tracker_id
 * @property string                          $status
 * @property int                             $network_profit
 * @property int                             $affiliate_profit
 * @property int                             $merchant_payment
 * @property string                          $currency
 * @property string|null                     $reject_comment
 * @property string|null                     $notice
 * @property string                          $session_id
 * @property string                          $client_id
 * @property string                          $request_id
 * @property int                             $network_id
 * @property int                             $offer_id
 * @property int                             $campaign_id
 * @property int                             $offer_rate_id
 * @property int                             $promo_tool_id
 * @property int                             $affiliate_id
 * @property int                             $merchant_id
 * @property Carbon                          $timestamp
 * @property Carbon                          $created_at
 * @property array|null                      $payload
 * @property Carbon|null                     $terminated_at
 * @property string|null                     $click_id
 * @property string|null                     $customer_id
 * @property string|null                     $prev_status
 * @property string                          $template_data
 * @property int                             $id
 * @property int|null                        $promo_tool_code_id;
 * @property-read Collection|LeadActionLog[] $actionsLog
 * @property-read int|null                   $actions_log_count
 * @property-read Affiliate                  $affiliate
 * @property-read NetworkParticipant         $affiliateParticipant
 * @property-read Campaign                   $campaign
 * @property-read Collection|LeadActionLog[] $costChangesLog
 * @property-read int|null                   $cost_changes_log_count
 * @property-read Merchant                   $merchant
 * @property-read NetworkParticipant         $merchantParticipant
 * @property-read Network                    $network
 * @property-read Offer                      $offer
 * @property-read OfferRate                  $offerRate
 * @property-read Operation                  $operation
 * @property-read PromoTool                  $promoTool
 * @property-read PromoTool                  $promoToolCode
 * @property-read TrackerTaskLeadPivot       $trackingPivot
 * @method static Builder|Lead approved()
 * @method static Builder|Lead declined()
 * @method static Builder|Lead hold()
 * @method static Builder|Lead newModelQuery()
 * @method static Builder|Lead newQuery()
 * @method static Builder|Lead prevMonth()
 * @method static Builder|Lead query()
 * @method static Builder|Lead whereAffiliateId($value)
 * @method static Builder|Lead whereAffiliateProfit($value)
 * @method static Builder|Lead whereCampaignId($value)
 * @method static Builder|Lead whereClickId($value)
 * @method static Builder|Lead whereClientId($value)
 * @method static Builder|Lead whereCreatedAt($value)
 * @method static Builder|Lead whereCurrency($value)
 * @method static Builder|Lead whereEventId($value)
 * @method static Builder|Lead whereId($value)
 * @method static Builder|Lead whereMerchantId($value)
 * @method static Builder|Lead whereMerchantPayment($value)
 * @method static Builder|Lead whereNetworkId($value)
 * @method static Builder|Lead whereNetworkProfit($value)
 * @method static Builder|Lead whereNotice($value)
 * @method static Builder|Lead whereOfferId($value)
 * @method static Builder|Lead whereOfferRateId($value)
 * @method static Builder|Lead wherePayload($value)
 * @method static Builder|Lead wherePromoToolId($value)
 * @method static Builder|Lead whereRejectComment($value)
 * @method static Builder|Lead whereRequestId($value)
 * @method static Builder|Lead whereSessionId($value)
 * @method static Builder|Lead whereStatus($value)
 * @method static Builder|Lead whereTerminatedAt($value)
 * @method static Builder|Lead whereTimestamp($value)
 * @method static Builder|Lead whereTrackerId($value)
 */
class Lead extends Model
{
    use Compoships;

    const TABLE = 'leads';

    const STATUS_HOLD     = 'hold';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    const REASON_DECLINED = 'declined';
    const REASON_INVALID  = 'invalid';
    const REASON_OVERCAP  = 'overcap';
    const REASON_TRASH    = 'trash';

    const UPDATE_ACTION_FIXED           = 'update.fixed';
    const UPDATE_ACTION_PERCENT         = 'update.percent';
    const UPDATE_ACTION_RETARIFFICATION = 'update.retariffication';

    const ALLOWED_UPDATE_ACTION = [
        self::UPDATE_ACTION_FIXED,
        self::UPDATE_ACTION_PERCENT,
        self::UPDATE_ACTION_RETARIFFICATION,
    ];

    const ALLOWED_REASON = [
        self::REASON_DECLINED,
        self::REASON_INVALID,
        self::REASON_OVERCAP,
        self::REASON_TRASH,
    ];

    const ALLOWED_STATUSES = [
        self::STATUS_HOLD,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];


    const RELATION_MORPH = 'lead';

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $attributes = [
        'status' => self::STATUS_HOLD,
    ];

    protected $fillable = [
        'payload',
        'reject_comment',
        'notice',
        'session_id',
        'client_id',
        'request_id',
        'timestamp',
    ];

    protected $dates = [
        'timestamp',
        'created_at',
        'terminated_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function offerRate(): BelongsTo
    {
        return $this->belongsTo(OfferRate::class);
    }

    public function promoTool(): BelongsTo
    {
        return $this->belongsTo(PromoTool::class);
    }

    public function promoToolCode(): BelongsTo
    {
        return $this->belongsTo(PromoTool::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function actionsLog(): HasMany
    {
        return $this->hasMany(LeadActionLog::class);
    }

    public function costChangesLog(): HasMany
    {
        return $this->hasMany(LeadActionLog::class)
            ->where('event', LeadActionLog::EVENT_COST_CHANGED)
            ->latest();
    }

    public function operation(): MorphOne
    {
        return $this->morphOne(Operation::class, 'based_on');
    }

    public function externalData(): HasOne
    {
        return $this->hasOne(LeadExternalData::class, 'lead_id', 'id');
    }

    public function affiliateParticipant()
    {
        return $this
            ->hasOne(
                NetworkParticipant::class,
                ['network_id', NetworkParticipant::MORPH_ID_FIELD],
                ['network_id', 'affiliate_id'],
            )
            ->where(NetworkParticipant::MORPH_TYPE_FIELD, '=', NetworkParticipant::TYPE_AFFILIATE);
    }

    public function merchantParticipant()
    {
        return $this
            ->hasOne(
                NetworkParticipant::class,
                ['network_id', NetworkParticipant::MORPH_ID_FIELD],
                ['network_id', 'merchant_id'],
            )
            ->where(NetworkParticipant::MORPH_TYPE_FIELD, '=', NetworkParticipant::TYPE_MERCHANT);
    }

    public function trackingPivot(): HasOne
    {
        return $this->hasOne(TrackerTaskLeadPivot::class)
            ->where(TrackerTaskLeadPivot::TABLE . '.status', '!=', TrackerTask::STATUS_DONE);
    }

    public function scopeApproved(Builder $query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeHold(Builder $query)
    {
        return $query->where('status', self::STATUS_HOLD);
    }

    public function scopeDeclined(Builder $query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function isHold(): bool
    {
        return $this->status === self::STATUS_HOLD;
    }

    public function isApproved(): bool
    {
        return $this->status === static::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === static::STATUS_REJECTED;
    }
}
