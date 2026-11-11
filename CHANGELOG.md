# Changelog

All notable changes to `phil/standard-webhooks-bundle` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## NEXT - YYYY-MM-DD

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
