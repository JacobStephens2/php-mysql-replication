<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event;

use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\QueryDTO;
use MySQLReplication\Event\Event;
use MySQLReplication\Event\RowEvent\RowEventBuilder;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use MySQLReplication\Repository\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

class EventTest extends TestCase
{
    protected function setUp(): void
    {
        (new ArrayCache())->clear();
    }

    protected function tearDown(): void
    {
        (new ArrayCache())->clear();
    }

    public function testConsumeDispatchesDecodedDtoAndSuppressesFilteredResponsesWithoutNetworkStartup(): void
    {
        $current = new BinLogCurrent();
        $connection = new class([
            EventPacket::response(ConstEventType::QUERY_EVENT->value, EventPacket::query('SELECT 1'), 120, true),
            EventPacket::response(
                ConstEventType::GTID_LOG_EVENT->value,
                "\x01" . hex2bin('00112233445566778899aabbccddeeff') . pack('P', 9),
                200,
                true
            ),
            "\xfe",
            EventPacket::response(250, 'unknown', 300, true),
        ], $current) extends BinLogSocketConnect {
            public function __construct(
                private array $responses,
                private readonly BinLogCurrent $current
            ) {
                // Supply already unframed responses without the parent's network startup.
            }

            public function getResponse(bool $checkResponse = true): string
            {
                return array_shift($this->responses) ?? throw new RuntimeException('No response remains');
            }

            public function getCheckSum(): bool
            {
                return true;
            }

            public function getBinLogCurrent(): BinLogCurrent
            {
                return $this->current;
            }
        };
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('query', static function (EventDTO $dto) use (&$events): void {
            $events[] = $dto;
        });
        $dispatcher->addListener('gtid', static function (EventDTO $dto) use (&$events): void {
            $events[] = $dto;
        });
        $cache = new ArrayCache();
        $config = (new ConfigBuilder())->withEventsIgnore([ConstEventType::GTID_LOG_EVENT->value])->build();
        $serverInfo = EventPacket::serverInfo();
        $rowFactory = new RowEventFactory(new RowEventBuilder(
            $this->createMock(RepositoryInterface::class),
            $cache,
            $config,
            $serverInfo,
            new NullLogger()
        ));
        $event = new Event($connection, $rowFactory, $dispatcher, $cache, $config, $serverInfo);

        $event->consume();
        self::assertCount(1, $events);
        self::assertInstanceOf(QueryDTO::class, $events[0]);
        self::assertSame('SELECT 1', $events[0]->query);
        self::assertSame('query', $events[0]->getType());
        self::assertSame($current, $events[0]->getEventInfo()->binLogCurrent);
        self::assertSame('120', $current->getBinLogPosition());

        $event->consume();
        self::assertCount(1, $events);
        self::assertSame('00112233-4455-6677-8899-aabbccddeeff:9', $current->getGtid());
        self::assertSame('200', $current->getBinLogPosition());

        $event->consume();
        self::assertCount(1, $events);
        self::assertSame('200', $current->getBinLogPosition());

        $event->consume();
        self::assertCount(1, $events);
        self::assertSame('300', $events[0]->getEventInfo()->binLogCurrent->getBinLogPosition());
    }
}
