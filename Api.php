<?php

namespace Omnibus\Usps;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The USPS APIs v3 (apis.usps.com) on an OAuth2 client-credentials token.
 * Labels are paid for through a payment authorization token, asked once per
 * session from the Payments API with the CRID, MID and EPS account.
 */
final class Api
{
    public const LIVE = 'https://apis.usps.com';
    public const TEST = 'https://apis-tem.usps.com';

    private ?string $token = null;
    private int $expiresAt = 0;
    private ?string $paymentToken = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        public readonly bool $sandbox = false,
        public readonly ?string $crid = null,
        public readonly ?string $mid = null,
        public readonly ?string $account = null,
        private readonly int $timeout = 20,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::TEST : self::LIVE;
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->http->request($method, $this->base().$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->token(), 'Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
                'query' => $query,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('usps', 'USPS request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('usps', sprintf('USPS answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400) {
            $error = $data['error'] ?? [];
            throw Redaction::withoutArguments(new CarrierException('usps', (string) ($error['message'] ?? $error['errors'][0]['detail'] ?? $data['message'] ?? sprintf('HTTP %d', $status)), isset($error['code']) ? (string) $error['code'] : null));
        }

        return $data;
    }

    /** The X-Payment-Authorization-Token a label needs: who pays (option crid, mid, account). */
    public function paymentToken(): string
    {
        if (null !== $this->paymentToken) {
            return $this->paymentToken;
        }
        if (!$this->crid || !$this->mid || !$this->account) {
            throw Redaction::withoutArguments(new CarrierException('usps', 'Labels need the payer: options crid, mid and account (the EPS account).'));
        }
        $data = $this->call('POST', '/payments/v3/payment-authorization', ['roles' => [
            ['roleName' => 'PAYER', 'CRID' => $this->crid, 'MID' => $this->mid, 'accountType' => 'EPS', 'accountNumber' => $this->account],
            ['roleName' => 'LABEL_OWNER', 'CRID' => $this->crid, 'MID' => $this->mid, 'accountType' => 'EPS', 'accountNumber' => $this->account],
        ]]);
        $this->paymentToken = (string) ($data['paymentAuthorizationToken'] ?? throw Redaction::withoutArguments(new CarrierException('usps', 'USPS gave no payment authorization.')));

        return $this->paymentToken;
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expiresAt - 60) {
            return $this->token;
        }
        try {
            $data = $this->http->request('POST', $this->base().'/oauth2/v3/token', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]),
                'timeout' => $this->timeout,
            ])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('usps', 'USPS gave no token: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (empty($data['access_token'])) {
            throw Redaction::withoutArguments(new CarrierException('usps', (string) ($data['error_description'] ?? 'USPS gave no token: check the client id and secret.')));
        }
        $this->token = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 28799);

        return $this->token;
    }

    /**
     * What a failure must not carry: the credentials and the token, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->clientSecret, (string) $this->token, (string) $this->paymentToken, ...$more];
    }
}
