<?php

namespace Omniticket\Billetweb;

use Omniticket\Config;
use Omniticket\Exception\InvalidConfigException;
use Omniticket\ProviderFactory;
use Omniticket\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Billetweb, from its options:
 *
 *   user      the API user (Billetweb's back office, "API" - a key is read-only or read and write,
 *             possibly restricted to some events: read-only is all this package needs)
 *   key       the API key
 *   timezone  the zone Billetweb's dates are in (Europe/Paris): it gives local times without one
 *   currency  the currency of its prices (EUR): its answers do not name it
 *   url       https://www.billetweb.fr
 */
final class BilletwebFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniticket.factory_name' => 'billetweb',
            'omniticket.factory_title' => 'Billetweb',
            'omniticket.required_options' => ['user', 'key'],
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'url' => 'https://www.billetweb.fr',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new BilletwebProvider(
            $this->http ?? throw new InvalidConfigException('The "billetweb" provider needs an HTTP client: give one to BilletwebFactory.'),
            (string) $c->string('user'),
            (string) $c->string('key'),
            new \DateTimeZone($c->string('timezone') ?? 'Europe/Paris'),
            $c->string('currency') ?? 'EUR',
            rtrim($c->string('url') ?? 'https://www.billetweb.fr', '/'),
        );
    }
}
