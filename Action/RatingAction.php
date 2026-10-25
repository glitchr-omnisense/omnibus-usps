<?php

namespace Omnibus\Usps\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;
use Omnibus\Usps\Api;
use Omnibus\Usps\Mapping;

/**
 * The Prices API: the base rate of each mail class for the parcel, from
 * ZIP to ZIP (domestic) or to a country (international), one call a class.
 */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const DOMESTIC = ['USPS_GROUND_ADVANTAGE', 'PRIORITY_MAIL', 'PRIORITY_MAIL_EXPRESS'];
    private const INTERNATIONAL = ['FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE', 'PRIORITY_MAIL_INTERNATIONAL', 'PRIORITY_MAIL_EXPRESS_INTERNATIONAL'];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $international = 'US' !== strtoupper($s->recipient->country);
        $parcel = $s->parcels[0];
        $rates = [];
        $failure = null;
        $answered = 0;
        foreach ($s->option('mail_classes', $international ? self::INTERNATIONAL : self::DOMESTIC) as $class) {
            $body = array_filter([
                'originZIPCode' => substr($s->sender->postcode, 0, 5),
                'destinationZIPCode' => $international ? null : substr($s->recipient->postcode, 0, 5),
                'destinationCountryCode' => $international ? strtoupper($s->recipient->country) : null,
                'weight' => Mapping::pounds($s->weight()),
                'length' => Mapping::inches($parcel->length), 'width' => Mapping::inches($parcel->width), 'height' => Mapping::inches($parcel->height),
                'mailClass' => $class,
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
                'priceType' => $s->option('price_type', 'COMMERCIAL'),
                'mailingDate' => ($s->shippingDate ?? new \DateTimeImmutable())->format('Y-m-d'),
            ], static fn ($v) => null !== $v);
            try {
                $data = $this->api->call('POST', $international ? '/international-prices/v3/base-rates/search' : '/prices/v3/base-rates/search', $body);
            } catch (\Omnibus\Exception\CarrierException $e) {
                $failure = $e; // a class not offered for this parcel
                continue;
            }
            ++$answered;
            foreach ($data['rates'] ?? [] as $rate) {
                $rates[] = new Rate('usps', $class, (string) ($rate['description'] ?? Mapping::CLASSES[$class] ?? $class), (int) round(((float) ($rate['price'] ?? 0)) * 100), 'USD');
                break;
            }
        }
        if (0 === $answered && null !== $failure) {
            throw $failure; // every service refused: the carrier failed, not "no rate"
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
