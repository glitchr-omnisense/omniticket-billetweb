<?php

namespace Omniticket\Billetweb;

use Omniticket\AttendeeListInterface;
use Omniticket\Capabilities;
use Omniticket\EventCatalogInterface;
use Omniticket\Exception\NotFoundException;
use Omniticket\Http\JsonApi;
use Omniticket\Model\Attendee;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\Availability;
use Omniticket\Model\Check;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Model\Money;
use Omniticket\Model\Rate;
use Omniticket\Model\Session;
use Omniticket\Support\Data;
use Omniticket\TicketCheckInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Billetweb's API v1 (https://www.billetweb.fr/bo/api.php, its OpenAPI
 * description at /api/openapi.json): GET /api/events, /api/event/{id}/avail,
 * /api/event/{id}/tickets and /api/event/{id}/attendees, with the key in an
 * HTTP Basic header. Reading only: nothing is written (no "check" POST,
 * which would mark a ticket as scanned).
 */
final class BilletwebProvider implements EventCatalogInterface, AttendeeListInterface, TicketCheckInterface
{
    private readonly JsonApi $api;

    public function __construct(
        HttpClientInterface $http,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $key,
        private readonly \DateTimeZone $timezone = new \DateTimeZone('Europe/Paris'),
        private readonly string $currency = 'EUR',
        private readonly string $url = 'https://www.billetweb.fr',
    ) {
        $this->api = new JsonApi($http, 'Billetweb');
    }

    public function getName(): string
    {
        return 'billetweb';
    }

    public function getTitle(): string
    {
        return 'Billetweb';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            catalog: true,
            attendees: true,
            check: true,
            incremental: true,
            widget: true,
            terms: ['https://www.billetweb.fr/bo/api.php'],
            limits: ['attendee functions: 10 calls a minute', 'fair use, monitored: keep the data on your side and read attendees incrementally (updatedSince); abuse may suspend the key'],
        );
    }

    public function events(EventQuery $query = new EventQuery()): iterable
    {
        return $query->filter(array_map($this->toEvent(...), $this->get('/api/events', $query->past ? ['past' => 1] : [], 'the events')->items()));
    }

    public function event(string $id): Event
    {
        $found = $this->get('/api/events', ['id' => $id, 'past' => 1], 'event "'.$id.'"')->items();
        if ([] === $found) {
            throw NotFoundException::of('Billetweb', 'event "'.$id.'"');
        }
        $avail = $this->get('/api/event/'.rawurlencode($id).'/avail', ['past' => 1], 'the availability of event "'.$id.'"')->items();
        $byTicket = [];
        $sessions = [];
        $whole = new Data();
        foreach ($avail as $line) {
            match ($line->string('type')) {
                'ticket' => $byTicket[(string) $line->string('id')] = $line,
                'session' => $sessions[] = new Session((string) $line->string('id'), $line->date('start', $this->timezone), $line->date('end', $this->timezone), $line->string('name'), $line->string('location'), self::availability($line)),
                'event' => $whole = $line,
                default => null,
            };
        }
        $rates = [];
        foreach ($this->get('/api/event/'.rawurlencode($id).'/tickets', [], 'the rates of event "'.$id.'"')->items() as $ticket) {
            $ticketId = (string) $ticket->string('id');
            $rates[] = new Rate(
                $ticketId,
                $ticket->string('name') ?? $ticketId,
                null !== ($price = $ticket->string('price')) ? Money::fromMajor($price, $this->currency) : null,
                isset($byTicket[$ticketId]) ? self::availability($byTicket[$ticketId]) : new Availability(),
                $ticket->date('start_time', $this->timezone),
                $ticket->date('end_time', $this->timezone),
                category: $ticket->string('category'),
            );
        }

        return $this->toEvent($found[0], self::availability($whole), $rates, $sessions, $whole->string('status'));
    }

    public function attendees(string $eventId, ?\DateTimeImmutable $updatedSince = null): iterable
    {
        $query = ['disabled' => 1] + (null !== $updatedSince ? ['last_update' => $updatedSince->getTimestamp()] : []);

        return array_map(fn (Data $line) => $this->attendee($line, $eventId), $this->get('/api/event/'.rawurlencode($eventId).'/attendees', $query, 'the attendees of event "'.$eventId.'"')->items());
    }

    public function check(string $eventId, string $code): Check
    {
        $found = $this->get('/api/event/'.rawurlencode($eventId).'/attendees', ['barcode' => $code, 'disabled' => 1], 'a ticket of event "'.$eventId.'"')->items();

        return Check::among(array_map(fn (Data $line) => $this->attendee($line, $eventId), $found), $code);
    }

    /** @param array<string, string|int> $query */
    private function get(string $path, array $query, string $what): Data
    {
        return $this->api->request('GET', $this->url.$path, ['query' => $query, 'auth_basic' => [$this->user, $this->key], 'headers' => ['Accept' => 'application/json']], $what);
    }

    /**
     * @param list<Rate>    $rates
     * @param list<Session> $sessions
     */
    private function toEvent(Data $line, Availability $availability = new Availability(), array $rates = [], array $sessions = [], ?string $status = null): Event
    {
        $start = $line->date('start', $this->timezone);
        $end = $line->date('end', $this->timezone);
        $shop = $line->string('shop');

        return new Event(
            (string) $line->string('id'),
            $line->string('name') ?? '',
            $start,
            $end,
            self::status($status, $availability, $end ?? $start),
            $shop,
            $line->string('place'),
            $availability,
            $rates,
            $sessions,
            widgetUrl: $shop,
            provider: 'billetweb',
        );
    }

    private function attendee(Data $line, string $eventId): Attendee
    {
        $used = $line->string('used');

        return new Attendee(
            (string) $line->string('id'),
            $eventId,
            $line->string('barcode'),
            $line->string('firstname'),
            $line->string('name'),
            $line->string('email'),
            $line->string('ticket'),
            match (true) {
                '1' === $line->string('disabled') => '1' === $line->string('order_paid') ? AttendeeStatus::Cancelled : AttendeeStatus::Refunded,
                \in_array($used, ['1', '2'], true) => AttendeeStatus::CheckedIn,
                '3' === $used => AttendeeStatus::Cancelled,
                '0' === $line->string('order_paid') => AttendeeStatus::Pending,
                default => AttendeeStatus::Valid,
            },
            \in_array($used, ['1', '2'], true) ? $line->date('used_date', $this->timezone) : null,
            $line->string('order_id'),
            $line->string('order_session'),
            $line->string('product_download'),
        );
    }

    /** Billetweb's "avail" and "quota": -1 is no limit. */
    private static function availability(Data $line): Availability
    {
        $remaining = $line->int('avail');
        $sold = $line->int('total_sales') ?? $line->int('sales');
        if (-1 === $remaining) {
            return Availability::unlimited($sold);
        }
        $quota = $line->int('quota');

        return null === $remaining && null === $sold ? new Availability() : Availability::of($remaining, null !== $quota && $quota >= 0 ? $quota : null, $sold);
    }

    private static function status(?string $status, Availability $availability, ?\DateTimeImmutable $last): EventStatus
    {
        return match (true) {
            null !== $last && $last < new \DateTimeImmutable() => EventStatus::Ended,
            $availability->soldOut => EventStatus::SoldOut,
            'available' === $status => EventStatus::OnSale,
            default => EventStatus::Unknown,
        };
    }
}
