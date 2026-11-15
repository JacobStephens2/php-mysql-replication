<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event\RowEvent;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Definitions\ConstFieldType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\RowsDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\RowEvent\RowEventDecoder;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Repository\FieldDTOCollection;
use MySQLReplication\Repository\RepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

class RowEventDecoderTest extends TestCase
{
    public function testDecodeTableMapCachesSchemaAndAvoidsDuplicateRepositoryCalls(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(self::exactly(2))
            ->method('getFields')
            ->with('app', 'items')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));

        $cache = new ArrayCache(10);
        $current = new BinLogCurrent();
        $current->setBinFileName('binlog.000001');
        $current->setBinLogPosition('4');
        $decoder = new RowEventDecoder($repository, $cache, (new ConfigBuilder())->build());

        // Six-byte table ID 7, flags, schema "app", table "items", 1 LONG column, 0 metadata bytes.
        $body = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x05items\x00\x01\x03\x00";
        $eventInfo = $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($body), $current);

        $first = $decoder->decode($eventInfo, new BinaryDataReader($body));
        self::assertInstanceOf(TableMapDTO::class, $first);
        self::assertSame('app', $first->tableMap->database);
        self::assertSame('items', $first->tableMap->table);
        self::assertSame('7', $first->tableMap->tableId);
        self::assertSame(1, $first->tableMap->columnsAmount);
        self::assertSame('binlog.000001', $current->getBinFileName());
        self::assertSame('4', $current->getBinLogPosition());

        // Second TABLE_MAP_EVENT for same table ID uses cached TableMap without calling getFields().
        $second = $decoder->decode($eventInfo, new BinaryDataReader($body));
        self::assertInstanceOf(TableMapDTO::class, $second);
        self::assertSame($first->tableMap, $second->tableMap);

        // Clearing table maps forces repository lookup on the next TABLE_MAP_EVENT.
        $decoder->clearTableMaps();
        $third = $decoder->decode($eventInfo, new BinaryDataReader($body));
        self::assertInstanceOf(TableMapDTO::class, $third);
        self::assertNotSame($first->tableMap, $third->tableMap);
    }

    public function testDecodeThrowsOnUnsupportedEventType(): void
    {
        $decoder = new RowEventDecoder($this->createMock(RepositoryInterface::class));
        $eventInfo = $this->makeEventInfo(ConstEventType::QUERY_EVENT, 0, new BinLogCurrent());

        $this->expectException(MySQLReplicationException::class);
        $decoder->decode($eventInfo, new BinaryDataReader(''));
    }

    public function testDatabaseExclusionStopsBeforeReadingTableNameRepositoryOrCache(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(self::never())->method('getFields');
        $cache = new ArrayCache(10);
        $config = (new ConfigBuilder())->withDatabasesOnly(['allowed_db'])->build();
        $decoder = new RowEventDecoder($repository, $cache, $config);

        // Payload ends immediately after schema name to prove table name bytes are not read.
        $body = "\x07\x00\x00\x00\x00\x00\x00\x00\x08other_db";
        $eventInfo = $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($body), new BinLogCurrent());

        self::assertNull($decoder->decode($eventInfo, new BinaryDataReader($body)));
        self::assertFalse($cache->has('7'));
    }

    public function testTableExclusionStopsBeforeCacheLookupAndRepositoryCall(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(self::never())->method('getFields');
        $cache = new ArrayCache(10);
        $cache->set('7', 'not-a-table-map');
        $config = (new ConfigBuilder())
            ->withDatabasesOnly(['app'])
            ->withTablesOnly(['wanted'])
            ->build();
        $decoder = new RowEventDecoder($repository, $cache, $config);

        // Payload ends right after table name to prove column count and cache are not read.
        $body = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x07ignored";
        $eventInfo = $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($body), new BinLogCurrent());

        self::assertNull($decoder->decode($eventInfo, new BinaryDataReader($body)));
        self::assertSame('not-a-table-map', $cache->get('7'));

        // Even with a valid cached TableMap for an allowed table sharing ID 7, an excluded table returns null.
        $allowedBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x06wanted\x00\x01\x03\x00";
        $repoAllowed = $this->createMock(RepositoryInterface::class);
        $repoAllowed->expects(self::once())
            ->method('getFields')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));
        $decoderAllowed = new RowEventDecoder($repoAllowed, $cache, $config);
        $cache->clear();
        self::assertInstanceOf(TableMapDTO::class, $decoderAllowed->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($allowedBody), new BinLogCurrent()),
            new BinaryDataReader($allowedBody)
        ));
        self::assertNull($decoderAllowed->decode($eventInfo, new BinaryDataReader($body)));
    }

    public function testNonTableMapCacheEntryIsSafelyReplacedOnTableMapAndLoggedAsMissingOnRowEvent(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(self::once())
            ->method('getFields')
            ->with('app', 'items')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));

        $cache = new ArrayCache(10);
        $cache->set('7', 'corrupt-entry');
        $logger = $this->createSpyLogger();
        $decoder = new RowEventDecoder($repository, $cache, null, $logger);

        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x01\x01\x00" . pack('V', 99);
        $rowInfo = $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent());
        self::assertNull($decoder->decode($rowInfo, new BinaryDataReader($rowBody)));
        self::assertSame([
            [
                'level' => 'debug',
                'message' => 'No table map found for table ID: 7',
            ],
        ], $logger->records);

        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x05items\x00\x01\x03\x00";
        $tableMapInfo = $this->makeEventInfo(
            ConstEventType::TABLE_MAP_EVENT,
            strlen($tableMapBody),
            new BinLogCurrent()
        );
        $dto = $decoder->decode($tableMapInfo, new BinaryDataReader($tableMapBody));
        self::assertInstanceOf(TableMapDTO::class, $dto);
        self::assertSame('items', $dto->tableMap->table);
    }

    public function testMissingTableMapLogsDebugAndReturnsNull(): void
    {
        $logger = $this->createSpyLogger();
        $decoder = new RowEventDecoder($this->createMock(RepositoryInterface::class), null, null, $logger);

        $body = "\x09\x00\x00\x00\x00\x00\x00\x00\x01\x01\x00" . pack('V', 1);
        $eventInfo = $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V1, strlen($body), new BinLogCurrent());

        self::assertNull($decoder->decode($eventInfo, new BinaryDataReader($body)));
        self::assertSame([
            [
                'level' => 'debug',
                'message' => 'No table map found for table ID: 9',
            ],
        ], $logger->records);
    }

    #[DataProvider('rowFramingModes')]
    public function testDecodeRowFramingAcrossV1V2ReadersAndChecksumModes(
        ConstEventType $type,
        string $dtoClass,
        string $eventName,
        bool $v2,
        bool $headerConsumed,
        bool $checksum
    ): void {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->with('app', 'items')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));

        $current = new BinLogCurrent();
        $current->setBinFileName('binlog.000001');
        $current->setBinLogPosition('4');
        $decoder = new RowEventDecoder($repository, new ArrayCache(10), (new ConfigBuilder())->build());

        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x05items\x00\x01\x03\x00";
        $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), $current, $checksum),
            $this->makeReader($tableMapBody, $headerConsumed, $checksum)
        );

        $isUpdate = in_array($type, [ConstEventType::UPDATE_ROWS_EVENT_V1, ConstEventType::UPDATE_ROWS_EVENT_V2], true);
        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00" . ($v2 ? "\x02\x00" : '') . "\x01\x01";
        $rowBody .= $isUpdate
            ? "\x01\x00" . pack('V', 10) . "\x00" . pack('V', 11) . "\x00" . pack('V', 20) . "\x00" . pack('V', 21)
            : "\x00" . pack('V', 10) . "\x00" . pack('V', 20);

        $reader = $this->makeReader($rowBody, $headerConsumed, $checksum);
        $dto = $decoder->decode($this->makeEventInfo($type, strlen($rowBody), $current, $checksum), $reader);

        self::assertInstanceOf($dtoClass, $dto);
        self::assertSame($eventName, $dto->getType());
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
        ] : [
            [
                'id' => 10,
            ],
            [
                'id' => 20,
            ],
        ], $dto->values);
        self::assertSame($checksum ? "\xde\xad\xbe\xef" : '', $reader->getBinaryData());
        self::assertSame('binlog.000001', $current->getBinFileName());
        self::assertSame('4', $current->getBinLogPosition());
    }

    public static function rowFramingModes(): iterable
    {
        foreach ([
            [ConstEventType::WRITE_ROWS_EVENT_V1, WriteRowsDTO::class, 'write', false],
            [ConstEventType::WRITE_ROWS_EVENT_V2, WriteRowsDTO::class, 'write', true],
            [ConstEventType::UPDATE_ROWS_EVENT_V1, UpdateRowsDTO::class, 'update', false],
            [ConstEventType::UPDATE_ROWS_EVENT_V2, UpdateRowsDTO::class, 'update', true],
            [ConstEventType::DELETE_ROWS_EVENT_V1, DeleteRowsDTO::class, 'delete', false],
            [ConstEventType::DELETE_ROWS_EVENT_V2, DeleteRowsDTO::class, 'delete', true],
        ] as [$type, $dtoClass, $eventName, $v2]) {
            foreach ([false, true] as $headerConsumed) {
                foreach ([false, true] as $checksum) {
                    yield $type->name . '-offset-' . ($headerConsumed ? '20' : '0') . '-checksum-' . (int)$checksum => [
                        $type,
                        $dtoClass,
                        $eventName,
                        $v2,
                        $headerConsumed,
                        $checksum,
                    ];
                }
            }
        }
    }

    #[DataProvider('droppedTableRowTypes')]
    public function testDroppedTableReturnsEmptyValuesAcrossWriteUpdateAndDeleteRows(
        ConstEventType $type,
        string $dtoClass,
        bool $isUpdate
    ): void {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->willReturn(new FieldDTOCollection());

        $decoder = new RowEventDecoder($repository, new ArrayCache(10));
        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x07dropped\x00\x01\x03\x00";
        $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), new BinLogCurrent()),
            new BinaryDataReader($tableMapBody)
        );

        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x01\x01"
            . ($isUpdate ? "\x01\x00" . pack('V', 1) . "\x00" . pack('V', 2) : "\x00" . pack('V', 1));
        $dto = $decoder->decode(
            $this->makeEventInfo($type, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );

        self::assertInstanceOf($dtoClass, $dto);
        self::assertSame(0, $dto->changedRows);
        self::assertSame([], $dto->values);
    }

    public static function droppedTableRowTypes(): iterable
    {
        yield 'write' => [ConstEventType::WRITE_ROWS_EVENT_V2, WriteRowsDTO::class, false];
        yield 'update' => [ConstEventType::UPDATE_ROWS_EVENT_V2, UpdateRowsDTO::class, true];
        yield 'delete' => [ConstEventType::DELETE_ROWS_EVENT_V2, DeleteRowsDTO::class, false];
    }

    public function testDroppedTrailingColumnSynthesizesDummyAndSkipsPayloadBytes(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->with('app', 'altered')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));

        $decoder = new RowEventDecoder($repository, new ArrayCache(10));

        // 2 columns in binlog: LONG + VARCHAR(50) encoded as BLOB/IGNORE metadata lengthSize=1 after drop.
        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x07altered\x00\x02"
            . chr(ConstFieldType::LONG) . chr(ConstFieldType::VARCHAR)
            . "\x01\x05";
        $tableMapDto = $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), new BinLogCurrent()),
            new BinaryDataReader($tableMapBody)
        );
        self::assertInstanceOf(TableMapDTO::class, $tableMapDto);
        self::assertSame('DROPPED_COLUMN_1', $tableMapDto->tableMap->columnDTOCollection->get(1)?->getName());

        // Row payload: present bitmap 0x03 (2 bits), null bitmap 0x00, id=7 (4 bytes), dropped col (5 bytes).
        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x02\x03\x00" . pack('V', 7) . 'abcde';
        $rowDto = $decoder->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );

        self::assertInstanceOf(WriteRowsDTO::class, $rowDto);
        self::assertSame([[
            'id' => 7,
            'DROPPED_COLUMN_1' => null,
        ]], $rowDto->values);
    }

    public function testRegexFiltersAndTableMapCacheIsolationBetweenDecoders(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(self::once())
            ->method('getFields')
            ->with('tenant_42', 'orders_2026')
            ->willReturn(new FieldDTOCollection([new FieldDTO('id', null, null, '', 'int', 'PRI')]));

        $config = (new ConfigBuilder())
            ->withDatabasesRegex(['/^tenant_\d+$/'])
            ->withTablesRegex(['/^orders_\d{4}$/'])
            ->build();

        $decoderA = new RowEventDecoder($repository, null, $config);
        $decoderB = new RowEventDecoder($this->createMock(RepositoryInterface::class), null, $config);

        $excludedDbBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x05other";
        self::assertNull($decoderA->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($excludedDbBody), new BinLogCurrent()),
            new BinaryDataReader($excludedDbBody)
        ));

        $allowedBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x09tenant_42\x00\x0borders_2026\x00\x01\x03\x00";
        $tableMapDto = $decoderA->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($allowedBody), new BinLogCurrent()),
            new BinaryDataReader($allowedBody)
        );
        self::assertInstanceOf(TableMapDTO::class, $tableMapDto);

        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x01\x01\x00" . pack('V', 55);
        $rowDtoA = $decoderA->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );
        self::assertInstanceOf(WriteRowsDTO::class, $rowDtoA);
        self::assertSame([[
            'id' => 55,
        ]], $rowDtoA->values);

        // Separate decoder instance using default ArrayCache must not see decoderA's cached TableMap.
        self::assertNull($decoderB->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        ));
    }

    public function testPartialColumnsBitmapAndMultiByteNullBitmap(): void
    {
        $fields = [];
        $columnTypes = '';
        for ($i = 1; $i <= 10; ++$i) {
            $fields[] = new FieldDTO('c' . $i, null, null, '', 'tinyint', '');
            $columnTypes .= chr(ConstFieldType::TINY);
        }

        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->willReturn(new FieldDTOCollection($fields));
        $decoder = new RowEventDecoder($repository, new ArrayCache(10));

        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x08nullable\x00\x0a" . $columnTypes . "\x00";
        $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), new BinLogCurrent()),
            new BinaryDataReader($tableMapBody)
        );

        // Present bitmap (2 bytes for 10 cols): c1, c2, c3, c7, c9, c10 present -> 0b01000111 = 0x47, 0b00000011 = 0x03 (6 bits set -> 1-byte null bitmap).
        // Null bits among the 6 present cols (c1, c2, c3, c7, c9, c10): c1 (bit 0) and c9 (bit 4) are NULL -> 0x11.
        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x0a\x47\x03\x11"
            . pack('c', -128) // c2
            . pack('c', 12)   // c3
            . pack('c', 42)   // c7
            . pack('c', 84);  // c10

        $dto = $decoder->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );

        self::assertInstanceOf(WriteRowsDTO::class, $dto);
        self::assertSame([[
            'c1' => null,
            'c2' => -128,
            'c3' => 12,
            'c4' => null,
            'c5' => null,
            'c6' => null,
            'c7' => 42,
            'c8' => null,
            'c9' => null,
            'c10' => 84,
        ]], $dto->values);
    }

    public function testDecodeNumericAndDecimalColumnTypes(): void
    {
        $fields = new FieldDTOCollection([
            new FieldDTO('tiny_u', null, null, '', 'tinyint unsigned', ''),
            new FieldDTO('tiny_s', null, null, '', 'tinyint', ''),
            new FieldDTO('short_u', null, null, '', 'smallint unsigned', ''),
            new FieldDTO('short_s', null, null, '', 'smallint', ''),
            new FieldDTO('int24_u', null, null, '', 'mediumint unsigned', ''),
            new FieldDTO('int24_s', null, null, '', 'mediumint', ''),
            new FieldDTO('long_u', null, null, '', 'int unsigned', ''),
            new FieldDTO('long_s', null, null, '', 'int', ''),
            new FieldDTO('bigint_u', null, null, '', 'bigint unsigned', ''),
            new FieldDTO('bigint_s', null, null, '', 'bigint', ''),
            new FieldDTO('flt', null, null, '', 'float', ''),
            new FieldDTO('dbl', null, null, '', 'double', ''),
            new FieldDTO('dec_pos', null, null, '', 'decimal(20,10)', ''),
            new FieldDTO('dec_neg', null, null, '', 'decimal(11,4)', ''),
            new FieldDTO('dec_zero', null, null, '', 'decimal(23,0)', ''),
        ]);

        $columnTypes = chr(ConstFieldType::TINY)
            . chr(ConstFieldType::TINY)
            . chr(ConstFieldType::SHORT)
            . chr(ConstFieldType::SHORT)
            . chr(ConstFieldType::INT24)
            . chr(ConstFieldType::INT24)
            . chr(ConstFieldType::LONG)
            . chr(ConstFieldType::LONG)
            . chr(ConstFieldType::LONGLONG)
            . chr(ConstFieldType::LONGLONG)
            . chr(ConstFieldType::FLOAT)
            . chr(ConstFieldType::DOUBLE)
            . chr(ConstFieldType::NEWDECIMAL)
            . chr(ConstFieldType::NEWDECIMAL)
            . chr(ConstFieldType::NEWDECIMAL);

        $metadata = "\x04\x08\x14\x0a\x0b\x04\x17\x00";

        $rowPayload = "\xff"
            . "\x80"
            . pack('v', 65535)
            . pack('s', -32768)
            . "\xff\xff\xff"
            . "\x00\x00\x80"
            . pack('V', 4294967295)
            . pack('l', -2147483648)
            . "\xff\xff\xff\xff\xff\xff\xff\xff"
            . pack('q', PHP_INT_MIN)
            . pack('f', 42.42)
            . pack('d', -84.84)
            // DECIMAL(20,10): 9000000123.0000012345 -> integral 10 (1 + 4 bytes), frac 10 (4 + 1 bytes).
            . "\x89" . pack('N', 123) . pack('N', 1234) . "\x05"
            // DECIMAL(11,4): -51.1234 -> ~("\x00\x00\x00\x33\x04\xd2") with MSB flipped.
            . "\x7f\xff\xff\xcc\xfb\x2d"
            // DECIMAL(23,0): 100000 -> integral 23 (comp=5 -> 3 bytes + 2*4 bytes).
            . "\x80\x00\x00" . pack('N', 0) . pack('N', 100000);

        $dto = $this->decodeSingleRow($fields, $columnTypes, $metadata, "\xff\x7f\x00\x00", $rowPayload);
        $row = $dto->values[0];

        self::assertSame(255, $row['tiny_u']);
        self::assertSame(-128, $row['tiny_s']);
        self::assertSame(65535, $row['short_u']);
        self::assertSame(-32768, $row['short_s']);
        self::assertSame(16777215, $row['int24_u']);
        self::assertSame(-8388608, $row['int24_s']);
        self::assertSame(4294967295, $row['long_u']);
        self::assertSame(-2147483648, $row['long_s']);
        self::assertSame('18446744073709551615', $row['bigint_u']);
        self::assertSame('-9223372036854775808', $row['bigint_s']);
        self::assertSame(42.42, $row['flt']);
        self::assertSame(-84.84, $row['dbl']);
        self::assertSame('9000000123.0000012345', $row['dec_pos']);
        self::assertSame('-51.1234', $row['dec_neg']);
        self::assertSame('100000', $row['dec_zero']);
    }

    public function testDecodeTemporalColumnTypes(): void
    {
        $fields = new FieldDTOCollection([
            new FieldDTO('d_valid', null, null, '', 'date', ''),
            new FieldDTO('d_zero', null, null, '', 'date', ''),
            new FieldDTO('d_zero_month', null, null, '', 'date', ''),
            new FieldDTO('d_zero_day', null, null, '', 'date', ''),
            new FieldDTO('y_valid', null, null, '', 'year', ''),
            new FieldDTO('y_zero', null, null, '', 'year', ''),
            new FieldDTO('t_valid', null, null, '', 'time', ''),
            new FieldDTO('t_zero', null, null, '', 'time', ''),
            new FieldDTO('t2', null, null, '', 'time(0)', ''),
            new FieldDTO('ts', null, null, '', 'timestamp', ''),
            new FieldDTO('ts2_0', null, null, '', 'timestamp(0)', ''),
            new FieldDTO('ts2', null, null, '', 'timestamp(3)', ''),
            new FieldDTO('dt_valid', null, null, '', 'datetime', ''),
            new FieldDTO('dt_zero', null, null, '', 'datetime', ''),
            new FieldDTO('dt_broken', null, null, '', 'datetime', ''),
            new FieldDTO('dt2_0', null, null, '', 'datetime(0)', ''),
            new FieldDTO('dt2_fsp', null, null, '', 'datetime(6)', ''),
            new FieldDTO('dt2_zero', null, null, '', 'datetime(6)', ''),
            new FieldDTO('dt2_broken', null, null, '', 'datetime(6)', ''),
        ]);

        $columnTypes = chr(ConstFieldType::DATE)
            . chr(ConstFieldType::DATE)
            . chr(ConstFieldType::DATE)
            . chr(ConstFieldType::DATE)
            . chr(ConstFieldType::YEAR)
            . chr(ConstFieldType::YEAR)
            . chr(ConstFieldType::TIME)
            . chr(ConstFieldType::TIME)
            . chr(ConstFieldType::TIME2)
            . chr(ConstFieldType::TIMESTAMP)
            . chr(ConstFieldType::TIMESTAMP2)
            . chr(ConstFieldType::TIMESTAMP2)
            . chr(ConstFieldType::DATETIME)
            . chr(ConstFieldType::DATETIME)
            . chr(ConstFieldType::DATETIME)
            . chr(ConstFieldType::DATETIME2)
            . chr(ConstFieldType::DATETIME2)
            . chr(ConstFieldType::DATETIME2)
            . chr(ConstFieldType::DATETIME2);

        // Metadata: time2(0), timestamp2(0), timestamp2(3), datetime2(0), datetime2(6), datetime2(6), datetime2(6).
        $metadata = "\x00\x00\x03\x00\x06\x06\x06";

        $dateValid = (1984 << 9) | (12 << 5) | 3;
        $dateZeroMonth = (2015 << 9) | 21;
        $dateZeroDay = (2015 << 9) | (5 << 5);
        $time2Packed = (1 << 23) | (12 << 12) | (33 << 6) | 18;
        $ts1984 = 470925187; // 1984-12-03 12:33:07 UTC
        $dt2Packed = (1 << 39) | (((1984 * 13) + 12) << 22) | (3 << 17) | (12 << 12) | (33 << 6) | 7;
        $dt2ZeroPacked = 1 << 39;
        $dt2BrokenPacked = (1 << 39) | ((2013 * 13) << 22);

        $rowPayload = substr(pack('V', $dateValid), 0, 3)
            . "\x00\x00\x00"
            . substr(pack('V', $dateZeroMonth), 0, 3)
            . substr(pack('V', $dateZeroDay), 0, 3)
            . chr(84)
            . "\x00"
            . substr(pack('V', 123318), 0, 3)
            . "\x00\x00\x00"
            . chr(($time2Packed >> 16) & 0xff) . pack('n', $time2Packed & 0xffff)
            . pack('V', $ts1984)
            . pack('N', $ts1984)
            . pack('N', $ts1984) . pack('n', 1230)
            . BinaryDataReader::pack64bit(19841203123307)
            . BinaryDataReader::pack64bit(0)
            . BinaryDataReader::pack64bit(20130000000000)
            . chr(($dt2Packed >> 32) & 0xff) . pack('N', $dt2Packed & 0xffffffff)
            . chr(($dt2Packed >> 32) & 0xff) . pack('N', $dt2Packed & 0xffffffff) . "\x00\x5b\x9a"
            . chr(($dt2ZeroPacked >> 32) & 0xff) . pack('N', $dt2ZeroPacked & 0xffffffff) . "\x00\x00\x00"
            . chr(($dt2BrokenPacked >> 32) & 0xff) . pack('N', $dt2BrokenPacked & 0xffffffff) . "\x00\x00\x00";

        $dto = $this->decodeSingleRow($fields, $columnTypes, $metadata, "\xff\xff\x07\x00\x00\x00", $rowPayload);
        $row = $dto->values[0];

        self::assertSame('1984-12-03', $row['d_valid']);
        self::assertNull($row['d_zero']);
        self::assertNull($row['d_zero_month']);
        self::assertNull($row['d_zero_day']);
        self::assertSame(1984, $row['y_valid']);
        self::assertNull($row['y_zero']);
        self::assertSame('12:33:18', $row['t_valid']);
        self::assertSame('00:00:00', $row['t_zero']);
        self::assertSame('12:33:18', $row['t2']);
        self::assertSame('1984-12-03 12:33:07', $row['ts']);
        self::assertSame('1984-12-03 12:33:07', $row['ts2_0']);
        self::assertSame('1984-12-03 12:33:07.123000', $row['ts2']);
        self::assertSame('1984-12-03 12:33:07', $row['dt_valid']);
        self::assertNull($row['dt_zero']);
        self::assertNull($row['dt_broken']);
        self::assertSame('1984-12-03 12:33:07', $row['dt2_0']);
        self::assertSame('1984-12-03 12:33:07.023450', $row['dt2_fsp']);
        self::assertNull($row['dt2_zero']);
        self::assertNull($row['dt2_broken']);
    }

    public function testDecodeStringBlobGeometryEnumSetBitAndJsonColumnTypes(): void
    {
        $fields = new FieldDTOCollection([
            new FieldDTO('vc_short', null, null, '', 'varchar(242)', ''),
            new FieldDTO('vc_long', null, null, '', 'varchar(1024)', ''),
            new FieldDTO('ch', null, null, '', 'char(12)', ''),
            new FieldDTO('blb', null, null, '', 'blob', ''),
            new FieldDTO('geom', null, null, '', 'geometry', ''),
            new FieldDTO('enm_ok', null, null, '', "enum('a','ba','c')", ''),
            new FieldDTO('enm_oob', null, null, '', "enum('a','ba','c')", ''),
            new FieldDTO('st', null, null, '', "set('a','ba','c')", ''),
            new FieldDTO('b6', null, null, '', 'bit(6)', ''),
            new FieldDTO('b12', null, null, '', 'bit(12)', ''),
            new FieldDTO('b64', null, null, '', 'bit(64)', ''),
            new FieldDTO('js', null, null, '', 'json', ''),
        ]);

        $columnTypes = chr(ConstFieldType::VARCHAR)
            . chr(ConstFieldType::VARCHAR)
            . chr(ConstFieldType::STRING)
            . chr(ConstFieldType::BLOB)
            . chr(ConstFieldType::GEOMETRY)
            . chr(ConstFieldType::STRING)
            . chr(ConstFieldType::STRING)
            . chr(ConstFieldType::STRING)
            . chr(ConstFieldType::BIT)
            . chr(ConstFieldType::BIT)
            . chr(ConstFieldType::BIT)
            . chr(ConstFieldType::JSON);

        $metadata = pack('v', 242)
            . pack('v', 1024)
            . "\xfe\x0c"
            . "\x02"
            . "\x04"
            . chr(ConstFieldType::ENUM) . "\x01"
            . chr(ConstFieldType::ENUM) . "\x01"
            . chr(ConstFieldType::SET) . "\x01"
            . "\x06\x00"
            . "\x04\x01"
            . "\x00\x08"
            . "\x04";

        $longVarchar = str_repeat('-', 300);
        $wkb = hex2bin('000000000101000000000000000000f03f000000000000f03f');
        self::assertIsString($wkb);
        $bit64 = hex2bin('d6b49e3514bbbada');
        self::assertIsString($bit64);
        // Binary JSON for {"a":2}: type 0 (small object), count 1, size 12, key offset 11, key len 1, value INT16 2, key "a".
        $jsonBinary = "\x00\x01\x00\x0c\x00\x0b\x00\x01\x00\x05\x02\x00a";

        $rowPayload = "\x05Hello"
            . pack('v', strlen($longVarchar)) . $longVarchar
            . "\x05World"
            . pack('v', 5) . 'bytes'
            . pack('V', strlen($wkb)) . $wkb
            . "\x02"
            . "\x00"
            . "\x05"
            . "\x22"
            . "\x08\xad"
            . $bit64
            . pack('V', strlen($jsonBinary)) . $jsonBinary;

        $dto = $this->decodeSingleRow($fields, $columnTypes, $metadata, "\xff\x0f\x00\x00", $rowPayload);
        $row = $dto->values[0];

        self::assertSame('Hello', $row['vc_short']);
        self::assertSame($longVarchar, $row['vc_long']);
        self::assertSame('World', $row['ch']);
        self::assertSame('bytes', $row['blb']);
        self::assertSame($wkb, $row['geom']);
        self::assertSame('ba', $row['enm_ok']);
        self::assertSame('', $row['enm_oob']);
        self::assertSame(['a', 'c'], $row['st']);
        self::assertSame('100010', $row['b6']);
        self::assertSame('100010101101', $row['b12']);
        self::assertSame('1101011010110100100111100011010100010100101110111011101011011010', $row['b64']);
        self::assertSame('{"a":2}', $row['js']);
    }

    public function testDecodeThrowsOnUnknownFieldType(): void
    {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->willReturn(new FieldDTOCollection([new FieldDTO('bad', null, null, '', 'newdate', '')]));

        $decoder = new RowEventDecoder($repository, new ArrayCache(10));
        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x03bad\x00\x01"
            . chr(ConstFieldType::NEWDATE) . "\x00";
        $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), new BinLogCurrent()),
            new BinaryDataReader($tableMapBody)
        );

        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00\x01\x01\x00\x00";
        $this->expectException(MySQLReplicationException::class);
        $this->expectExceptionMessage('Unknown row type: ' . ConstFieldType::NEWDATE);

        $decoder->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );
    }

    private function decodeSingleRow(
        FieldDTOCollection $fields,
        string $columnTypes,
        string $metadata,
        string $bitmapAndNullPrefix,
        string $rowPayload
    ): RowsDTO {
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->method('getFields')
            ->willReturn($fields);
        $decoder = new RowEventDecoder($repository, new ArrayCache(10));

        $columnCount = $fields->count();
        $tableMapBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00\x05types\x00"
            . chr($columnCount)
            . $columnTypes
            . chr(strlen($metadata))
            . $metadata;

        $decoder->decode(
            $this->makeEventInfo(ConstEventType::TABLE_MAP_EVENT, strlen($tableMapBody), new BinLogCurrent()),
            new BinaryDataReader($tableMapBody)
        );

        $rowBody = "\x07\x00\x00\x00\x00\x00\x00\x00\x02\x00"
            . chr($columnCount)
            . $bitmapAndNullPrefix
            . $rowPayload;

        $dto = $decoder->decode(
            $this->makeEventInfo(ConstEventType::WRITE_ROWS_EVENT_V2, strlen($rowBody), new BinLogCurrent()),
            new BinaryDataReader($rowBody)
        );

        self::assertInstanceOf(WriteRowsDTO::class, $dto);
        return $dto;
    }

    private function makeReader(string $body, bool $headerConsumed, bool $checksum): BinaryDataReader
    {
        $trailer = $checksum ? "\xde\xad\xbe\xef" : '';
        if ($headerConsumed) {
            $reader = new BinaryDataReader(str_repeat("\x00", 20) . $body . $trailer);
            $reader->advance(20);
            return $reader;
        }

        return new BinaryDataReader($body . $trailer);
    }

    private function createSpyLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                ];
            }
        };
    }

    private function makeEventInfo(
        ConstEventType $type,
        int $bodyLength,
        BinLogCurrent $current,
        bool $checksum = false
    ): EventInfo {
        return new EventInfo(
            1700000000,
            $type->value,
            42,
            19 + $bodyLength + ($checksum ? 4 : 0),
            '0',
            1,
            $checksum,
            $current
        );
    }
}
