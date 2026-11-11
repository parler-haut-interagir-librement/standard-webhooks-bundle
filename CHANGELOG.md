# Changelog

All notable changes to `phil/standard-webhooks-bundle` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## 0.1.1-alpha - 2026-11-11

### Security

- `WebhookSender` reads the receiver's URL by `phil/url`, as a browser reads it, no longer by `parse_url()`, which
  read its scheme only, and sends it in its canonical form. A URL with a user name or a password, or whose host is an
  IP address — dotted, a single number such as `2130706433`, IPv6 — or a single name such as `metadata`, or with
  port 0, is refused before any request, whatever the configuration, as a `DeliveryFailed` that is not retryable.

### Changed

- `allow_private_networks: true` lets the sender reach a host name that resolves to a private address; an IP address
  written in the URL is refused all the same. The refusal reads `the receiver's URL is not an https URL to a host
  name`, or `… an http or https URL to a host name` with `allow_plain_http`.
- The package requires `phil/url` 0.1.2.

## 0.1.0-alpha - 2026-11-11

### Added

- `SigningKeys`, whose `fromStrings()` keeps the keys out of a stack trace.
- `VerifiedWebhookResolver`, which takes a `ClockInterface` and the receiver as a closure, built only for a
  `VerifiedWebhook` argument: an unreadable verification key fails the webhook endpoint with a server error, and no
  key is parsed by a request without a webhook.
- `WebhookSender`, which takes a `ClockInterface` and sends to `https` URLs only, through
  `NoPrivateNetworkHttpClient`, which refuses loopback, private, link-local and reserved addresses;
  `allow_plain_http` and `allow_private_networks` lift either guard. `DeliveryFailed` says whether it is
  `retryable`, with `refused()`.
- `DbalProcessedWebhooks`, `ProcessedWebhookTable` and `ProcessedWebhookSchemaListener`; on MySQL and MariaDB the
  id column is `ascii` with the binary collation `ascii_bin`, so that two ids that differ by their case are two ids.
- `PhilStandardWebhooksBundle`, extension alias `phil_standard_webhooks`, keys `signing_keys`,
  `verification_keys`, `tolerance`, `audience`, `connection`, `allow_plain_http` and `allow_private_networks`; it
  wires the container's `clock` service, without falling back to the system's time. The protocol itself — headers,
  keys, signatures, verification, receiving and the ports — is `phil/standard-webhooks`, under
  `Phil\StandardWebhooks`, which the bundle requires.
- `GenerateKeyCommand`.
- The documentation in `doc/`, one file per topic, indexed by the README.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md`.
