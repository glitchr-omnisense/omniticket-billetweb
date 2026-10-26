# omniticket/billetweb

Billetweb for [omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket): an
organizer's events, their rates and places left, their sessions, their attendees, and a ticket
code checked - through Billetweb's API v1 (`https://www.billetweb.fr/bo/api.php`, its OpenAPI
description at `https://www.billetweb.fr/api/openapi.json`), the key in an HTTP Basic header.
Reading only.

| Operation | Billetweb API v1 |
|---|---|
| `events()` | `GET /api/events` (`past=1` with a query's `past`) |
| `event($id)` | `GET /api/events?id=&past=1`, `GET /api/event/{id}/avail?past=1`, `GET /api/event/{id}/tickets` |
| `attendees($id, $since)` | `GET /api/event/{id}/attendees?disabled=1` (`last_update` with `$since`) |
| `check($id, $code)` | `GET /api/event/{id}/attendees?barcode=&disabled=1` |

The `check` POST endpoint, which marks a ticket as scanned, is not used. No PHP client of
Billetweb's API was found to reuse (Billetweb publishes the OpenAPI description only).

## Installation

```sh
composer require omniticket/billetweb symfony/http-client
```

Requires PHP 8.2, `omnisense/omniticket` (1.x) and `symfony/http-client-contracts` (^3.0): the
application gives the HTTP client to `BilletwebFactory`'s constructor.

## Credentials

The **API user** and **key** of Billetweb's back office ("API"). A key is read-only or read and
write, possibly restricted to some events: read-only is all this package needs.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `user` | string | - | yes | the API user |
| `key` | string | - | yes | the API key |
| `timezone` | string | `Europe/Paris` | no | the zone Billetweb's dates are in: it gives local times without one |
| `currency` | string | `EUR` | no | the currency of its prices: its answers do not name it |
| `url` | string | `https://www.billetweb.fr` | no | |

## Capabilities

| Contract | Supported |
|---|---|
| `EventCatalogInterface` | yes |
| `AttendeeListInterface` | yes, incremental (`last_update`) |
| `TicketCheckInterface` | yes, a lookup by barcode |

`capabilities()`: catalog, attendees, check, incremental and widget true; terms
`https://www.billetweb.fr/bo/api.php`; limits: attendee functions 10 calls a minute; fair use,
monitored: keep the data on your side and read attendees incrementally; abuse may suspend the
key.

## Behaviour

**Events.** Each line: `id`, `name`, `start` and `end` in `timezone`, `place` as the venue,
`shop` as both the purchase URL and the widget URL (the module Billetweb's own embedding code
frames). A listed event has no availability: its status is `Ended` when its end (else start) is
past, else `Unknown`.

**One event.** Three calls. The availability lines by `type`: `event` (the whole event, and its
`status`), `ticket` (per rate), `session` (each session: id, start, end, name, location). Places:
`avail` (-1 is no limit), `quota` (a negative one ignored), `total_sales` (else `sales`). The
rates: `id`, `name`, `price` in units of `currency`, the ticket's availability, `start_time` and
`end_time` as the sale period, `category`. Status: ended by date, else sold out when nothing
remains, else on sale when Billetweb says `available`, else unknown.

**Attendees.** One call, cancelled and refunded tickets included (`disabled=1`). Each: `id`,
`barcode` as the code, `firstname`, `name`, `email`, `ticket` as the rate, `order_id`,
`order_session`, `product_download` as the ticket URL. Status: disabled - cancelled when its order
was paid, else refunded; `used` 1 (scanned) or 2 (gone out) - checked in, `used_date` as when;
`used` 3 (expired) - cancelled; order not paid - pending; else valid.

**Check.** One lookup by barcode, the first attendee holding the exact code decides. Nothing
throttles the calls: an application checking more than 10 tickets a minute keeps the attendee
list itself (`attendees()` then `Check::among()`).

## Example

The [core's installation page](https://github.com/glitchr-omnisense/omniticket/blob/1.x/docs/installation.md)
runs this provider on recorded answers. With the account's key:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\Billetweb\BilletwebFactory;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new BilletwebFactory(HttpClient::create(['timeout' => 15]))], [
    'box_office' => ['factory' => 'billetweb', 'options' => ['user' => getenv('BILLETWEB_USER'), 'key' => getenv('BILLETWEB_KEY')]],
]);

$event = $registry->catalog('box_office')->event('1235');
foreach ($event->sessions as $session) {
    echo $session->start?->format('d/m H:i'), ' ', $session->availability->remaining ?? '?', " left\n";
}

$since = new \DateTimeImmutable('-1 hour');
foreach ($registry->attendees('box_office')->attendees('1235', $since) as $attendee) {
    echo $attendee->name(), ' ', $attendee->status->value, "\n";
}
```

Not run live (no account).

## Errors

- 401, 403: `InvalidKeyException` (`Billetweb refused the credentials (401) for the events:
  invalid key`); 404: `NotFoundException`; another status of 400 or more, or an answer that is
  not JSON: `ProviderException`, the status as its code.
- An event Billetweb does not list: `NotFoundException` (`Billetweb has no event "<id>".`).
- A network failure: `UnreachableException`.

## Verified, and not

`Tests/BilletwebProviderTest.php`, against the examples of Billetweb's OpenAPI description as
published (read on 2026-10-24): events, one event whole (rates, sessions, places left, "no
limit"), attendees with `last_update`, checks valid, already used, refunded and unknown, a refused
key; `BareTest`. Green in the family's harness on 2026-10-26. Not run live: no Billetweb account
was used.

## Responsibility

This package is an independent, unofficial client, affiliated with Billetweb in no way. Whoever
runs it keeps Billetweb's API rules ([billetweb.fr/bo/api.php](https://www.billetweb.fr/bo/api.php):
10 attendee calls a minute, fair use); see the README.
