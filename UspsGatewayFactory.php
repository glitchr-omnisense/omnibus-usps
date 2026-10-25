<?php

namespace Omnibus\Usps;

use Omnibus\Config;
use Omnibus\GatewayFactory;
use Omnibus\Usps\Action\PickupAction;
use Omnibus\Usps\Action\RatingAction;
use Omnibus\Usps\Action\ShippingAction;
use Omnibus\Usps\Action\TrackingAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     client_id: '%env(USPS_CLIENT_ID)%'        # an app in the USPS Developer Portal
 *     client_secret: '%env(USPS_CLIENT_SECRET)%'
 *     sandbox: true                              # apis-tem.usps.com
 *     crid: '%env(USPS_CRID)%'                   # for labels: the payer's Customer Registration ID,
 *     mid: '%env(USPS_MID)%'                     #   Mailer ID and
 *     account: '%env(USPS_EPS_ACCOUNT)%'         #   Enterprise Payment System account
 *     rates: [...]                               # optional: configured prices instead of the Prices API
 */
final class UspsGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'usps',
            'omnibus.factory_title' => 'USPS',
            'omnibus.required_options' => ['client_id', 'client_secret'],
            'sandbox' => false,
            'crid' => null,
            'mid' => null,
            'account' => null,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['client_id'], (string) $c['client_secret'], (bool) $c['sandbox'], $c['crid'] ?: null, $c['mid'] ?: null, $c['account'] ?: null);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => static fn (Config $c) => $c['crid'] && $c['mid'] && $c['account'] ? new ShippingAction() : null, // a label needs its payer
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.pickup' => new PickupAction(),
        ]);
    }
}
