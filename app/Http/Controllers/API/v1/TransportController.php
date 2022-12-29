<?php

namespace App\Http\Controllers\API\v1;

use App\Enum\Business;
use App\Enum\StatusVisibility;
use App\Enum\TravelType;
use App\Exceptions\Checkin\AlreadyCheckedInException;
use App\Exceptions\CheckInCollisionException;
use App\Exceptions\HafasException;
use App\Exceptions\StationNotOnTripException;
use App\Exceptions\TrainCheckinAlreadyExistException;
use App\Http\Controllers\API\ResponseController;
use App\Http\Controllers\Backend\Transport\HomeController;
use App\Http\Controllers\Backend\Transport\TrainCheckinController;
use App\Http\Controllers\HafasController;
use App\Http\Controllers\TransportController as TransportBackend;
use App\Http\Resources\HafasTripResource;
use App\Http\Resources\StatusResource;
use App\Http\Resources\TrainStationResource;
use App\Models\Event;
use App\Models\TrainStation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Annotations as OA;

class TransportController extends Controller
{
    /**
     * @param Request $request
     * @param string  $name
     *
     * @return JsonResponse
     * @see All slashes (as well as encoded to %2F) in $name need to be replaced, preferrably by a space (%20)
     */
    public function departures(Request $request, string $name): JsonResponse {
        $validated = $request->validate([
                                            'when'       => ['nullable', 'date'],
                                            'travelType' => ['nullable', new Enum(TravelType::class)],
                                        ]);

        try {
            $trainStationboardResponse = TransportBackend::getDepartures(
                stationQuery: $name,
                when:         isset($validated['when']) ? Carbon::parse($validated['when']) : null,
                travelType:   TravelType::tryFrom($validated['travelType'] ?? null),
            );
        } catch (HafasException) {
            return $this->sendError(__('messages.exception.generalHafas', [], 'en'), 400);
        } catch (ModelNotFoundException) {
            return $this->sendError(__('controller.transport.no-station-found', [], 'en'));
        }

        return $this->sendResponse(
            data:       $trainStationboardResponse['departures'],
            additional: ["meta" => ['station' => $trainStationboardResponse['station'],
                                    'times'   => $trainStationboardResponse['times'],
                        ]]
        );
    }

    public function getTrip(Request $request): JsonResponse {
        $validated = $request->validate([
                                            'tripId'   => ['required', 'string'],
                                            'lineName' => ['required', 'string'],
                                            'start'    => ['required', 'numeric', 'gt:0'],
                                        ]);

        try {
            $hafasTrip = TrainCheckinController::getHafasTrip(
                $validated['tripId'],
                $validated['lineName'],
                (int) $validated['start']
            );
            return $this->sendResponse(data: new HafasTripResource($hafasTrip));
        } catch (StationNotOnTripException) {
            return $this->sendError(__('controller.transport.not-in-stopovers', [], 'en'), 400);
        }
    }

    /**
     * @OA\Get(
     *      path="/trains/station/nearby",
     *      operationId="trainStationsNearby",
     *      tags={"Checkin"},
     *      summary="Location based search for trainstations",
     *      description="Returns the nearest station to the given coordinates",
     *      @OA\Parameter(
     *          name="latitude",
     *          in="query",
     *          description="latitude",
     *          example=48.991,
     *          required=true
     *     ),
     *     @OA\Parameter(
     *          name="longitude",
     *          in="query",
     *          description="longitude",
     *          example=8.4005,
     *          required=true
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="data", type="array",
     *                  @OA\Items(
     *                      ref="#/components/schemas/TrainStation"
     *                  )
     *              )
     *          )
     *       ),
     *       @OA\Response(response=400, description="Bad request"),
     *       @OA\Response(response=401, description="Unauthorized"),
     *       @OA\Response(response=404, description="No station found"),
     *       @OA\Response(response=503, description="There has been an error with our data provider"),
     *       security={
     *          {"token": {}},
     *          {}
     *       }
     *     )
     */
    public function getNextStationByCoordinates(Request $request): JsonResponse {
        $validated = $request->validate([
                                            'latitude'  => ['required', 'numeric', 'min:-90', 'max:90'],
                                            'longitude' => ['required', 'numeric', 'min:-180', 'max:180'],
                                        ]);

        try {
            $nearestStation = HafasController::getNearbyStations(
                latitude:  $validated['latitude'],
                longitude: $validated['longitude'],
                results:   1
            )->first();
        } catch (HafasException) {
            return $this->sendError(__('messages.exception.generalHafas', [], 'en'), 503);
        }

        if ($nearestStation === null) {
            return $this->sendError(__('controller.transport.no-station-found', [], 'en'));
        }

        return $this->sendResponse(new TrainStationResource($nearestStation));
    }

