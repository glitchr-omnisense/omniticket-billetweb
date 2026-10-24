<?php

namespace Omniticket\Billetweb\Tests;

use Omniticket\Billetweb\BilletwebFactory;
use Omniticket\Billetweb\BilletwebProvider;
use Omniticket\Exception\InvalidConfigException;
use Omniticket\Exception\InvalidKeyException;
use Omniticket\Exception\NotFoundException;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\CheckResult;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Registry;
use Omniticket\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Billetweb's answers are the examples of its OpenAPI description
 * (https://www.billetweb.fr/api/openapi.json, read on 2026-10-24), as
 * published - the rate of /tickets given the id /avail uses (124), so the
 * two describe one event. No account was used.
 */
final class BilletwebProviderTest extends TestCase
{
    public function testEvents(): void
    {
        $http = self::http();
        $events = [...self::provider($http)->events(new EventQuery(past: true))];

        self::assertCount(1, $events);
        self::assertSame(['1235', 'Retro future shock', '2015-12-31T20:00:00+01:00', '2016-01-01T00:00:00+01:00', 'Paris', 'https://www.billetweb.fr/shop.php?event=soiree', 'ended'], [$events[0]->id, $events[0]->title, $events[0]->start?->format(\DATE_ATOM), $events[0]->end?->format(\DATE_ATOM), $events[0]->venue, $events[0]->url, $events[0]->status->value]);
        self::assertSame(['past' => 1], $http->requests[0]['options']['query']);
        self::assertSame(['user-example', 'key-example-not-a-real-key'], $http->requests[0]['options']['auth_basic']);
        self::assertSame([], [...self::provider(self::http())->events()], 'upcoming only by default: the example is of 2015');
    }

    public function testAnEventWhole(): void
    {
        $http = self::http();
        $event = self::provider($http)->event('1235');

        self::assertSame(['/api/events', '/api/event/1235/avail', '/api/event/1235/tickets'], array_map(static fn (array $r) => (string) parse_url($r['url'], \PHP_URL_PATH), $http->requests));
        self::assertTrue($event->availability->unlimited, 'avail -1: no limit');
        self::assertSame(120, $event->availability->sold);
        self::assertCount(1, $event->rates);
        self::assertSame(['124', 'Plein tarif', '15.00', 'EUR', 90, 100, 'Catégorie'], [$event->rates[0]->id, $event->rates[0]->label, $event->rates[0]->price?->major(), $event->rates[0]->price?->currency, $event->rates[0]->availability->remaining, $event->rates[0]->availability->capacity, $event->rates[0]->category]);
        self::assertCount(1, $event->sessions);
        self::assertSame(['398792', 'Séance du soir', 'Salle A', 50, '2016-01-01T20:00:00+01:00'], [$event->sessions[0]->id, $event->sessions[0]->name, $event->sessions[0]->venue, $event->sessions[0]->availability->remaining, $event->sessions[0]->start?->format(\DATE_ATOM)]);
        self::assertSame('15.00', $event->lowestPrice()?->major());
    }

    public function testAnUnknownEvent(): void
    {
        $this->expectException(NotFoundException::class);
        self::provider(RecordedHttpClient::routes(['GET /api/events' => '[]']))->event('9');
    }

    public function testAttendeesIncrementally(): void
    {
        $http = self::http();
        $attendees = [...self::provider($http)->attendees('1235', new \DateTimeImmutable('@1767225600'))];

        self::assertSame(['disabled' => 1, 'last_update' => 1767225600], $http->requests[0]['options']['query']);
        self::assertCount(1, $attendees);
        $a = $attendees[0];
        self::assertSame(['656087', '1235', '4475320', 'Prénom Nom', 'jean@dupont.fr', 'Plein tarif', AttendeeStatus::CheckedIn, '2016-06-15T22:39:24+02:00', '264751', '398792'], [$a->id, $a->eventId, $a->code, $a->name(), $a->email, $a->rate, $a->status, $a->checkedInAt?->format(\DATE_ATOM), $a->orderId, $a->sessionId]);
    }

    public function testCheckByBarcode(): void
    {
        $http = self::http();
        $check = self::provider($http)->check('1235', '4475320');

        self::assertSame(CheckResult::AlreadyUsed, $check->result, 'the example is scanned (used 1)');
        self::assertSame(['barcode' => '4475320', 'disabled' => 1], $http->requests[0]['options']['query']);
        self::assertSame('GET', $http->requests[0]['method'], 'reading only');

        $valid = str_replace(['"used": "1"', '"disabled": "0"'], ['"used": "0"', '"disabled": "0"'], self::fixture('attendees'));
        self::assertSame(CheckResult::Valid, self::provider(RecordedHttpClient::routes(['GET /attendees' => $valid]))->check('1235', '4475320')->result);
        $refunded = str_replace(['"disabled": "0"', '"order_paid": "1"'], ['"disabled": "1"', '"order_paid": "0"'], self::fixture('attendees'));
        self::assertSame([CheckResult::Void, AttendeeStatus::Refunded], [($c = self::provider(RecordedHttpClient::routes(['GET /attendees' => $refunded]))->check('1235', '4475320'))->result, $c->attendee?->status]);
        self::assertSame(CheckResult::Unknown, self::provider(RecordedHttpClient::routes(['GET /attendees' => '[]']))->check('1235', '000')->result);
    }

    public function testARefusedKey(): void
    {
        $this->expectException(InvalidKeyException::class);
        [...self::provider(RecordedHttpClient::routes(['GET /api/events' => [401, '{"error":"Invalid key"}']]))->events()];
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new BilletwebFactory(self::http())], ['box_office' => ['factory' => 'billetweb', 'options' => ['user' => 'user-example', 'key' => 'key-example-not-a-real-key']]]);

        self::assertInstanceOf(BilletwebProvider::class, $registry->catalog('box_office'));
        self::assertSame(['catalog', 'attendees', 'check'], $registry->get('box_office')->capabilities()->contracts());
        self::assertSame(['1235'], array_map(static fn (Event $e) => $e->id, [...$registry->catalog('box_office')->events(new EventQuery(past: true))]));
        $this->expectException(InvalidConfigException::class);
        (new Registry([new BilletwebFactory(self::http())], ['b' => ['factory' => 'billetweb', 'options' => ['user' => 'x']]]))->get('b');
    }

    public function testStatusOfAFutureEvent(): void
    {
        $future = str_replace(['2015-12-31 20:00:00', '2016-01-01 00:00:00'], ['2099-12-31 20:00:00', '2100-01-01 00:00:00'], self::fixture('events'));
        $event = self::provider(RecordedHttpClient::routes(['GET /api/events' => $future, 'GET /avail' => self::fixture('avail'), 'GET /tickets' => self::fixture('tickets')]))->event('1235');

        self::assertSame(EventStatus::OnSale, $event->status, '"available"');
        self::assertTrue($event->onSale());
    }

    private static function provider(RecordedHttpClient $http): BilletwebProvider
    {
        return new BilletwebProvider($http, 'user-example', 'key-example-not-a-real-key');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'GET /api/events' => self::fixture('events'),
            'GET /avail' => self::fixture('avail'),
            'GET /tickets' => self::fixture('tickets'),
            'GET /attendees' => self::fixture('attendees'),
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
