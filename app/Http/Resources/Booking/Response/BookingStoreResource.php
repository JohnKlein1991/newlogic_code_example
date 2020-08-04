<?php

namespace App\Http\Resources\Booking\Response;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Class BookingStoreResource
 * @package App\Http\Resources\Booking\Response
 * @property integer $id идентификато бронирования
 * @property string $reserve_from дата и время начала бронирования
 * @property string $reserve_to дата и время окончания бронирования
 * @property string $price_type цель аренды студии
 * @property string $amount полная цена бронирования без учета скидок
 * @property string $discount размер скидки
 * @property string $amount_with_discount полная цена бронирования с учетом скидок
 * @property integer $duration время аренды в часах
 * @property float $prepayment размер внесенной предоплаты
 * @property string $user_comment комментарий клиента
 * @property string $statusText статус бронирования
 * @property array $members указанные участники
 */
class BookingStoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $extraCharge = $this->resource['amount_with_discount'] - $this->resource['prepayment'];
        return [
            'id' => $this->resource['id'],
            'reservedFrom' => date('c', strtotime($this->resource['reserve_from'])),
            'reservedTo' => date('c', strtotime($this->resource['reserve_to'])),
            'eventType' => $this->resource['price_type'],
            'prepayment' => $this->resource['prepayment'],
            'extraCharge' => $extraCharge > 0 ? $extraCharge : 0,
            'amount' => $this->resource['amount'],
            'discount' => $this->resource['discount'],
            'duration' => $this->resource['duration'],
            'status' => $this->resource['statusText'],
            'customerComment' => $this->resource['user_comment'],
            'room' => new RoomResource($this->resource['room']),
            'customer' => new CustomerResource($this->resource['client']),
            'members' => $this->resource['members'] ?? []
        ];
    }
}