    /**
     * @OA\Post(
     *      path="/trains/checkin",
     *      operationId="createTrainCheckin",
     *      tags={"Checkin"},
     *      summary="Create a checkin",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/TrainCheckinRequestBody")
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="successful operation",
     *          @OA\JsonContent(ref="#/components/schemas/TrainCheckinResponse")
     *       ),
     *       @OA\Response(response=400, description="Bad request"),
     *       @OA\Response(response=409, description="Checkin collision"),
     *       @OA\Response(response=403, description="User not authorized"),
     *       security={
     *           {"token": {}},
     *           {}
     *       }
     *     )
     * @TODO document the responses
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse {
        $validated = $request->validate([
                                            'body'        => ['nullable', 'max:280'],
                                            'business'    => ['nullable', new Enum(Business::class)],
                                            'visibility'  => ['nullable', new Enum(StatusVisibility::class)],
                                            'eventId'     => ['nullable', 'integer', 'exists:events,id'],
                                            'tweet'       => ['nullable', 'boolean'],
                                            'toot'        => ['nullable', 'boolean'],
                                            'chainPost'   => ['nullable', 'boolean'],
                                            'ibnr'        => ['nullable', 'boolean'],
                                            'tripId'      => ['required'],
                                            'lineName'    => ['required'],
                                            'start'       => ['required', 'numeric'],
                                            'destination' => ['required', 'numeric'],
                                            'departure'   => ['required', 'date'],
                                            'arrival'     => ['required', 'date'],
                                            'force'       => ['nullable', 'boolean']
                                        ]);

        try {
            $searchKey          = isset($validated['ibnr']) ? 'ibnr' : 'id';
            $originStation      = TrainStation::where($searchKey, $validated['start'])->first();
            $destinationStation = TrainStation::where($searchKey, $validated['destination'])->first();

            $trainCheckinResponse           = TrainCheckinController::checkin(
                user:           Auth::user(),
                hafasTrip:      HafasController::getHafasTrip($validated['tripId'], $validated['lineName']),
                origin:         $originStation,
                departure:      Carbon::parse($validated['departure']),
                destination:    $destinationStation,
                arrival:        Carbon::parse($validated['arrival']),
                travelReason:   Business::tryFrom($validated['business'] ?? Business::PRIVATE->value),
                visibility:     StatusVisibility::tryFrom($validated['visibility'] ?? StatusVisibility::PUBLIC->value),
                body:           $validated['body'] ?? null,
                event:          isset($validated['eventId']) ? Event::find($validated['eventId']) : null,
                force:          isset($validated['force']) && $validated['force'],
                postOnTwitter:  isset($validated['tweet']) && $validated['tweet'],
                postOnMastodon: isset($validated['toot']) && $validated['toot'],
                shouldChain:    isset($validated['chainPost']) && $validated['chainPost']
            );
            $trainCheckinResponse['status'] = new StatusResource($trainCheckinResponse['status']);
            return $this->sendResponse($trainCheckinResponse, 201);
        } catch (CheckInCollisionException $exception) {
            return $this->sendError([
                                        'status_id' => $exception->getCollision()->status_id,
                                        'lineName'  => $exception->getCollision()->HafasTrip->first()->linename
                                    ], 409);

        } catch (StationNotOnTripException) {
            return $this->sendError('Given stations are not on the trip/have wrong departure/arrival.', 400);
        } catch (HafasException $exception) {
            return $this->sendError($exception->getMessage(), 400);
        } catch (AlreadyCheckedInException) {
            return $this->sendError(__('messages.exception.already-checkedin', [], 'en'), 400);
        }
    }

    /**
     * @param string $stationName
     *
     * @return JsonResponse
     * @see All slashes (as well as encoded to %2F) in $name need to be replaced, preferrably by a space (%20)
     */
    public function setHome(string $stationName): JsonResponse {
        try {
            $trainStation = HafasController::getStations(query: $stationName, results: 1)->first();
            if ($trainStation === null) {
                return $this->sendError("Your query matches no station");
            }

            $station = HomeController::setHome(user: auth()->user(), trainStation: $trainStation);

            return $this->sendResponse(
                data: new TrainStationResource($station),
            );
        } catch (HafasException) {
            return $this->sendError("There has been an error with our data provider", 400);
        } catch (ModelNotFoundException) {
            return $this->sendError("Your query matches no station", 404);
        }
    }

    /**
     * @OA\Get(
     *      path="/trains/station/autocomplete/{query}",
     *      operationId="trainStationAutocomplete",
     *      tags={"Checkin"},
     *      summary="Autocomplete for trainstations",
     *      description="This request returns an array of max. 10 station objects matching the query. **CAUTION:** All slashes (as well as encoded to %2F) in {query} need to be replaced, preferrably by a space (%20)",
     *      @OA\Parameter(
     *          name="query",
     *          in="path",
     *          description="station query",
     *          example="Karls"
     *     ),
     *      @OA\Response(
     *          response=200,
     *          description="successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="data", type="array",
     *                  @OA\Items(
     *                      ref="#/components/schemas/ShortTrainStation"
     *                  )
     *              )
     *          )
     *       ),
     *       @OA\Response(response=401, description="Unauthorized"),
     *       @OA\Response(response=503, description="There has been an error with our data provider"),
     *       security={
     *          {"token": {}},
     *          {}
     *       }
     *     )
     */
    public function getTrainStationAutocomplete(string $query): JsonResponse {
        try {
            $trainAutocompleteResponse = TransportBackend::getTrainStationAutocomplete($query);
            return $this->sendResponse($trainAutocompleteResponse);
        } catch (HafasException) {
            return $this->sendError("There has been an error with our data provider", 503);
        }
    }

    /**
     * @OA\Get(
     *      path="/trains/station/history",
     *      operationId="trainStationHistory",
     *      tags={"Checkin"},
     *      summary="History for trainstations",
     *      description="This request returns an array of max. 10 most recent station objects that the user has arrived
     *      at.",
     *      @OA\Response(
     *          response=200,
     *          description="successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="data", type="array",
     *                  @OA\Items(
     *                      ref="#/components/schemas/TrainStation"
     *                  )
     *              )
     *          )
     *       ),
     *       @OA\Response(response=401, description="Unauthorized"),
     *       security={
     *          {"token": {}},
     *          {}
     *       }
     *     )
     */
    public function getTrainStationHistory(): AnonymousResourceCollection {
        return TrainStationResource::collection(TransportBackend::getLatestArrivals(auth()->user()));
    }
}
