<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Http;

use LogicException;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookTimestamp;
use Phil\StandardWebhooksBundle\Application\SigningKeys;
use Phil\Url\Exception\InvalidUrl;
use Phil\Url\Url;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One attempt of a delivery: the body signed with the configured keys at the current time, sent as a JSON `POST`
 * that follows no redirect. A new delivery gets a new id; **a retry passes the id of the first attempt**, so that
 * the receiver's deduplication absorbs it. The retry schedule is the application's.
 *
 * A receiver's URL is usually given by a third party, so the sender guards against server-side request forgery:
 * only an `https` URL is sent to, unless `$allowPlainHttp`; the URL is read by phil/url, as a browser reads it, and
 * sent in its canonical form, so that one with a user name or a password, or whose host is an IP address — written
 * dotted, as a single number, or in IPv6 — or a single name, `https://metadata/`, is refused before any request,
 * whatever the configuration — `localhost` and the names under `.localhost` too, unless `$allowLocalhost`, for a
 * receiver of the development machine, which then also needs `$allowPrivateNetworks`; and, unless `$allowPrivateNetworks`, the client is wrapped in
 * `NoPrivateNetworkHttpClient`, which refuses a host that resolves to a loopback, private, link-local or reserved
 * address — a cloud metadata endpoint, a service of the internal network.
 */
final readonly class WebhookSender
{
    private ?HttpClientInterface $client;

    public function __construct(
        ?HttpClientInterface $client,
        private Signer $signer,
        private SigningKeys $keys,
        private ClockInterface $clock,
        private float $timeout = 10.0,
        bool $allowPrivateNetworks = false,
        private bool $allowPlainHttp = false,
        private bool $allowLocalhost = false,
    ) {
        $this->client = !$client instanceof HttpClientInterface || $allowPrivateNetworks ? $client : $this->guarded($client);
    }

    /**
     * @param array<string, string> $headers more headers
     *
     * @return WebhookId the id of the delivery, to retry with
     *
     * @throws DeliveryFailed when the receiver does not answer `2xx`
     */
    public function send(string $url, string $body, ?WebhookId $id = null, array $headers = []): WebhookId
    {
        if (!$this->client instanceof HttpClientInterface) {
            throw new LogicException('Sending webhooks needs an HTTP client: enable framework.http_client.');
        }

        $id ??= WebhookId::generate();
        $address = $this->address($url);

        if (!$address instanceof Url || ('https' !== $address->scheme && !$this->allowPlainHttp)) {
            throw DeliveryFailed::refused($id, $this->allowPlainHttp ? "the receiver's URL is not an http or https URL to a host name" : "the receiver's URL is not an https URL to a host name");
        }

        [$key, $others] = $this->keys->split();
        $signed = $this->signer->sign($id, WebhookTimestamp::fromDateTime($this->clock->now()), $body, $key, ...$others);

        try {
            $status = $this->client->request('POST', $address->toString(), [
                'headers' => ['Content-Type' => 'application/json', ...$headers, ...$signed->toArray()],
                'body' => $body,
                'max_redirects' => 0,
                'timeout' => $this->timeout,
            ])->getStatusCode();
        } catch (TransportExceptionInterface $transportException) {
            throw DeliveryFailed::unanswered($id, $transportException);
        }

        if ($status < 200 || $status > 299) {
            throw DeliveryFailed::answered($id, $status);
        }

        return $id;
    }

    /**
     * The receiver's address, read by phil/url as a browser reads it; none when it is no http or https address to a
     * host name.
     */
    private function address(string $url): ?Url
    {
        try {
            return Url::fromString($url, allowLocalhost: $this->allowLocalhost);
        } catch (InvalidUrl) {
            return null;
        }
    }

    private function guarded(HttpClientInterface $client): HttpClientInterface
    {
        if (!class_exists(NoPrivateNetworkHttpClient::class)) {
            throw new LogicException('Guarding webhook deliveries against private networks needs symfony/http-client; install it, or set phil_standard_webhooks.allow_private_networks.');
        }

        return new NoPrivateNetworkHttpClient($client);
    }
}
