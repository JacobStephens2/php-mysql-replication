<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event;

use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\Config;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\FormatDescriptionEventDTO;
use MySQLReplication\Event\DTO\GTIDLogDTO;
use MySQLReplication\Event\DTO\HeartbeatDTO;
use MySQLReplication\Event\DTO\MariaDbGtidLogDTO;
use MySQLReplication\Event\DTO\QueryDTO;
use MySQLReplication\Event\DTO\RotateDTO;
use MySQLReplication\Event\DTO\RowsQueryDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\EventDecoder;
use MySQLReplication\Event\RowEvent\RowEventBuilder;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Repository\FieldDTOCollection;
use MySQLReplication\Repository\RepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EventDecoderTest extends TestCase
{
    private ArrayCache $cache;
    private BinLogCurrent $current;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $this->cache->clear();
        $this->current = new BinLogCurrent();
        $this->current->setBinLogPosition('4');
        $this->current->setBinFileName('binlog.000001');
        $this->current->setGtid('previous-gtid');
        $this->current->setMariaDbGtid('7');
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    public function testEofPreservesStateAndCache(): void
    {
        $decoder = $this->decoder();
        $this->cache->set('sentinel', 'keep');
        $before = $this->current->jsonSerialize();

        self::assertNull($decoder->decode("\xfe"));
        self::assertSame($before, $this->current->jsonSerialize());
        self::assertSame('keep', $this->cache->get('sentinel'));
    }

    #[DataProvider('rowEvents')]
    public function testFilteredTableMapEnablesRowDecoding(
        ConstEventType $type,
        string $dtoClass,
        string $eventName,
        bool $checksum,
        bool $ignoreTableMap
    ): void {
        $builder = new ConfigBuilder();
        $config = ($ignoreTableMap
            ? $builder->withEventsIgnore([ConstEventType::TABLE_MAP_EVENT->value])
            : $builder->withEventsOnly([$type->value]))->build();
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->with('app', 'items')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));
        $decoder = $this->decoder($config, $checksum, repository: $repository);

        self::assertNull($decoder->decode(EventPacket::response(
            ConstEventType::TABLE_MAP_EVENT->value,
            EventPacket::tableMap(),
            80,
            $checksum
        )));
        self::assertSame('80', $this->current->getBinLogPosition());

        $isUpdate = in_array($type, [ConstEventType::UPDATE_ROWS_EVENT_V1, ConstEventType::UPDATE_ROWS_EVENT_V2], true);
        $isV2 = in_array(
            $type,
            [
                ConstEventType::WRITE_ROWS_EVENT_V2,
                ConstEventType::UPDATE_ROWS_EVENT_V2,
                ConstEventType::DELETE_ROWS_EVENT_V2,
            ],
            true
        );
        // ID 7, flags, optional v2 extra-data length (including its two bytes), one column and present bitmaps.
        $body = "\x07\x00\x00\x00\x00\x00\x00\x00" . ($isV2 ? "\x02\x00" : '') . "\x01\x01";
        $body .= $isUpdate ? "\x01\x00" . pack('V', 10) . "\x00" . pack('V', 11) : "\x00" . pack('V', 10);
        $body .= $isUpdate ? "\x00" . pack('V', 20) . "\x00" . pack('V', 21) : "\x00" . pack('V', 20);

        $dto = $decoder->decode(EventPacket::response($type->value, $body, 120, $checksum));

        self::assertInstanceOf($dtoClass, $dto);
        self::assertSame($eventName, $dto->getType());
        self::assertSame('app', $dto->tableMap->database);
        self::assertSame('items', $dto->tableMap->table);
        self::assertSame('7', $dto->tableMap->tableId);
        self::assertSame(2, $dto->changedRows);
        self::assertSame($isUpdate ? [
            [
                'before' => [
                    'id' => 10,
                ],
                'after' => [
                    'id' => 11,
                ],
            ],
            [
                'before' => [
                    'id' => 20,
                ],
                'after' => [
                    'id' => 21,
                ],
            ],
        ] : [[
            'id' => 10,
        ], [
            'id' => 20,
        ]], $dto->values);
        self::assertSame($this->current, $dto->getEventInfo()->binLogCurrent);
        self::assertSame('120', $this->current->getBinLogPosition());
    }

    public static function rowEvents(): iterable
    {
        foreach ([
            [ConstEventType::WRITE_ROWS_EVENT_V1, WriteRowsDTO::class, 'write'],
            [ConstEventType::WRITE_ROWS_EVENT_V2, WriteRowsDTO::class, 'write'],
            [ConstEventType::UPDATE_ROWS_EVENT_V1, UpdateRowsDTO::class, 'update'],
            [ConstEventType::UPDATE_ROWS_EVENT_V2, UpdateRowsDTO::class, 'update'],
            [ConstEventType::DELETE_ROWS_EVENT_V1, DeleteRowsDTO::class, 'delete'],
            [ConstEventType::DELETE_ROWS_EVENT_V2, DeleteRowsDTO::class, 'delete'],
        ] as [$type, $dtoClass, $eventName]) {
            foreach ([false, true] as $checksum) {
                foreach ([false, true] as $ignoreTableMap) {
                    yield $type->name . '-checksum-' . (int)$checksum . '-ignore-map-' . (int)$ignoreTableMap => [
                        $type, $dtoClass, $eventName, $checksum, $ignoreTableMap,
                    ];
                }
            }
        }
    }

    public function testFilteredRotateClearsCacheBeforeTableIdReuse(): void
    {
        $config = (new ConfigBuilder())->withEventsOnly([ConstEventType::WRITE_ROWS_EVENT_V1->value])->build();
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->willReturnMap([
                ['app', 'items', new FieldDTOCollection([new FieldDTO('old_id', null, null, '', 'int', '')])],
                ['app', 'replacement', new FieldDTOCollection([new FieldDTO('new_id', null, null, '', 'int', '')])],
            ]);
        $decoder = $this->decoder($config, repository: $repository);
        $row = "\x07\x00\x00\x00\x00\x00\x00\x00\x01\x01\x00" . pack('V', 42);

        self::assertNull(
            $decoder->decode(EventPacket::response(ConstEventType::TABLE_MAP_EVENT->value, EventPacket::tableMap()))
        );
        $before = $decoder->decode(EventPacket::response(ConstEventType::WRITE_ROWS_EVENT_V1->value, $row));
        self::assertInstanceOf(WriteRowsDTO::class, $before);
        self::assertSame([[
            'old_id' => 42,
        ]], $before->values);
        $this->cache->set('sentinel', 'discard');

        self::assertNull($decoder->decode(EventPacket::response(
            ConstEventType::ROTATE_EVENT->value,
            pack('P', 4) . 'binlog.000002',
            300
        )));
        self::assertFalse($this->cache->has('sentinel'));
        self::assertFalse($this->cache->has('7'));
        self::assertSame('binlog.000002', $this->current->getBinFileName());
        self::assertSame('4', $this->current->getBinLogPosition());
        self::assertSame('4', $before->getEventInfo()->binLogCurrent->getBinLogPosition());
        self::assertNull($decoder->decode(EventPacket::response(ConstEventType::WRITE_ROWS_EVENT_V1->value, $row, 0)));

        self::assertNull(
            $decoder->decode(EventPacket::response(
                ConstEventType::TABLE_MAP_EVENT->value,
                EventPacket::tableMap('replacement')
            ))
        );
        $after = $decoder->decode(EventPacket::response(ConstEventType::WRITE_ROWS_EVENT_V1->value, $row));
        self::assertInstanceOf(WriteRowsDTO::class, $after);
        self::assertSame('replacement', $after->tableMap->table);
        self::assertSame([[
            'new_id' => 42,
        ]], $after->values);
    }

    #[DataProvider('rotateServers')]
    public function testAllowedRotatePreservesServerSpecificFilenameParsing(
        bool $mariaDb,
        float $revision,
        bool $checksum
    ): void {
        $decoder = $this->decoder(checksum: $checksum, serverInfo: EventPacket::serverInfo($mariaDb, $revision));
        $dto = $decoder->decode(EventPacket::response(
            ConstEventType::ROTATE_EVENT->value,
            pack('P', 4) . 'binlog.000002',
            0,
            $checksum
        ));

        self::assertInstanceOf(RotateDTO::class, $dto);
        self::assertSame('rotate', $dto->getType());
        self::assertSame('4', $dto->position);
        self::assertSame('binlog.000002', $dto->nextBinlog);
        self::assertSame('binlog.000002', $this->current->getBinFileName());
        self::assertSame('4', $this->current->getBinLogPosition());
    }

    public static function rotateServers(): iterable
    {
        yield 'MySQL' => [false, 8.0, false];
        yield 'MariaDB legacy filename length' => [true, 10.0, false];
        yield 'MariaDB' => [true, 10.11, true];
    }

    public function testHeaderPositionsAdvanceBeforeSuppressionAndRemainLive(): void
    {
        $decoder = $this->decoder();
        $dto = $decoder->decode(EventPacket::response(ConstEventType::HEARTBEAT_LOG_EVENT->value));
        self::assertInstanceOf(HeartbeatDTO::class, $dto);
        self::assertSame('heartbeat', $dto->getType());
        $info = $dto->getEventInfo();
        self::assertSame(1700000000, $info->timestamp);
        self::assertSame(42, $info->serverId);
        self::assertSame(19, $info->size);
        self::assertSame(1, $info->flag);
        self::assertSame('HEARTBEAT_LOG_EVENT', $info->getTypeName());
        self::assertSame($this->current, $info->binLogCurrent);

        // 250 is unknown; STOP_EVENT is known but unsupported. Neither body is parsed.
        self::assertNull($decoder->decode(EventPacket::response(250, 'opaque', 200)));
        self::assertSame('200', $this->current->getBinLogPosition());
        self::assertNull($decoder->decode(EventPacket::response(ConstEventType::STOP_EVENT->value, 'opaque', 300)));
        self::assertSame('300', $info->binLogCurrent->getBinLogPosition());
        self::assertNull($decoder->decode(EventPacket::response(250, '', 0)));
        self::assertSame('300', $this->current->getBinLogPosition());
        self::assertSame('120', $info->pos);
    }

    public function testIgnoredOrdinaryBodiesAreNotParsedAndIgnoreWinsOverAllow(): void
    {
        $types = [ConstEventType::QUERY_EVENT->value, ConstEventType::XID_EVENT->value,
            ConstEventType::ROWS_QUERY_LOG_EVENT->value, ConstEventType::WRITE_ROWS_EVENT_V2->value,
            ConstEventType::HEARTBEAT_LOG_EVENT->value];
        $decoder = $this->decoder((new ConfigBuilder())->withEventsOnly($types)->withEventsIgnore($types)->build());

        foreach ($types as $index => $type) {
            // Empty bodies would fail in the ordinary parsers if the early filter were lost.
            self::assertNull($decoder->decode(EventPacket::response($type, '', 400 + $index)));
            self::assertSame((string)(400 + $index), $this->current->getBinLogPosition());
        }
    }

    #[DataProvider('gtidFilters')]
    public function testMysqlGtidUpdatesStateBeforeDispatchFiltering(bool $allowed, bool $checksum): void
    {
        $config = (new ConfigBuilder())->withEventsIgnore(
            $allowed ? [] : [ConstEventType::GTID_LOG_EVENT->value]
        )->build();
        $decoder = $this->decoder($config, $checksum);
        $dto = $decoder->decode(EventPacket::response(
            ConstEventType::GTID_LOG_EVENT->value,
            "\x01" . hex2bin('00112233445566778899aabbccddeeff') . pack('P', 9),
            200,
            $checksum
        ));

        self::assertSame('00112233-4455-6677-8899-aabbccddeeff:9', $this->current->getGtid());
        self::assertSame('200', $this->current->getBinLogPosition());
        if ($allowed) {
            self::assertInstanceOf(GTIDLogDTO::class, $dto);
            self::assertSame('gtid', $dto->getType());
            self::assertTrue($dto->commit);
            self::assertSame('00112233-4455-6677-8899-aabbccddeeff:9', $dto->gtid);
            self::assertSame($this->current, $dto->getEventInfo()->binLogCurrent);
        } else {
            self::assertNull($dto);
        }
    }

    #[DataProvider('gtidFilters')]
    public function testMariaDbGtidUpdatesStateBeforeDispatchFiltering(bool $allowed, bool $checksum): void
    {
        $config = (new ConfigBuilder())->withEventsIgnore(
            $allowed ? [] : [ConstEventType::MARIA_GTID_EVENT->value]
        )->build();
        $decoder = $this->decoder($config, $checksum, EventPacket::serverInfo(true));
        $dto = $decoder->decode(EventPacket::response(
            ConstEventType::MARIA_GTID_EVENT->value,
            pack('PVC', 11, 12, 3),
            250,
            $checksum
        ));

        self::assertSame('11', $this->current->getMariaDbGtid());
        self::assertSame('250', $this->current->getBinLogPosition());
        if ($allowed) {
            self::assertInstanceOf(MariaDbGtidLogDTO::class, $dto);
            self::assertSame('mariadb gtid', $dto->getType());
            self::assertSame('11', $dto->mariaDbGtid);
            self::assertSame(12, $dto->domainId);
            self::assertSame(3, $dto->flag);
            self::assertSame($this->current, $dto->getEventInfo()->binLogCurrent);
        } else {
            self::assertNull($dto);
        }
    }

    public static function gtidFilters(): iterable
    {
        yield 'allowed' => [true, false];
        yield 'ignored' => [false, false];
        yield 'allowed with checksum' => [true, true];
        yield 'ignored with checksum' => [false, true];
    }

    public function testFormatDescriptionAndXidReturnExistingDtos(): void
    {
        $decoder = $this->decoder(checksum: true);
        $format = $decoder->decode(
            EventPacket::response(ConstEventType::FORMAT_DESCRIPTION_EVENT->value, 'format', 120, true)
        );
        self::assertInstanceOf(FormatDescriptionEventDTO::class, $format);
        self::assertSame('format description', $format->getType());
        self::assertSame(6, $format->getEventInfo()->getSizeNoHeader());

        $xid = $decoder->decode(
            EventPacket::response(ConstEventType::XID_EVENT->value, pack('P', 4294967297), 160, true)
        );
        self::assertInstanceOf(XidDTO::class, $xid);
        self::assertSame('xid', $xid->getType());
        self::assertSame('4294967297', $xid->xid);
        self::assertSame('160', $format->getEventInfo()->binLogCurrent->getBinLogPosition());
    }

    #[DataProvider('queries')]
    public function testQueriesSuppressOnlyMariaDbDummyQueries(bool $mariaDb, string $query, bool $suppressed): void
    {
        $decoder = $this->decoder(checksum: true, serverInfo: EventPacket::serverInfo($mariaDb));
        $dto = $decoder->decode(
            EventPacket::response(ConstEventType::QUERY_EVENT->value, EventPacket::query($query), 200, true)
        );

        self::assertSame('200', $this->current->getBinLogPosition());
        if ($suppressed) {
            self::assertNull($dto);
        } else {
            self::assertInstanceOf(QueryDTO::class, $dto);
            self::assertSame('query', $dto->getType());
            self::assertSame('app', $dto->database);
            self::assertSame(123, $dto->threadId);
            self::assertSame(2, $dto->executionTime);
            self::assertSame($query, $dto->query);
        }
    }

    public static function queries(): iterable
    {
        yield 'normal MySQL query' => [false, 'SELECT 1', false];
        yield 'normal MariaDB query' => [true, 'SELECT 1', false];
        yield 'MySQL dummy text is delivered' => [false, 'prefix # Dummy event', false];
        yield 'MariaDB dummy substring is suppressed' => [true, 'prefix # Dummy event', true];
        yield 'MariaDB matching stays case sensitive' => [true, '# dummy event', false];
    }

    #[DataProvider('rowsQueries')]
    public function testRowsQueryUsesFullBodyLengthWithoutChecksumTrailer(string $query, bool $checksum): void
    {
        $decoder = $this->decoder(checksum: $checksum);
        // The one-byte length wraps for queries longer than 255 bytes; event size supplies the actual length.
        $body = chr(strlen($query) % 256) . $query;
        $dto = $decoder->decode(
            EventPacket::response(ConstEventType::ROWS_QUERY_LOG_EVENT->value, $body, 200, $checksum)
        );

        self::assertInstanceOf(RowsQueryDTO::class, $dto);
        self::assertSame('rows_query', $dto->getType());
        self::assertSame($query, $dto->query);
        self::assertSame(strlen($query) + 1, $dto->getEventInfo()->getSizeNoHeader());
        self::assertSame($checksum, $dto->getEventInfo()->checkSum);
        self::assertSame('200', $this->current->getBinLogPosition());
    }

    public static function rowsQueries(): iterable
    {
        yield 'short without checksum' => ['SELECT 1', false];
        yield 'short with checksum' => ['SELECT 1', true];
        yield 'long without checksum' => [str_repeat('SELECT 1; ', 30), false];
        yield 'long with checksum' => [str_repeat('SELECT 1; ', 30), true];
    }

    private function decoder(
        ?Config $config = null,
        bool $checksum = false,
        ?BinLogServerInfo $serverInfo = null,
        ?RepositoryInterface $repository = null
    ): EventDecoder {
        $config ??= (new ConfigBuilder())->build();
        $serverInfo ??= EventPacket::serverInfo();
        $repository ??= $this->createMock(RepositoryInterface::class);

        return new EventDecoder(
            new RowEventFactory(new RowEventBuilder($repository, $this->cache, $config, $serverInfo, new NullLogger())),
            $this->cache,
            $config,
            $serverInfo,
            $checksum,
            $this->current
        );
    }
}
