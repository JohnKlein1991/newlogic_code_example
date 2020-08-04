<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\EmailConflictException;
use App\Exceptions\InternalServerErrorException;
use App\Exceptions\NotFoundException;
use App\Facades\DiscountFacade;
use App\Http\Requests\Booking\StoreTechnicalRequest;
use App\Http\Resources\Booking\Response\RoomExtraResource;
use App\Models\Booking;
use App\Models\DeferredAction;
use App\Models\Refund;
use App\Models\Room\Extra;
use App\Models\Room;
use App\Models\Room\Setting;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use App\Services\Front\BookingPriceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Models\UtmCode;


/**
 * Сервис бронирований
 *
 * Class BookingService
 * @package App\Services
 */
class BookingService
{
    /**
     * Процент предоплаты
     */
    private const PREPAYMENT = [
        50,
        100
    ];

    /**
     * @var CustomerService $customerService
     */
    private $customerService;

    /**
     * @var GoogleCalendarService $googleCalendarService
     */
    private $googleCalendarService;

    private $bookingPriceService;

    /**
     * BookingService constructor.
     * @param \App\Services\CustomerService $customerService
     * @param GoogleCalendarService $googleCalendarService
     * @param BookingPriceService $bookingPriceService
     */
    public function __construct(CustomerService $customerService, GoogleCalendarService $googleCalendarService, BookingPriceService $bookingPriceService)
    {
        $this->customerService = $customerService;
        $this->googleCalendarService = $googleCalendarService;
        $this->bookingPriceService = $bookingPriceService;
    }

    /**
     * Проверяем что выбранный период пуст
     *
     * @param Room $room
     * @param Carbon $reserveFrom
     * @param Carbon $reserveTo
     * @param Booking $booking
     *
     * @return bool
     */
    protected function isPeriodEmpty(Room $room, Carbon $reserveFrom, Carbon $reserveTo, Booking $booking = null): bool
    {
        // Проверяем что выбранная дата не старее чем текущее время
        if ($reserveFrom <= Carbon::now()) {
            return false;
        }

        $reserveFromPlus = Carbon::createFromTimeString($reserveFrom->format(DATE_ATOM))
            ->addSecond();
        $reserveToMinus = Carbon::createFromTimeString($reserveTo->format(DATE_ATOM))
            ->subSecond();

        $query = $room->bookings()
            ->where('status', '!=', Booking::STATUS['CANCELLED'])
            ->where('status', '!=', Booking::STATUS['EXPIRED'])
            ->where(function ($query) use ($reserveFrom, $reserveTo, $reserveFromPlus, $reserveToMinus) {
                $query->where(function ($query) use ($reserveFrom, $reserveTo, $reserveFromPlus, $reserveToMinus) {
                    // Начала или конец какого-то бронирования пересекается с нашим диапозоном
                    $query->whereBetween('reserve_from', [$reserveFrom, $reserveToMinus])
                        ->orWhereBetween('reserve_to', [$reserveFromPlus, $reserveTo]);
                })
                    ->orWhere(function ($query) use ($reserveFrom, $reserveTo) {
                        // Наше бронирование лежит вдругом бронировании
                        $query->where('reserve_from', '<=', $reserveFrom)
                            ->where('reserve_to', '>=', $reserveTo);
                    });
            })
            ->where(function ($query) {
                $query->where('expires_at', '>', Carbon::now())
                    ->orWhereNull('expires_at')
                    ->orWhereIn('status', [Booking::STATUS['PAID'], Booking::STATUS['DONE']]);
            });

        if (!empty($booking)) {
            $query->where('bookings.id', '!=', $booking->id);
        }

        return empty($query->count());
    }

