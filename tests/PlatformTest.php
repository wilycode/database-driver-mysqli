<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverMysqli\Tests;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\Driver\PlatformProviderInterface;
use WilyCode\Database\DriverMysqli\MysqliDatabase;

final class PlatformTest extends TestCase
{
    public function testPlatformDoesNotNeedAQueryOrAnOpenConnection(): void
    {
        if (!extension_loaded('mysqli')) { self::markTestSkipped('mysqli required.'); }
        $database = new MysqliDatabase(new \mysqli());
        self::assertInstanceOf(PlatformProviderInterface::class, $database);
        self::assertSame('mysql', $database->getPlatformName());
    }
}
