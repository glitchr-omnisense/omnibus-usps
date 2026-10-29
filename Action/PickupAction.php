<?php

namespace Omnibus\Usps\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;
use Omnibus\Usps\Api;

/** The Locations API: post offices and drop-off points near an address. */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $near = $request->near;
        $data = $this->api->call('GET', '/locations/v3/dropoff-locations', null, array_filter(['streetAddress' => $near->line(0) ?: null, 'city' => $near->city, 'ZIPCode' => substr($near->postcode, 0, 5), 'radius' => 20, 'mailClass' => 'PRIORITY_MAIL']));
        $points = [];
        foreach ($data['locations'] ?? $data['dropoffLocations'] ?? (array_is_list($data) ? $data : []) as $location) {
            $address = $location['facilityAddress'] ?? $location['address'] ?? $location;
            $hours = self::hours($location['dropShipAcceptanceTimes'] ?? $location['hours'] ?? []);
            $points[] = new PickupPoint('usps', (string) ($location['facilityID'] ?? $location['locationID'] ?? $location['dropShipmentKey'] ?? $location['id'] ?? ''), (string) ($location['facilityName'] ?? $location['name'] ?? 'USPS'),
                new Address((string) ($location['facilityName'] ?? ''), array_values(array_filter([$address['streetAddress'] ?? null])), (string) ($address['ZIPCode'] ?? ''), (string) ($address['city'] ?? ''), 'US'),
                isset($location['latitude']) ? (float) $location['latitude'] : null, isset($location['longitude']) ? (float) $location['longitude'] : null,
                $hours, isset($location['distance']) ? (int) round(((float) $location['distance']) * 1609) : null);
        }
        $request->setResult(\array_slice($points, 0, $request->limit));
    }

    private const DAYS = ['MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6, 'SUNDAY' => 7];

    /**
     * USPS's days by name (dayOfWeek MONDAY..SUNDAY), each with its times (openTime, closeTime):
     * ISO weekdays with "HH:MM" slots; a day without a name or without times is left out.
     *
     * @return array<int, list<array{string, string}>>
     */
    private static function hours(array $days): array
    {
        $hours = [];
        foreach ($days as $day) {
            $iso = self::DAYS[strtoupper((string) ($day['dayOfWeek'] ?? ''))] ?? null;
            if (null === $iso) {
                continue;
            }
            foreach ($day['times'] ?? [] as $time) {
                $open = self::time($time['openTime'] ?? null);
                $close = self::time($time['closeTime'] ?? null);
                if (null !== $open && null !== $close) {
                    $hours[$iso][] = [$open, $close];
                }
            }
        }
        ksort($hours);

        return $hours;
    }

    private static function time(mixed $value): ?string
    {
        return \is_string($value) && preg_match('/^(\d{1,2}):?(\d{2})$/', trim($value), $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : null;
    }
}
