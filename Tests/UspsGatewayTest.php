<?php

namespace Omnibus\Usps\Tests;

use Omnibus\Exception\CarrierException;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use Omnibus\Usps\UspsGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UspsGatewayTest extends TestCase
{
    private array $calls = [];

    private static function shipment(string $country = 'US'): Shipment
    {
        return new Shipment(
            new Address('Camille Durand', ['475 L\'Enfant Plaza SW'], '20260', 'Washington', 'US', phone: '2025551234'),
            new Address('Alex Martin', ['1 Infinite Loop'], 'US' === $country ? '95014' : '75001', 'US' === $country ? 'Cupertino' : 'Paris', $country),
            [new Parcel(1200, 30, 20, 10)],
            reference: 'ORDER-1042',
            options: ['sender_state' => 'DC', 'recipient_state' => 'CA'],
        );
    }

    private function gateway(bool $payer = true): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://apis-tem.usps.com', $url);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : [], $options['headers']];

            return match (true) {
                '/oauth2/v3/token' === $path => new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 28799])),
                '/prices/v3/base-rates/search' === $path => new MockResponse(json_encode(['rates' => [['description' => 'USPS_GROUND_ADVANTAGE' === ($this->calls[array_key_last($this->calls)][2]['mailClass'] ?? '') ? 'USPS Ground Advantage Machinable Single-piece' : 'Priority Mail Machinable Single-piece', 'price' => 'USPS_GROUND_ADVANTAGE' === ($this->calls[array_key_last($this->calls)][2]['mailClass'] ?? '') ? 8.45 : 11.3, 'mailClass' => $this->calls[array_key_last($this->calls)][2]['mailClass']]]])),
                '/payments/v3/payment-authorization' === $path => new MockResponse(json_encode(['paymentAuthorizationToken' => 'pay-tok'])),
                '/labels/v3/label' === $path => new MockResponse(json_encode(['labelMetadata' => ['trackingNumber' => '9400111899223456789012', 'postage' => 8.45], 'labelImage' => base64_encode('%PDF-1.7 usps')])),
                str_starts_with($path, '/tracking/v3/tracking/') => new MockResponse(json_encode(['trackingNumber' => '9400111899223456789012', 'status' => 'Delivered', 'statusCategory' => 'Delivered', 'trackingEvents' => [
                    ['eventType' => 'Delivered, In/At Mailbox', 'eventTimestamp' => '2026-10-02T14:05:00', 'eventCity' => 'CUPERTINO', 'eventState' => 'CA', 'eventZIP' => '95014', 'eventCode' => '01'],
                    ['eventType' => 'Arrived at USPS Regional Facility', 'eventTimestamp' => '2026-10-01T22:10:00', 'eventCity' => 'SAN JOSE', 'eventState' => 'CA', 'eventCode' => '10'],
                ]])),
                '/locations/v3/dropoff-locations' === $path => new MockResponse(json_encode(['locations' => [['facilityID' => '1393910', 'facilityName' => 'CUPERTINO', 'address' => ['streetAddress' => '21701 STEVENS CREEK BLVD', 'city' => 'CUPERTINO', 'ZIPCode' => '95014'], 'latitude' => 37.32, 'longitude' => -122.04, 'distance' => 0.7, 'dropShipAcceptanceTimes' => [['dayOfWeek' => 'TUESDAY', 'times' => [['openTime' => '08:30', 'closeTime' => '12:00'], ['openTime' => '13:00', 'closeTime' => '17:00']]], ['dayOfWeek' => 'SATURDAY', 'times' => [['openTime' => '9:00', 'closeTime' => '12:00']]], ['dayOfWeek' => 'SUNDAY', 'times' => []]]]]])),
                default => new MockResponse(json_encode(['error' => ['code' => '404', 'message' => 'No such resource '.$path]]), ['http_code' => 404]),
            };
        });

        return (new UspsGatewayFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true] + ($payer ? ['crid' => '12345678', 'mid' => '901234567', 'account' => '1000012345'] : []));
    }

    public function testPricesAreAskedPerMailClassInPoundsAndInches(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['USPS_GROUND_ADVANTAGE', 'PRIORITY_MAIL', 'PRIORITY_MAIL_EXPRESS'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(845, $rates[0]->amount);
        self::assertSame('USD', $rates[0]->currency);
        $sent = $this->calls[1][2];
        self::assertSame('20260', $sent['originZIPCode']);
        self::assertSame(2.65, $sent['weight'], '1200 g in pounds');
        self::assertSame(11.8, $sent['length'], '30 cm in inches');
    }

    public function testALabelIsPaidThroughThePaymentAuthorization(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('9400111899223456789012', $label->trackingNumber);
        self::assertSame('%PDF-1.7 usps', $label->content);
        $payment = array_values(array_filter($this->calls, fn ($c) => '/payments/v3/payment-authorization' === $c[1]))[0];
        self::assertSame('12345678', $payment[2]['roles'][0]['CRID']);
        $labelCall = array_values(array_filter($this->calls, fn ($c) => '/labels/v3/label' === $c[1]))[0];
        self::assertContains('X-Payment-Authorization-Token: pay-tok', $labelCall[3]);
        self::assertSame('95014', $labelCall[2]['toAddress']['ZIPCode']);
        self::assertSame('CA', $labelCall[2]['toAddress']['state']);

        $this->expectException(\Omnibus\Exception\RequestNotSupportedException::class); // without its option, not offered
        $this->gateway(false)->ship(self::shipment());
    }

    public function testAnInternationalLabelIsRefusedAndTrackingRead(): void
    {
        try {
            $this->gateway()->ship(self::shipment('FR'));
            self::fail('needs a customs form');
        } catch (CarrierException $e) {
            self::assertStringContainsString('customs', $e->getMessage());
        }
        $tracking = $this->gateway()->track('9400111899223456789012');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->events[0]->status);
        self::assertSame('CUPERTINO CA 95014', $tracking->latest()->location);
    }

    public function testPostOfficesAreFoundNearAnAddress(): void
    {
        $points = $this->gateway()->pickupPoints(self::shipment()->recipient);
        self::assertSame('1393910', $points[0]->id);
        self::assertSame(1126, $points[0]->distance, '0.7 miles in metres');
    }

    /**
     * Locations v3 (USPS/api-examples, dropoff-location-v3-response.json): dropShipAcceptanceTimes
     * names each day (dayOfWeek MONDAY...) with its times (openTime, closeTime). Read by the day's
     * name as ISO weekdays, every slot "HH:MM"; a day without times is left out.
     */
    public function testOpeningHoursAreReadByTheDaysName(): void
    {
        $points = $this->gateway()->pickupPoints(self::shipment()->recipient);
        self::assertSame([2 => [['08:30', '12:00'], ['13:00', '17:00']], 6 => [['09:00', '12:00']]], $points[0]->openingHours);
    }
}
