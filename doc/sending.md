# Sending

`WebhookSender::send()` makes one attempt of a delivery: the body signed with the configured signing keys at the
current time, sent as a JSON `POST` that follows no redirect and waits ten seconds at most.

```php
use Phil\StandardWebhooksBundle\Infrastructure\Http\{DeliveryFailed, WebhookSender};

$body = json_encode(['type' => 'invoice.paid', 'timestamp' => $now->format(DATE_RFC3339), 'data' => ['id' => 'inv_1'], 'audience' => 'shop'], JSON_THROW_ON_ERROR);

try {
    $id = $sender->send('https://shop.example/webhooks', $body);
} catch (DeliveryFailed $failed) {
    // $failed->id: retry later with it; $failed->status: the answer, null when there was none
}
```

| Argument | What it is |
|---|---|
| `$url` | The receiver's endpoint |
| `$body` | The exact bytes signed and sent |
| `$id` | `null` for a new delivery, which gets `WebhookId::generate()`; **the first attempt's id for a retry**, so that the receiver's deduplication absorbs it |
| `$headers` | More headers; `Content-Type` replaces `application/json`, the three signature headers cannot be replaced |

It returns the delivery's id; it throws `DeliveryFailed` when the receiver answers outside `2xx`, does not answer,
or has a URL the sender refuses. `DeliveryFailed::$retryable` says which: a `4xx` or a refused URL is permanent, the
same delivery fails the same way; a `5xx` or no answer is worth retrying, with the same id, on the application's
schedule — a Messenger retry strategy, an outbox and a scheduler —, within the receiver's deduplication window.

## Receiver URLs given by others

A receiver's URL is usually registered by a third party — a subscriber, a partner shop. Posted to as given, it would
let that party make the application send requests inside its own network: to a cloud metadata endpoint
(`169.254.169.254`), to an internal service, with the status code or the transport error coming back as an answer.
The sender therefore:

- sends to `https` URLs only; another scheme is a `DeliveryFailed` that is not retryable, `the receiver's URL is not
  an https URL to a host name`. `allow_plain_http: true` accepts `http` too;
- reads the URL with phil/url, as a browser reads it, and sends it in its canonical form: a URL with a user name or a
  password, or whose host is an IP address — dotted, a single number such as `2130706433`, IPv6 — or a single name
  such as `metadata`, is refused the same way, before any request, whatever the configuration. Name a receiver of
  the internal network by a host name, `hooks.internal.example`, or under `.localhost`;
- wraps the HTTP client in Symfony's `NoPrivateNetworkHttpClient`, which refuses a host that resolves to a loopback,
  private, link-local or reserved address, before anything is sent: a `DeliveryFailed` without a status, whose
  message says the host `is blocked`. `allow_private_networks: true` removes the guard; it needs
  `symfony/http-client`, which the `http_client` service already brings.

Validate a URL when it is registered, too, and never relay a `DeliveryFailed` message to the party that registered
the URL: it names the status or the transport error, which is what an internal scan would read.

Without the `http_client` service (`framework.http_client`), `send()` throws a `LogicException`; without a signing
key, too.
