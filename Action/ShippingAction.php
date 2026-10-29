<?php

namespace Omnibus\Usps\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;
use Omnibus\Usps\Api;
use Omnibus\Usps\Mapping;

/** The Labels API: a domestic label (international labels need customs forms: not offered here), paid through the payment authorization. */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** Labels v3's imageType values and their media types. */
    private const FORMATS = ['PDF' => Label::PDF, 'TIFF' => 'image/tiff', 'JPG' => 'image/jpeg', 'PNG' => 'image/png', 'GIF' => 'image/gif', 'SVG' => 'image/svg+xml', 'ZPL203DPI' => Label::ZPL, 'ZPL300DPI' => Label::ZPL];

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        if ('US' !== strtoupper($s->recipient->country)) {
            throw new CarrierException('usps', 'Only domestic labels are offered: an international one needs a customs form.');
        }
        $parcel = $s->parcels[0];
        $imageType = strtoupper((string) $s->option('label_format', 'PDF'));
        $imageType = 'ZPL' === $imageType ? 'ZPL203DPI' : $imageType; // USPS names its ZPL by resolution
        $data = $this->api->call('POST', '/labels/v3/label', [
            'imageInfo' => ['imageType' => $imageType, 'labelType' => '4X6LABEL'],
            'toAddress' => Mapping::address($s->recipient, ['state' => $s->option('recipient_state')]),
            'fromAddress' => Mapping::address($s->sender, ['state' => $s->option('sender_state')]),
            'packageDescription' => array_filter([
                'mailClass' => $s->service ?? 'USPS_GROUND_ADVANTAGE',
                'rateIndicator' => 'SP',
                'weightUOM' => 'lb',
                'weight' => Mapping::pounds($s->weight()),
                'dimensionsUOM' => 'in',
                'length' => Mapping::inches($parcel->length), 'width' => Mapping::inches($parcel->width), 'height' => Mapping::inches($parcel->height),
                'processingCategory' => 'MACHINABLE',
                'destinationEntryFacilityType' => 'NONE',
                'mailingDate' => ($s->shippingDate ?? new \DateTimeImmutable())->format('Y-m-d'),
                'customerReference' => $s->reference ? [['referenceNumber' => mb_substr($s->reference, 0, 30)]] : null,
            ], static fn ($v) => null !== $v),
        ], [], ['X-Payment-Authorization-Token' => $this->api->paymentToken(), 'Accept' => 'application/vnd.usps.labels+json']);
        $number = (string) ($data['labelMetadata']['trackingNumber'] ?? '');
        if ('' === $number) {
            throw new CarrierException('usps', 'USPS made no label.');
        }
        $image = $data['labelImage'] ?? null;
        $request->setResult(new Label('usps', $number, \is_string($image) ? base64_decode($image) : null, self::FORMATS[$imageType] ?? 'application/octet-stream', null, 'https://tools.usps.com/go/TrackConfirmAction?tLabels='.rawurlencode($number)));
    }
}
