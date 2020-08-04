<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Сущность для бронирования
 *
 * Class Booking
 * @package App\Models
 *
 * @property int $id
 * @property int $room_id
 * @property int $user_id
 * @property string $payment_method
 * @property int|null $amount
 * @property int|null $discount
 * @property int|null $amount_with_discount
 * @property int|null $payed
 * @property int|null $duration
 * @property Carbon $created_at
 * @property Carbon|null $payed_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $canceled_at
 * @property Carbon $reserve_from
 * @property Carbon $reserve_to
 * @property string|null $user_comment
 * @property string|null $manager_comment
 * @property int $status
 * @property int|null $prepayment
 * @property Carbon|null $deleted_at
 * @property Carbon|null $expires_at
 * @property int|null $manager_id
 * @property boolean $is_service
 * @property int|null $google_export_status
 * @property string|null $google_event_id
 * @property boolean|null $is_imported_from_google
 * @property int|null $order_id
 * @property int|null $promocode_id
 * @property string|null $price_type
 * @property array $extras
 * @property array $members
 * @property int|null $people_amount
 * @property int|null $seats
 * @property string|null $description
 *
 * @property Room $room
 * @property User $client
 * @property Promocode $promocode
 * @property BookingPaymentData $paymentData
 * @property BookingPaymentYaKassa $paymentYandexKassa
 *
 * @method Builder findById(int $id)
 * @method Builder closest()
 * @method Builder orderDesc()
 *
 * @mixin Collection
 * @mixin Builder
 */
class Booking extends Model
{
    use SoftDeletes;

    public const STATUS = [
        'NEW' => 0,
        'CANCELLED' => 1,
        'PAID' => 2,
        'DONE' => 3,
        'EXPIRED' => 4,
    ];

    public const STATUS_TEXT = [
        0 => 'Ожидается оплата',
        1 => 'Отменено',
        2 => 'Забронировано',
        3 => 'Завершено',
        4 => 'Просрочено'
    ];

    public const GOOGLE = [
        'EXPORT_NONE' => 0,
        'EXPORT_EXPORTED' => 1,
        'EXPORT_ERROR' => 2,
    ];

    public const MERCHANT = [
        'WALLETONE' => 'WALLETONE',
        'PAYPAL' => 'PAYPAL',
        'CASH' => 'CASH',
        'YANDEXKASSA' => 'YANDEXKASSA',
    ];

    public const MIN_DURATION_IN_HOURS = 1;

    const UPDATED_AT = null;

    protected $casts = [
        'members' => 'array',
        'prepayment' => 'float'
    ];

    /**
     * Атрибуты, которые должны быть преобразованы в даты.
     *
     * @var array
     */
    protected $dates = [
        'deleted_at',
        'reserve_from',
        'reserve_to',
        'payed_at',
        'returned_at',
        'canceled_at',
        'expires_at',
        'created_at'
    ];

    protected $fillable = [
        'room_id',
        'user_id',
        'manager_id',
        'payment_method',
        'amount',
        'discount',
        'amount_with_discount',
        'reserve_from',
        'reserve_to',
        'duration',
        'user_comment',
        'description',
        'extras',
        'price_type',
        'google_export_status',
        'expires_at',
        'description',
        'status',
        'prepayment',
        'members'
    ];

    /**
     * Получение забронированного зала
     *
     * @return BelongsTo
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /**
     * Получение брони клиента
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->withTrashed();
    }

    /**
     * Получение промокода
     *
     * @return BelongsTo
     */
    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    /**
     * Общие данные для всех платежей по данному бронированию
     *
     * @return HasOne
     */
    public function paymentData(): HasOne
    {
        return $this->hasOne(BookingPaymentData::class);
    }

    /**
     * Данные по оплате бронирования через Яндекс.Кассу
     *
     * @return HasOne
     */
    public function paymentYandexKassa(): HasOne
    {
        return $this->hasOne(BookingPaymentYaKassa::class);
    }

    /**
     * Получение всех уведомлений по бронированию
     *
     * @return HasMany
     */
    public function bookingPaymentNotifications(): HasMany
    {
        return $this->hasMany(BookingPaymentYaKassaNotification::class);
    }

    /**
     * Текстовое представление статуса
     *
     * @return string
     */
    public function getStatusTextAttribute(): string
    {
        return self::STATUS_TEXT[$this->status];
    }

    /**
     * Получение доп. услуг бронирования
     *
     * @param string|null $value
     * @return array
     */
    public function getExtrasAttribute(?string $value): array
    {
        $result = json_decode($value, true);
        return empty($result) ? [] : $result;
    }

    /**
     * Получить бронирование по полю id
     *
     * @param Builder $query
     * @param int $id
     * @return Builder
     */
    public function scopeFindById(Builder $query, int $id): Builder
    {
        return $query->where('id', $id);
    }

    /**
     * Ближайшие бронирования
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeClosest(Builder $query): Builder
    {
        return $query->where('reserve_from', '>=', Carbon::today())
            ->orderBy('reserve_from', 'asc');
    }

    /**
     * Получение последнего комментария
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrderDesc(Builder $query): Builder
    {
        return $query->orderBy('id', 'desc');
    }

    /**
     * UTM метки бронирования
     *
     * @return HasOne
     */
    public function utmCodes(): HasOne
    {
        return $this->hasOne(UtmCode::class);
    }
}