    /**
     * Проверяем то что выбран минимум один час бронирования
     *
     * @param CarbonInterface $reserveFrom
     * @param CarbonInterface $reserveTo
     * @return int
     */
    protected function getReserveDurationInHours(CarbonInterface $reserveFrom, CarbonInterface $reserveTo): int
    {
        $duration = $reserveTo->diffInHours($reserveFrom);

        if ($duration < Booking::MIN_DURATION_IN_HOURS) {
            $duration = Booking::MIN_DURATION_IN_HOURS;
        }

        return $duration;
    }

    /**
     *  Сериализация допов
     *
     * @param array $extras
     * @return array
     */
    protected function serializeExtras(array $extras): array
    {
        $extrasCollection = new Collection();

        foreach ($extras as $extra) {
            $extraModel = Extra::find($extra['id']);
            if (empty($extraModel)) {
                continue;
            }
            $extraModel->count = $extra['count'];
            $extrasCollection->push($extraModel);
        }

        return RoomExtraResource::collection($extrasCollection)->jsonSerialize();
    }

    /**
     * Создание бронирования
     *
     * @param Request $request
     * @param User|null $manager
     * @return Booking
     *
     * @throws ConflictException
     * @throws EmailConflictException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     */
    public function create(Request $request, ?User $manager): Booking
    {
        $calculate = $this->bookingPriceService->calculateForBooking();

        $reserveTo = Carbon::createFromTimeString($request->reserveTo);
        $reserveFrom = Carbon::createFromTimeString($request->reserveFrom);
        $duration = $this->getReserveDurationInHours($reserveFrom, $reserveTo);

        $extras = new Collection();

        if (isset($request->extras) && is_array($request->extras)) {
            foreach ($request->extras as $extra) {
                $extraModel = Extra::find($extra['id']);
                if (empty($extraModel)) {
                    continue;
                }
                $extraModel->count = $extra['count'];
                $extras->push($extraModel);
            }

            $extras = $this->serializeExtras($request->extras);
        }

        // Если есть предоплата, сравниваем правильна ли указана сумма 50% или 100% к оплате(при оплате с фронта)
        // и считаем размер в рублях
        $prepayment = 0;
        if ($calculate['room']->is_prepayment && is_null($manager)) {
            if(!in_array($request->prepayment, self::PREPAYMENT)) {
                throw new ConflictException('Указан неверный размер предоплаты');
            }
            $prepayment = $calculate['sumForPay'];
        }

        // Проверяем наличие ID в таблице Users
        $consumer = User::where('email', "=", $request->customer['email'])->first();

        if (is_null($consumer)) {
            $consumer = User::where('email', "=", $request->customer['email'])->withTrashed()->first();
            if (!is_null($consumer)) {
                throw new EmailConflictException('Адрес электронной почты принадлежит другому пользователю');
            }
        }
        if(empty($consumer) && !is_null($request->consumerId)){
            $consumer = User::find($request->consumerId);
        }

        if (empty($consumer) && array_key_exists('id', $request->customer)) {
            $consumer = User::find($request->customer['id']);
        }

        if (empty($consumer)) {
            $password = $this->customerService->createPassword();
            $fullName = $request->customer['fullName'];
            $email = $request->customer['email'];
            $phone = $request->customer['phone'];
            // Регистрируем нового пользователя
            $consumer = $this->customerService->createDefault($fullName, $email, $password, $phone);
        }

        // Создание нового бронирования
        $booking = new Booking([
            'room_id' => $calculate['room']->id,
            'user_id' => $consumer->id,
            'manager_id' => $manager instanceof User ? $manager->id : null,
            'amount' => $calculate['amount'],
            'discount' => $calculate['discount'],
            'amount_with_discount' => $calculate['amountWithDiscount'],
            'reserve_from' => $reserveFrom,
            'reserve_to' => $reserveTo,
            'duration' => $duration,
            'user_comment' => $request->userComment ?? null,
            'extras' => count($extras) > 0 ? json_encode($extras) : '',
            'price_type' => $request->priceType,
            'google_export_status' => Booking::GOOGLE['EXPORT_NONE'],
            'expires_at' => Carbon::now()->addDay(),
            'description' => $request->managerComment ?? null,
            'status' => Booking::STATUS['NEW'],
            'prepayment' => $prepayment,
            'members' => $request->members ?? []
        ]);

        if (!$booking->save()) {
            throw new InternalServerErrorException('Возникла ошибка при добавлении бронирования');
        }
        $booking->refresh();

        $this->createUtmCode($booking, $request);

        $this->googleCalendarService->sendCreateBookingInGoogleCalendarEvent($calculate['room'], $booking);

        return $booking;
    }


