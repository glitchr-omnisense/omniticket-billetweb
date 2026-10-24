<?php

namespace Omniticket\Billetweb\Tests;

use Omniticket\Billetweb\BilletwebProvider;
use Omniticket\Testing\LoadCheck;
use PHPUnit\Framework\TestCase;

/**
 * omniticket/billetweb with no framework: every class of the package loaded in a
 * PHP process of its own, with nothing from outside but what it requires -
 * omniscient/omniticket and Symfony's HTTP client contracts (interfaces
 * only): no framework, no HTTP client, no application, even in the
 * harness where they are installed beside it.
 */
final class BareTest extends TestCase
{
    private const ALLOWED = ['/vendor/omniscient/omniticket/', '/omniticket/core/', '/vendor/symfony/http-client-contracts/', '/vendor/symfony/service-contracts/'];

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $autoload = LoadCheck::autoload(BilletwebProvider::class, self::class, \dirname(__DIR__)) ?? self::markTestSkipped('omniticket/billetweb is installed in an application here, or no autoloader knows its tests.');
        $report = LoadCheck::run(\dirname(__DIR__), 'Omniticket\\Billetweb\\', $autoload);

        self::assertContains(BilletwebProvider::class, $report['loaded']);
        self::assertSame([], $report['foreign'], 'no class of a framework, an HTTP client or an application');
        self::assertSame([], LoadCheck::outside($report['files'], \dirname(__DIR__), self::ALLOWED), 'no file but the package and what it requires');
    }
}
