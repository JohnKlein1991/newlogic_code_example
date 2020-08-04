<?php

namespace App\Http\Requests\Front\Bookings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Класс валидации для бронирования
 * Class BookingStoreRequest
 * @package App\Http\Requests\Front\Bookings
 */
class BookingStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'roomId' => ['required', 'integer'],
            'reserveFrom' => ['required', 'string', 'date'],
            'reserveTo' => ['required', 'string', 'date', 'after:reserveFrom'],
            'priceType' => ['required', 'string', Rule::in(['photo', 'video', 'event'])],
            'prepayment' => ['required', 'integer', 'min:50','max:100'],
            'customer.fullName' => ['required', 'string'],
            'customer.phone' => ['string', 'required'],
            'customer.email' => ['required', 'email'],
            'consumerId' => ['integer', 'nullable'],
            'extras' => ['array'],
            'seats' => ['integer'],
            'userComment' => ['string'],
            'promocode' => ['string']
        ];
    }
}