    /**
     * Создает запись в таблице UTM-меток
     *
     * @param Booking $booking
     * @param Request $request
     * @return UtmCode|null
     */
    protected function createUtmCode(Booking $booking, Request $request): ?UtmCode
    {
        if (empty($request->utm_source)) {
            $request->utm_source = $_SERVER['HTTP_REFERER'] ?? null;
        }

        if (empty($request->utm_source)
            && empty($request->utm_medium)
            && empty($request->utm_campaign)
            && empty($request->utm_content)
            && empty($request->utm_term)
        ) {
            return null;
        }
        $utm = new UtmCode();
        $utm->utm_source = $request->utm_source ?? null;
        $utm->utm_medium = $request->utm_medium ?? null;
        $utm->utm_campaign = $request->utm_campaign ?? null;
        $utm->utm_content = $request->utm_content ?? null;
        $utm->utm_term = $request->utm_term ?? null;
        $utm->booking_id = $booking->id;
        $utm->save();

        return $utm;
    }

    /**
     * Создание технической брони
     *
     * @param StoreTechnicalRequest $request
     * @return Booking
     * @throws ConflictException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     */
    public function createTechnical(StoreTechnicalRequest $request): Booking
    {
        $reserveTo = Carbon::createFromTimeString($request->reserveTo);
        $reserveFrom = $reserveFromWithDuration = Carbon::createFromTimeString($request->reserveFrom);

        $duration = $this->getReserveDurationInHours($reserveFrom, $reserveTo);

        $reserveTo = (clone $reserveFrom)->addHours($duration);

        /** @var Room $room */
        $room = Room::find($request->room['id']);

        if (empty($room)) {
            throw new NotFoundException('Зал не найден');
        }

        // Проверяем что выбранный период пуст
        if (!$this->isPeriodEmpty($room, $reserveFrom, $reserveTo)) {
            throw new ConflictException('Выбранный период времени пересекается с другим бронированием');
        }

        $booking = new Booking();
        $booking->user_id = $request->user()->id;
        $booking->status = Booking::STATUS['PAID'];
        $booking->amount = 1;
        $booking->amount_with_discount = 1;
        $booking->prepayment = 0;
        $booking->payed = 1;
        $booking->discount = 0;
        $booking->reserve_from = $reserveFrom;
        $booking->reserve_to = $reserveTo;
        $booking->duration = $duration;
        $booking->room_id = $room->id;
        $booking->manager_id = $request->user()->id;
        $booking->price_type = 'photo';
        $booking->expires_at = Carbon::now()->addDay();
        $booking->is_service = 1;
        $booking->description = $request->managerComment ?? '';

        if (!$booking->save()) {
            throw new InternalServerErrorException('Возникла ошибка при добавлении бронирования');
        }

        $booking->refresh();

        $this->googleCalendarService->sendCreateBookingInGoogleCalendarEvent($room, $booking);

        return $booking;
    }

