# omnibus/usps

USPS for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): prices, domestic labels
paid through a payment authorization, tracking and drop-off locations, through the USPS APIs v3
on an OAuth 2 client-credentials token. The gateway calls:

| Operation | USPS API | Request |
|---|---|---|
| token | OAuth | `POST /oauth2/v3/token` |
| rate (domestic) | Domestic Prices | `POST /prices/v3/base-rates/search`, once per mail class |
| rate (international) | International Prices | `POST /international-prices/v3/base-rates/search`, once per mail class |
| payment authorization | Payments | `POST /payments/v3/payment-authorization` |
| ship | Labels | `POST /labels/v3/label` |
| track | Tracking | `GET /tracking/v3/tracking/{trackingNumber}?expand=DETAIL` |
| pickup points | Locations | `GET /locations/v3/dropoff-locations` |

on `https://apis.usps.com` (live) or `https://apis-tem.usps.com` (the test environment,
`sandbox: true`). USPS works in pounds and inches: the package converts grams and centimetres.

## Installation

```sh
composer require omnibus/usps
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- An app in the [USPS Developer Portal](https://developer.usps.com) with the Prices, Labels,
  Tracking, Locations and Payments APIs: its **client id** and **client secret**.
- For labels, from a Business Customer Gateway account: the payer's **CRID** (Customer
  Registration ID), **MID** (Mailer ID) and **EPS account** (Enterprise Payment System) that pays
  for them. Without the three, the gateway does not support `Shipping`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the app's client id |
| `client_secret` | string | - | yes | the app's client secret |
| `sandbox` | bool | `false` | no | `true`: `https://apis-tem.usps.com` |
| `crid` | ?string | `null` | for labels | the payer's Customer Registration ID |
| `mid` | ?string | `null` | for labels | the payer's Mailer ID |
| `account` | ?string | `null` | for labels | the EPS account number |
| `rates` | list | - | no | prices from configuration instead of the Prices APIs ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, the Prices APIs are not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `mail_classes` | rate | the three domestic or the three international classes | the mail classes to price |
| `price_type` | rate | `COMMERCIAL` | `COMMERCIAL` or `RETAIL` |
| `label_format` | ship | `PDF` | USPS's `imageType`, sent in capitals |
| `sender_state` | ship | - | the sender's two-letter state; labels need it |
| `recipient_state` | ship | - | the recipient's two-letter state; labels need it |

The address model has no state: it comes from these two options.

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (the Prices APIs), or configured rates with `rates` |
| `Shipping` | only with `crid`, `mid` and `account`; domestic only |
| `Tracking` | yes |
| `Pickup` | yes (post offices and drop-off locations) |
| `GetSlip` | no |
| `Cancel` | no |
| `Paperless` | no |

## Behaviour

**Token.** Asked for on the first call: a JSON `POST` with `grant_type=client_credentials`, the
client id and the secret. It is kept until 60 seconds before it expires (`expires_in`, 28799
seconds when USPS does not say). Every call sends `Authorization: Bearer` and has a 20-second
timeout.

**Units and addresses.** The weight is the shipment's total in pounds, rounded to two decimals
(at least 0.01); dimensions are the first parcel's, in inches with one decimal. A name is split at
its first space: the first word is `firstName`, the rest `lastName` (a one-word name is both). The
company is `firm`, the phone keeps its digits. A US address sends its first five postcode
characters as `ZIPCode`; another country sends `postalCode`, `country` and
`countryISOAlpha2Code`.

**Mail classes.** The service is the USPS mail class; labels come from
`Omnibus\Usps\Mapping::CLASSES` when USPS gives no description:

| Mail class | Label | |
|---|---|---|
| `USPS_GROUND_ADVANTAGE` | USPS Ground Advantage | domestic; the default when booking |
| `PRIORITY_MAIL` | Priority Mail | domestic |
| `PRIORITY_MAIL_EXPRESS` | Priority Mail Express | domestic |
| `FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE` | First-Class Package International | international |
| `PRIORITY_MAIL_INTERNATIONAL` | Priority Mail International | international |
| `PRIORITY_MAIL_EXPRESS_INTERNATIONAL` | Priority Mail Express International | international |

**Rate.** A recipient outside the US makes the request international. One base-rate search per
mail class (`mail_classes`, else the three of the table for that direction), whatever `Shipment::$service` says: from the sender's ZIP
to the recipient's ZIP (domestic) or country (international), the weight and dimensions,
processing category `MACHINABLE`, rate indicator `SP`, no destination entry, the `price_type`, the
mailing date `Shipment::$shippingDate` (else today). Each class gives at most one rate, the first
USPS lists, in `USD`; `days` is `null` and `toPickupPoint` `false`. A class USPS refuses for the
parcel is left out; when every class is refused, the last refusal is raised. Rates come cheapest
first.

**Ship.** A recipient outside the US is refused before any call (`Only domestic labels are
offered: an international one needs a customs form.`). The first label of a gateway asks the
Payments API for a payment authorization token (the configured CRID, MID and EPS account as payer
and label owner), kept for the gateway's life. The label request: image type `label_format`
(4 x 6 inch label), both addresses with their state options, the mail class `Shipment::$service`
or `USPS_GROUND_ADVANTAGE`, rate indicator `SP`, the weight in pounds, the first parcel's
dimensions in inches, processing category `MACHINABLE`, the mailing date, the reference (30
characters) as customer reference; headers `X-Payment-Authorization-Token` and `Accept:
application/vnd.usps.labels+json`. One label is made for the whole shipment. The tracking number
is `labelMetadata.trackingNumber`, the content `labelImage` base64-decoded; the format is
`application/pdf` for `PDF` and `application/zpl` for any other `label_format`. `trackingUrl` is
`https://tools.usps.com/go/TrackConfirmAction?tLabels=<number>`. A response without a number is a
`CarrierException` (`USPS made no label.`). `pickupPoint` is not sent.

