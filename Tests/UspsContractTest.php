<?php

namespace Omnibus\Usps\Tests;

use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Testing\GatewayContractTests;
use Omnibus\Usps\UspsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on USPS's recorded answers (APIs v3, test environment), a domestic parcel. */
final class UspsContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new UspsGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true, 'crid' => '12345678', 'mid' => '901234567', 'account' => '1000012345'];
    }

    protected function sessionRoutes(): array
    {
        return ['POST /oauth2/v3/token'];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(
            new Address('Camille Durand', ['475 L\'Enfant Plaza SW'], '20260', 'Washington', 'US', phone: '2025551234'),
            new Address('Alex Martin', ['1 Infinite Loop'], '95014', 'Cupertino', 'US'),
            [new Parcel(1200, 30, 20, 10)],
            reference: 'ORDER-1042',
            options: ['sender_state' => 'DC', 'recipient_state' => 'CA'],
        );
    }

    protected function contractTrackingNumber(): string
    {
        return '9400111899223456789012';
    }

    protected function operationOptions(): array
    {
        return ['crid' => [\Omnibus\Request\Shipping::class], 'account' => [\Omnibus\Request\Shipping::class]];
    }

    protected function recordedAnswers(): array
    {
        $office = static fn (string $id, float $distance) => ['facilityID' => $id, 'facilityName' => 'CUPERTINO '.$id, 'address' => ['streetAddress' => '21701 STEVENS CREEK BLVD', 'city' => 'CUPERTINO', 'ZIPCode' => '95014'], 'latitude' => 37.32, 'longitude' => -122.04, 'distance' => $distance, 'hours' => [['open' => '08:30', 'close' => '17:00']]];

        return [
            'POST /oauth2/v3/token' => ['access_token' => 'tok', 'expires_in' => 28799],
            '/prices/v3/base-rates/search' => static function (string $method, string $url, array $options): array {
                $class = json_decode((string) ($options['body'] ?? '{}'), true)['mailClass'] ?? 'PRIORITY_MAIL';

                return ['rates' => [['description' => $class.' Machinable Single-piece', 'price' => 'USPS_GROUND_ADVANTAGE' === $class ? 8.45 : 11.3, 'mailClass' => $class]]];
            },
            '/payments/v3/payment-authorization' => ['paymentAuthorizationToken' => 'pay-tok'],
            '/labels/v3/label' => ['labelMetadata' => ['trackingNumber' => '9400111899223456789012', 'postage' => 8.45], 'labelImage' => base64_encode('%PDF-1.7 usps')],
            '/tracking/v3/tracking/' => ['trackingNumber' => '9400111899223456789012', 'status' => 'Delivered', 'statusCategory' => 'Delivered', 'trackingEvents' => [
                ['eventType' => 'Delivered, In/At Mailbox', 'eventTimestamp' => '2026-10-02T14:05:00', 'eventCity' => 'CUPERTINO', 'eventState' => 'CA', 'eventZIP' => '95014', 'eventCode' => '01'],
                ['eventType' => 'Arrived at USPS Regional Facility', 'eventTimestamp' => '2026-10-01T22:10:00', 'eventCity' => 'SAN JOSE', 'eventState' => 'CA', 'eventCode' => '10'],
            ]],
            '/locations/v3/dropoff-locations' => ['locations' => [$office('1393910', 0.7), $office('1393911', 1.9), $office('1393912', 3.2)]],
        ];
    }
}