    /**
     * Обновление бронирования
     *
     * @param Request $request
     * @param Booking $booking
     *
     * @return Booking
     *
     * @throws ConflictException
     * @throws NotFoundException
     * @throws InternalServerErrorException
     */
    public function update(Request $request, Booking $booking): Booking
    {
        $reserveTo = Carbon::createFromTimeString($request->reserveTo);
        $reserveFrom = Carbon::createFromTimeString($request->reserveFrom);

        $duration = $this->getReserveDurationInHours($reserveFrom, $reserveTo);

        $reserveTo = (clone $reserveFrom)->addHours($duration);

        // Получение зала из бронирования
        $room = $booking->room;

        // Проверяем не изменился ли зал
        if ($request->roomId !== $room->id) {
            // Получение зала
            $room = Room::with([
                'discounts',
                'videoPrices',
                'photoPrices',
                'eventPrices',
                'studio.extras' => function ($query) use ($request) {
                    $query->whereIn('id', $request->extras)
                        ->whereNotNull('published_at');
                }
            ])->find($request->roomId);

            if (empty($room)) {
                throw new NotFoundException('Зал не найден');
            }
        }

        $expiresAt = $booking->expires_at;

        // Проверяем изменился ли период
        if ($reserveFrom != $booking->reserve_from || $reserveTo != $booking->reserve_to) {
            // Проверяем что новый выбранный период пуст
            if (!$this->isPeriodEmpty($room, $reserveFrom, $reserveTo, $booking)) {
                throw new ConflictException('Выбранный период времени пересекается с другим бронированием');
            }

            $expiresAt = Carbon::now()->addDay();
        }

        // Получение скидки для бронирования
        $discount = DiscountFacade::calculateForBooking(
            $room,
            Carbon::createFromTimeString($reserveFrom->format(DATE_ATOM))->addSecond(),
            $reserveTo,
            $request->priceType
        );

        // Проверяем что статус бронирования новый и тогда мы можем поменять предоплату
        if (Booking::STATUS['NEW'] === $booking->status) {
            $prepayment = $request->prepayment ?? $booking->prepayment;
        } else {
            $prepayment = $booking->prepayment;
        }

        $extras = $this->serializeExtras($request->extras);

        $result = $booking->update([
            'room_id' => $room->id,
            'manager_id' => $request->user()->id,
            'amount' => $discount['amount'],
            'discount' => $discount['discount'],
            'amount_with_discount' => $discount['amountWithDiscount'],
            'reserve_from' => $reserveFrom,
            'reserve_to' => $reserveTo,
            'duration' => $duration,
            'extras' => count($extras) > 0 ? json_encode($extras) : '',
            'price_type' => $request->priceType,
            'expires_at' => $expiresAt,
            'description' => $request->managerComment ?? null,
            'prepayment' => $prepayment,
            'members' => $request->members ?? []
        ]);

        if (!$result) {
            throw new InternalServerErrorException('Возникла ошибка при обновлении студии');
        }

        $booking->refresh();

        $this->googleCalendarService->sendUpdateBookingInGoogleCalendarEvent($room, $booking);

        return $booking;
    }

    /**
     * Отмена бронирования с формированием запроса
     * на возврат предоплаты
     *
     * @param Booking $booking
     * @return Booking
     */
    public function cancel(Booking $booking) : Booking
    {
        $user = \Auth::user();

        // Отменяем бронирование
        $booking->canceled_at = Carbon::now();
        $booking->status = 1;
        $bookingSaved = $booking->save();

        //  Выполняем возврат в случае, если была внесена предоплата
        if ($booking->payed > 0) {
            /**
             * Создание запроса на возмещение предоплаты
             *
             * @var Refund $refund
             */
            $refund = new Refund();
            $refund->booking_id = $booking->id;
            $refund->amount = $booking->payed;
            $refund->user_id = $user->id;
            $refund->status = 0;

            $refundSaved = $refund->save();

            /**
             * Внесение операций в список отложенных операций
             *
             * @var DeferredAction $deferred
             */
            $deferred = new DeferredAction();

            if ($refundSaved && !$booking->is_service) {
                $deferred->bookingNeedReturn($booking);
            }

            if ($bookingSaved && !$booking->is_service) {
                $deferred->bookingCancel($booking);
            }

        }

        $booking->refresh();

        return $booking;
    }
}
