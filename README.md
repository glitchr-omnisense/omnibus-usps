# omnibus/usps

USPS for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): prices (Domestic and
International Prices APIs), domestic labels (Labels API, paid through the Payments API), tracking
(Tracking API) and drop-off locations (Locations API) - the USPS APIs v3 with OAuth2.

```php
$gateway = (new UspsGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        usps:
            factory: usps
            options:
                client_id: '%env(USPS_CLIENT_ID)%'
                client_secret: '%env(USPS_CLIENT_SECRET)%'
                sandbox: true                        # apis-tem.usps.com
                crid: '%env(USPS_CRID)%'             # for labels: the payer's Customer Registration ID,
                mid: '%env(USPS_MID)%'               #   Mailer ID and
                account: '%env(USPS_EPS_ACCOUNT)%'   #   Enterprise Payment System account
                rates: [...]                         # optional: configured prices instead of the Prices API
```

Weights go in pounds and sizes in inches: the conversion is here. The service is the mail class
(USPS_GROUND_ADVANTAGE, PRIORITY_MAIL, PRIORITY_MAIL_EXPRESS; the international classes for prices).
Shipment options: `sender_state` and `recipient_state` (two letters, labels need them),
`mail_classes` (the classes to price), `price_type` (COMMERCIAL, RETAIL), `label_format` (PDF, ZPL).
International labels are not offered: they need a customs form.

Credentials: an app in the [USPS Developer Portal](https://developer.usps.com) with the Prices,
Labels, Tracking, Locations and Payments APIs; a Business Customer Gateway account gives the
CRID, the MID and the EPS account that pays for labels.

Built from USPS's published API documentation and tested on recorded answers; not yet run against
the test environment: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the United States Postal Service. It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the USPS APIs's terms
([developers.usps.com/terms-and-conditions](https://developers.usps.com/terms-and-conditions)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