> [!WARNING]
> Known defect: any `label_format` other than `PDF` is labelled `application/zpl`, where the label's real format should be given.

**Track.** `expand=DETAIL`; the locale is not sent. Each tracking event: its `eventTimestamp`, its
`eventType` as description, `eventCity eventState eventZIP` as location, `eventCode` as `code`;
sorted oldest first. An event's status is read from its type's words; the tracking's status from
USPS's `statusCategory` (else `status`) together with the latest event's type. The words, tried in
this order:

| The category or the event type contains | `TrackingStatus` |
|---|---|
| `delivered` | `DELIVERED` |
| `out for delivery` | `OUT_FOR_DELIVERY` |
| `available for pickup` | `AVAILABLE_FOR_PICKUP` |
| `pre-shipment`; the event type `label created` | `PENDING` |
| `alert`; the event type `notice left` | `EXCEPTION` |
| the category `return`; the event type `return to sender` | `RETURNED` |
| the category `transit`; the event type `arrived`, `departed`, `accept`, `in transit` | `IN_TRANSIT` |
| anything else | `UNKNOWN` |

**Pickup points.** Drop-off locations for `PRIORITY_MAIL` within a radius of 20 (USPS's unit,
miles) of the address's first street line, city and five-digit ZIP; the limit is applied to the
answer, not sent. Each location: its `facilityID` (else `locationID`, else `id`), its
`facilityName`, its street, ZIP and city, country `US`, coordinates, the distance converted from
miles to metres (x 1609). Opening hours are taken by position: the first entry of `hours` is
weekday 1 (Monday), the second weekday 2, and so on, each with one `[open, close]` slot as USPS
writes it; a day without hours keeps an empty slot (`['', '']`). The locations are kept in USPS's
order and cut to `limit`.

> [!WARNING]
> Known defect: opening hours are assigned by their position in the answer (Monday first assumed) and a day without hours keeps an empty `['', '']` slot, where the core wants ISO weekdays with `HH:MM` slots only.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Usps\UspsGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$usps = (new UspsGatewayFactory(HttpClient::create()))->create([
    'client_id' => getenv('USPS_CLIENT_ID'),
    'client_secret' => getenv('USPS_CLIENT_SECRET'),
    'sandbox' => true,
    'crid' => getenv('USPS_CRID'),           // the three payer options: labels
    'mid' => getenv('USPS_MID'),
    'account' => getenv('USPS_EPS_ACCOUNT'),
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['1600 Pennsylvania Ave NW'], '20500', 'Washington', 'US', phone: '+1 202 456 1111'),
    new Address('Ada Lovelace', ['1 Infinite Loop'], '95014', 'Cupertino', 'US', email: 'ada@example.org'),
    [new Parcel(1200, 30, 20, 10)],
    service: 'PRIORITY_MAIL',
    reference: 'CMD-1042',
    options: ['sender_state' => 'DC', 'recipient_state' => 'CA', 'price_type' => 'COMMERCIAL'],
);

foreach ($usps->rate($shipment) as $rate) {
    printf("%-24s %-24s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $usps->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $usps->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

foreach ($usps->pickupPoints($shipment->recipient, 3) as $point) {
    printf("%s %s %d m\n", $point->id, $point->name, $point->distance);
}
```

In Symfony:

```yaml
omnibus:
    gateways:
        usps:
            factory: usps
            options:
                client_id: '%env(USPS_CLIENT_ID)%'
                client_secret: '%env(USPS_CLIENT_SECRET)%'
                sandbox: '%env(bool:USPS_SANDBOX)%'
                crid: '%env(USPS_CRID)%'
                mid: '%env(USPS_MID)%'
                account: '%env(USPS_EPS_ACCOUNT)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `usps`:

- an HTTP status of 400 or more: `error.message`, else `error.errors[0].detail`, else the body's
  `message`, else `HTTP <status>`; `error.code` in `carrierCode`;
- a body that is not JSON, a network error: `USPS request failed: ...` or `USPS answered HTTP
  <status> with a body that is not JSON.`;
- no token: `USPS gave no token: ...`, USPS's `error_description`, or `USPS gave no token: check
  the client id and secret.`;
- no payment authorization: `USPS gave no payment authorization.`;
- an international label: refused before any call; a label answer without a number: `USPS made
  no label.`;
- rating: the last class's refusal, only when every class was refused.

A missing `client_id` or `client_secret` is an `InvalidConfigException` when the gateway is built;
`Shipping` without `crid`, `mid` and `account`, `GetSlip`, `Cancel` and `Paperless` raise
`RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/UspsGatewayTest.php` (one price request per mail class, weights in pounds
and sizes in inches, `USD`; a label paid through the payment authorization, the CRID in it, the
token header, the recipient's ZIP and state; an international label refused; tracking oldest first
with its location; post offices with their distance converted from miles) and
`Tests/UspsContractTest.php`, the family's conformance suite on recorded answers (`Shipping`
supported only with the three payer options). The example above was run in the core's harness on
those recorded answers.

Not verified: the README says the package is built from USPS's published API documentation and
was not yet run against the test environment; that needs the credentials above. Unchecked against
a real answer: which weekday the Locations API's first `hours` entry is (taken as Monday), the
format of its times (passed on as written), and the label image types USPS accepts besides `PDF`
(the README names `ZPL`).

## Responsibility

An independent, unofficial client, not affiliated with the United States Postal Service. Whoever
runs it complies with the USPS APIs' terms
([developers.usps.com/terms-and-conditions](https://developers.usps.com/terms-and-conditions)): see
the README's Responsibility section.
