<?php

namespace App\Http\Controllers\Api\Front\V1\Bookings;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Http\Requests\Front\Bookings\BookingStoreRequest;
use App\Http\Resources\Booking\Response\BookingStoreResource;
use Exception;

/**
 * Класс создания нового бронирования
 * Class BookingStoreController
 * @package App\Http\Controllers\Api\Front\V1\Bookings
 */
class BookingStoreController extends Controller
{

    /** @var BookingService */
    private $bookingService;

    /**
     * BookingStoreController constructor.
     * @param BookingService $bookingService
     */
    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * @param BookingStoreRequest $request
     * @return \Illuminate\Http\JsonResponse|BookingStoreResource
     */
    public function __invoke(BookingStoreRequest $request)
    {
        // Если не пришли UTM-метки, поставим метки по умолчанию
        $request->utm_medium = $request->utm_medium ?? 'front';

        try {
            $store = $this->bookingService->create($request, null);
        } catch (Exception $e) {
            return response()->json(
                [
                    'source' => 'bookingStoreController',
                    'title' => $e->getMessage()
                ],
                $e->getCode()
            );
        }
        return new BookingStoreResource($store);
    }
}
