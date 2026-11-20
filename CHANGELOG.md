# Changelog

All notable changes to `phil/standard-webhooks-bundle` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## 0.2.0-alpha - 2026-11-20

The processed ids are keyed by the receiver and the id, a change of the table to
migrate, and a body larger than 1 MiB is answered `413` before it is read whole. See
[Upgrading from 0.1](doc/storage.md#upgrading-from-01).

### Added

- `receiver`, `default` by default: the name of the configured receiver, which keys its processed ids apart from those
  of the other receivers, one per sender, that share the table; 1 to 64 letters, digits, `_`, `.` or `-`.
- `DbalProcessedWebhooks::forReceiver()` and `$receiver`, its second argument: the ids of another receiver, on the same
  connection; a name that is not one is refused with an `InvalidArgumentException`.
- `max_body_bytes`, 1 MiB by default, and `VerifiedWebhookResolver::MAX_BODY_BYTES`, its third argument: the largest
  body read before a delivery is verified.
- `ProcessedWebhookTable::RECEIVER_MAX_LENGTH`, `RECEIVER_PATTERN` and `DEFAULT_RECEIVER`.

### Changed

- The table `phil_standard_webhooks_processed` has a column `receiver`, `VARCHAR(64)`, `'default'` by default, in
  `ascii_bin` on MySQL and MariaDB, and its primary key is `(receiver, id)`. The migration adds the column, which the
  rows already there take as `default`, and replaces the primary key; an application that configures another
  `receiver` moves those rows to it. `doctrine:migrations:diff` writes it from the schema listener; the SQL for
  PostgreSQL, MySQL and MariaDB is in [Upgrading from 0.1](doc/storage.md#upgrading-from-01).
- `claim()` inserts the receiver beside the id: `ON CONFLICT (receiver, id) DO NOTHING` on PostgreSQL. `purge()`
  forgets the ids of every receiver.

### Security

- The processed ids shared one key for every sender: a sender that guessed the id of another sender's event, and sent
  it first, had the genuine event acknowledged and never processed. Each receiver claims its ids apart.
- The body of a delivery was read whole and hashed before any check, whatever its size: a `Content-Length` above
  `max_body_bytes` is answered `413` before a byte is read, and a body is read up to one byte past the limit, then
  answered `413`.

## 0.1.1-alpha - 2026-11-11

### Security

- `WebhookSender` reads the receiver's URL by `phil/url`, as a browser reads it, no longer by `parse_url()`, which
  read its scheme only, and sends it in its canonical form. A URL with a user name or a password, or whose host is an
  IP address — dotted, a single number such as `2130706433`, IPv6 — or a single name such as `metadata`, or with
  port 0, is refused before any request, whatever the configuration, as a `DeliveryFailed` that is not retryable.

### Added

- The option `allow_localhost`, `false` by default, and `WebhookSender`'s `$allowLocalhost`: `true`, in development
  and tests, lets a receiver be on `localhost` or under `.localhost`; it also needs `allow_private_networks`, since
  those names resolve to the loopback address. An IP address stays refused.

### Changed

- `allow_private_networks: true` lets the sender reach a host name that resolves to a private address; an IP address
  written in the URL is refused all the same. The refusal reads `the receiver's URL is not an https URL to a host
  name`, or `… an http or https URL to a host name` with `allow_plain_http`.
- The package requires `phil/url` 0.1.3.

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
