# omniticket/billetweb

[omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket)'s Billetweb, under any PHP
host: an organizer's events with their rates, sessions and places left, the attendees of an event
(incrementally, as Billetweb asks), and a ticket's barcode checked at the door. Reading only.

## Install and a first call

```sh
composer require omniticket/billetweb
```

No account for this first call: Billetweb's answer is recorded (`Omniticket\Testing\RecordedHttpClient`
answers in place of the API), so nothing leaves the process. A real call needs a Billetweb account:
the user id and an API key from its back office (a read-only key is enough).

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\Billetweb\BilletwebFactory;
use Omniticket\Testing\RecordedHttpClient;

// Billetweb's answer, recorded: nothing sent. HttpClient::create() (symfony/http-client) for a real call.
$http = RecordedHttpClient::routes([
    'GET /api/events' => '[{"id": "1235", "name": "Retro future shock", "start": "2036-12-31 20:00:00", "end": "2037-01-01 02:00:00", "place": "Paris", "shop": "https://www.billetweb.fr/shop.php?event=soiree", "online": "1"}]',
]);
$billetweb = (new BilletwebFactory($http))->create(['user' => 'recorded', 'key' => 'recorded']);

foreach ($billetweb->events() as $event) {
    echo $event->title, ' ', $event->start?->format('d/m/Y H:i'), ' ', $event->venue, "\n";   // Retro future shock 31/12/2036 20:00 Paris
}
```

With your key, through the family's registry:

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

## Usage policy

Declared as data in `policy.json` (omnisense/contracts' schema), read on 2026-11-10 at
[api.php](https://www.billetweb.fr/bo/api.php): the attendee functions - `attendees()` and
`check()` - 10 calls a minute per key; the rest under fair use, monitored, with no figure. Storage
allowed (Billetweb asks to keep the data on your side and read attendees incrementally); commercial
use, caching, redistribution and attribution unknown: the page does not say. A key is needed.
Through omniticket's registry every call is checked against it, a 429 and its `Retry-After` hold
the next calls, and a site may tighten it, never loosen it.

## Verified, and not

- Verified (the core's harness, PHP 8.4): events, one event whole (rates, sessions, places left,
  "no limit"), attendees with `last_update`, checks valid, already used, refunded and unknown, a
  refused key - against the examples of Billetweb's OpenAPI description, as published; every class
  loading with the package's requirements alone (`BareTest`).
- Not verified live: no Billetweb account was used.

## Licence

MIT. An unofficial client, affiliated with Billetweb in no way.
