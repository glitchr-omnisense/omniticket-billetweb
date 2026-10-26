# omniticket/billetweb

[omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket)'s Billetweb, under any PHP
host: an organizer's events with their rates, sessions and places left, the attendees of an event
(incrementally, as Billetweb asks), and a ticket's barcode checked at the door. Reading only.

```php
use Omniticket\Billetweb\BilletwebFactory;
use Omniticket\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new BilletwebFactory(HttpClient::create())], [
    'box_office' => ['factory' => 'billetweb', 'options' => ['user' => '...', 'key' => '...']],
]);
$registry->checker('box_office')->check('1235', '4475320')->result; // CheckResult::Valid...
```

Options: `user`, `key` (Billetweb's back office, "API": a read-only key, restricted to the events
the site shows, is enough), `timezone` (Europe/Paris: Billetweb's dates carry none), `currency`
(EUR: its prices name none), `url`.

## The API, as read on 2026-10-24

Billetweb's API v1 (<https://www.billetweb.fr/bo/api.php>, OpenAPI description at
<https://www.billetweb.fr/api/openapi.json>), the key in an HTTP Basic header:
`GET /api/events` (and `?id=` for one), `/api/event/{id}/avail` (places left by event, category,
rate and session: -1 is no limit), `/api/event/{id}/tickets` (rates and prices),
`/api/event/{id}/attendees` (`last_update` for what changed since, `barcode` for one ticket,
`disabled=1` to include refunded and cancelled ones). `used`: 0 not scanned, 1 scanned, 2 gone
out, 3 expired. An event's `shop` URL is its purchase page and the module Billetweb's own
embedding code frames.

**Terms**: the attendee functions are limited to **10 calls a minute**; the API is under fair use
and monitored: store the data on your side and read attendees incrementally; abuse, intended or
not, can suspend the key. What this package does: `event()` makes three calls (event, avail,
tickets), `attendees()` one, `check()` one (a lookup by barcode, not a scan of the whole list);
`updatedSince` maps to `last_update`; nothing throttles the calls - an application checking at
the door at more than 10 tickets a minute keeps the attendee list itself (`attendees()` then
`Check::among()`). The POST `check` endpoint, which marks a ticket as scanned, is not used.

No PHP client of Billetweb's API was found to reuse (Billetweb publishes the OpenAPI description
only).

## Verified, and not

- Verified (the core's harness, PHP 8.4): events, one event whole (rates, sessions, places left,
  "no limit"), attendees with `last_update`, checks valid, already used, refunded and unknown, a
  refused key - against the examples of Billetweb's OpenAPI description, as published; every class
  loading with the package's requirements alone (`BareTest`).
- Not verified live: no Billetweb account was used.

## Licence

MIT. An unofficial client, affiliated with Billetweb in no way.
