<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use DateTime;
use Exception;
use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\Config;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Definitions\ConstFieldType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\RowsDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\JsonBinaryDecoder\JsonBinaryDecoderService;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Repository\FieldDTOCollection;
use MySQLReplication\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

readonly class RowEventDecoder
{
    private const BIT_COUNT_IN_BYTE = [
        0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        4, 5, 5, 6, 5, 6, 6, 7, 5, 6, 6, 7, 6, 7, 7, 8,
    ];

    private CacheInterface $cache;
    private Config $config;

    public function __construct(
        private RepositoryInterface $repository,
        ?CacheInterface $cache = null,
        ?Config $config = null,
        private LoggerInterface $logger = new NullLogger()
    ) {
        $this->config = $config ?? (new ConfigBuilder())->build();
        $this->cache = $cache ?? new ArrayCache($this->config->tableCacheSize);
    }

    public function decode(EventInfo $eventInfo, BinaryDataReader $binaryDataReader): TableMapDTO|RowsDTO|null
    {
        $bodyEndBytes = $binaryDataReader->getReadBytes() + $eventInfo->getSizeNoHeader();

        return match ($eventInfo->type) {
            ConstEventType::TABLE_MAP_EVENT->value => $this->decodeTableMap($eventInfo, $binaryDataReader),
            ConstEventType::WRITE_ROWS_EVENT_V1->value,
            ConstEventType::WRITE_ROWS_EVENT_V2->value => $this->decodeRowChange(
                $eventInfo,
                $binaryDataReader,
                $bodyEndBytes,
                false,
                static fn (TableMap $tableMap, array $values): WriteRowsDTO => new WriteRowsDTO(
                    $eventInfo,
                    $tableMap,
                    count($values),
                    $values
                )
            ),
            ConstEventType::DELETE_ROWS_EVENT_V1->value,
            ConstEventType::DELETE_ROWS_EVENT_V2->value => $this->decodeRowChange(
                $eventInfo,
                $binaryDataReader,
                $bodyEndBytes,
                false,
                static fn (TableMap $tableMap, array $values): DeleteRowsDTO => new DeleteRowsDTO(
                    $eventInfo,
                    $tableMap,
                    count($values),
                    $values
                )
            ),
            ConstEventType::UPDATE_ROWS_EVENT_V1->value,
            ConstEventType::UPDATE_ROWS_EVENT_V2->value => $this->decodeRowChange(
                $eventInfo,
                $binaryDataReader,
                $bodyEndBytes,
                true,
                static fn (TableMap $tableMap, array $values): UpdateRowsDTO => new UpdateRowsDTO(
                    $eventInfo,
                    $tableMap,
                    count($values),
                    $values
                )
            ),
            default => throw new MySQLReplicationException('Unsupported row event type: ' . $eventInfo->type),
        };
    }

    public function clearTableMaps(): void
    {
        $this->cache->clear();
    }

    private function decodeTableMap(EventInfo $eventInfo, BinaryDataReader $binaryDataReader): ?TableMapDTO
    {
        $tableId = $binaryDataReader->readTableId();
        $binaryDataReader->advance(2);
        $schemaLength = $binaryDataReader->readUInt8();
        $schemaName = $binaryDataReader->read($schemaLength);

        if ($this->config->checkDataBasesOnly($schemaName)) {
            return null;
        }

        $binaryDataReader->advance(1);
        $tableLength = $binaryDataReader->readUInt8();
        $tableName = $binaryDataReader->read($tableLength);

        if ($this->config->checkTablesOnly($tableName)) {
            return null;
        }

        $binaryDataReader->advance(1);
        $columnsAmount = (int)$binaryDataReader->readCodedBinary();
        $columnTypes = $binaryDataReader->read($columnsAmount);

        $cachedTableMap = $this->getCachedTableMap($tableId);
        if ($cachedTableMap !== null) {
            return new TableMapDTO($eventInfo, $cachedTableMap);
        }

        $binaryDataReader->readCodedBinary();

        $fieldDTOCollection = $this->repository->getFields($schemaName, $tableName);
        $columnDTOCollection = $this->buildColumnCollection($fieldDTOCollection, $columnTypes, $binaryDataReader);

        $tableMap = new TableMap(
            $schemaName,
            $tableName,
            $tableId,
            $columnsAmount,
            $columnDTOCollection
        );

        $this->cache->set($tableId, $tableMap);

        return new TableMapDTO($eventInfo, $tableMap);
    }

    private function buildColumnCollection(
        FieldDTOCollection $fieldDTOCollection,
        string $columnTypes,
        BinaryDataReader $binaryDataReader
    ): ColumnDTOCollection {
        $columnDTOCollection = new ColumnDTOCollection();
        // if you drop tables and parse of logs you will get empty scheme
        if ($fieldDTOCollection->isEmpty()) {
            return $columnDTOCollection;
        }

        $columnLength = strlen($columnTypes);
        for ($offset = 0; $offset < $columnLength; ++$offset) {
            // this a dirty hack to prevent row events containing columns which have been dropped
            if ($fieldDTOCollection->offsetExists($offset)) {
                $type = ord($columnTypes[$offset]);
                $fieldDTO = $fieldDTOCollection->offsetGet($offset);
            } else {
                $type = ConstFieldType::IGNORE;
                $fieldDTO = FieldDTO::makeDummy($offset);
                $fieldDTOCollection->offsetSet($offset, $fieldDTO);
            }

            if ($fieldDTO) {
                $columnDTOCollection->set($offset, ColumnDTO::make($type, $fieldDTO, $binaryDataReader));
            }
        }

        return $columnDTOCollection;
    }

    private function getCachedTableMap(string $tableId): ?TableMap
    {
        $tableMap = $this->cache->get($tableId);

        return $tableMap instanceof TableMap ? $tableMap : null;
    }

    /**
     * @template T of RowsDTO
     * @param callable(TableMap, array): T $makeDto
     * @return T|null
     */
    private function decodeRowChange(
        EventInfo $eventInfo,
        BinaryDataReader $binaryDataReader,
        int $bodyEndBytes,
        bool $isUpdate,
        callable $makeDto
    ): ?RowsDTO {
        $tableMap = $this->findTableMap($eventInfo, $binaryDataReader);
        if ($tableMap === null) {
            return null;
        }

        if ($tableMap->columnDTOCollection->isEmpty()) {
            return $makeDto($tableMap, []);
        }

        $columnsBinarySize = $this->getColumnsBinarySize($tableMap->columnsAmount);
        if ($isUpdate) {
            $beforeBinaryData = $binaryDataReader->read($columnsBinarySize);
            $afterBinaryData = $binaryDataReader->read($columnsBinarySize);
            $values = [];
            while ($binaryDataReader->getReadBytes() < $bodyEndBytes) {
                $values[] = [
                    'before' => $this->getColumnData($tableMap, $beforeBinaryData, $binaryDataReader),
                    'after' => $this->getColumnData($tableMap, $afterBinaryData, $binaryDataReader),
                ];
            }

            return $makeDto($tableMap, $values);
        }

        $colsBitmap = $binaryDataReader->read($columnsBinarySize);
        $values = [];
        while ($binaryDataReader->getReadBytes() < $bodyEndBytes) {
            $values[] = $this->getColumnData($tableMap, $colsBitmap, $binaryDataReader);
        }

        return $makeDto($tableMap, $values);
    }

    private function getColumnsBinarySize(int $columnsAmount): int
    {
        return (int)(($columnsAmount + 7) / 8);
    }

    private function getColumnData(
        TableMap $tableMap,
        string $colsBitmap,
        BinaryDataReader $binaryDataReader
    ): array {
        $values = [];

        // null bitmap length = (bits set in 'columns-present-bitmap'+7)/8
        // see http://dev.mysql.com/doc/internals/en/rows-event.html
        $nullBitmap = $binaryDataReader->read($this->getColumnsBinarySize($this->bitCount($colsBitmap)));
        $nullBitmapIndex = 0;

        foreach ($tableMap->columnDTOCollection as $i => $columnDTO) {
            $name = $columnDTO->getName();

            if ($this->bitGet($colsBitmap, $i) === 0) {
                $values[$name] = null;
                continue;
            }

            if ($this->checkNull($nullBitmap, $nullBitmapIndex)) {
                $values[$name] = null;
            } else {
                $values[$name] = $this->decodeColumnValue($columnDTO, $binaryDataReader);
            }

            ++$nullBitmapIndex;
        }

        return $values;
    }

    private function decodeColumnValue(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): mixed
    {
        return match ($columnDTO->type) {
            ConstFieldType::IGNORE => $this->skipIgnoredColumn($columnDTO, $binaryDataReader),
            ConstFieldType::TINY => $columnDTO->isUnsigned()
                ? $binaryDataReader->readUInt8()
                : $binaryDataReader->readInt8(),
            ConstFieldType::SHORT => $columnDTO->isUnsigned()
                ? $binaryDataReader->readUInt16()
                : $binaryDataReader->readInt16(),
            ConstFieldType::LONG => $columnDTO->isUnsigned()
                ? $binaryDataReader->readUInt32()
                : $binaryDataReader->readInt32(),
            ConstFieldType::LONGLONG => $columnDTO->isUnsigned()
                ? $binaryDataReader->readUInt64()
                : $binaryDataReader->readInt64(),
            ConstFieldType::INT24 => $columnDTO->isUnsigned()
                ? $binaryDataReader->readUInt24()
                : $binaryDataReader->readInt24(),
            ConstFieldType::FLOAT => round($binaryDataReader->readFloat(), 4),
            ConstFieldType::DOUBLE => $binaryDataReader->readDouble(),
            ConstFieldType::VARCHAR,
            ConstFieldType::STRING => $this->getString(
                $columnDTO->maxLength > 255 ? 2 : 1,
                $binaryDataReader
            ),
            ConstFieldType::NEWDECIMAL => $this->getDecimal($columnDTO, $binaryDataReader),
            ConstFieldType::BLOB,
            ConstFieldType::GEOMETRY => $this->getString($columnDTO->lengthSize, $binaryDataReader),
            ConstFieldType::DATETIME => $this->getDatetime($binaryDataReader),
            ConstFieldType::DATETIME2 => $this->getDatetime2($columnDTO, $binaryDataReader),
            ConstFieldType::TIMESTAMP => date('Y-m-d H:i:s', $binaryDataReader->readUInt32()),
            ConstFieldType::TIME => $this->getTime($binaryDataReader),
            ConstFieldType::TIME2 => $this->getTime2($columnDTO, $binaryDataReader),
            ConstFieldType::TIMESTAMP2 => $this->getTimestamp2($columnDTO, $binaryDataReader),
            ConstFieldType::DATE => $this->getDate($binaryDataReader),
            ConstFieldType::YEAR => $this->getYear($binaryDataReader),
            ConstFieldType::ENUM => $this->getEnum($columnDTO, $binaryDataReader),
            ConstFieldType::SET => $this->getSet($columnDTO, $binaryDataReader),
            ConstFieldType::BIT => $this->getBit($columnDTO, $binaryDataReader),
            ConstFieldType::JSON => JsonBinaryDecoderService::makeJsonBinaryDecoder(
                $this->getString($columnDTO->lengthSize, $binaryDataReader)
            )->parseToString(),
            default => throw new MySQLReplicationException('Unknown row type: ' . $columnDTO->type),
        };
    }

    private function skipIgnoredColumn(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): mixed
    {
        $binaryDataReader->advance($columnDTO->lengthSize);

        return null;
    }

    private function getString(int $size, BinaryDataReader $binaryDataReader): string
    {
        return $binaryDataReader->readLengthString($size);
    }

    /**
     * Read MySQL's new decimal format introduced in MySQL 5
     * https://dev.mysql.com/doc/refman/5.6/en/precision-math-decimal-characteristics.html
     */
    private function getDecimal(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $digitsPerInteger = 9;
        $compressedBytes = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
        $integral = $columnDTO->precision - $columnDTO->decimals;
        $unCompIntegral = (int)($integral / $digitsPerInteger);
        $unCompFractional = (int)($columnDTO->decimals / $digitsPerInteger);
        $compIntegral = $integral - ($unCompIntegral * $digitsPerInteger);
        $compFractional = $columnDTO->decimals - ($unCompFractional * $digitsPerInteger);

        $value = $binaryDataReader->readUInt8();
        if (0 !== ($value & 0x80)) {
            $mask = 0;
            $res = '';
        } else {
            $mask = -1;
            $res = '-';
        }
        $binaryDataReader->unread(pack('C', $value ^ 0x80));

        $size = $compressedBytes[$compIntegral];
        if ($size > 0) {
            $value = $binaryDataReader->readIntBeBySize($size) ^ $mask;
            $res .= $value;
        }

        for ($i = 0; $i < $unCompIntegral; ++$i) {
            $value = $binaryDataReader->readInt32Be() ^ $mask;
            $res .= sprintf('%09d', $value);
        }

        $res .= '.';

        for ($i = 0; $i < $unCompFractional; ++$i) {
            $value = $binaryDataReader->readInt32Be() ^ $mask;
            $res .= sprintf('%09d', $value);
        }

        $size = $compressedBytes[$compFractional];
        if ($size > 0) {
            $value = $binaryDataReader->readIntBeBySize($size) ^ $mask;
            $res .= sprintf('%0' . $compFractional . 'd', $value);
        }

        return bcmul($res, '1', $columnDTO->decimals);
    }

    private function getDatetime(BinaryDataReader $binaryDataReader): ?string
    {
        $value = $binaryDataReader->readUInt64();
        // nasty mysql 0000-00-00 dates
        if ($value === '0') {
            return null;
        }

        return $this->dateFormatter($value);
    }

    /**
     * Date Time
     * 1 bit  sign           (1= non-negative, 0= negative)
     * 17 bits year*13+month  (year 0-9999, month 0-12)
     * 5 bits day            (0-31)
     * 5 bits hour           (0-23)
     * 6 bits minute         (0-59)
     * 6 bits second         (0-59)
     * ---------------------------
     * 40 bits = 5 bytes
     *
     * @link https://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
     */
    private function getDatetime2(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): ?string
    {
        $data = $binaryDataReader->readIntBeBySize(5);

        $yearMonth = $binaryDataReader->getBinarySlice($data, 1, 17, 40);

        $year = (int)($yearMonth / 13);
        $month = $yearMonth % 13;
        $day = $binaryDataReader->getBinarySlice($data, 18, 5, 40);
        $hour = $binaryDataReader->getBinarySlice($data, 23, 5, 40);
        $minute = $binaryDataReader->getBinarySlice($data, 28, 6, 40);
        $second = $binaryDataReader->getBinarySlice($data, 34, 6, 40);
        $fsp = $this->getFSP($columnDTO, $binaryDataReader);

        $formattedDate = $this->dateFormatter(
            $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':' . $second
        );
        if ($formattedDate !== null) {
            if ((int)$fsp > 0) {
                return vsprintf('%s.%06u', [$formattedDate, $fsp]);
            }

            return $formattedDate;
        }

        return null;
    }

    private function dateFormatter(string $date): ?string
    {
        try {
            $dateTime = new DateTime($date);
        } catch (Exception) {
            $dateTime = DateTime::createFromFormat('YmdHis', $date);
            if ($dateTime === false) {
                return null;
            }
        }

        $formattedDate = $dateTime->format('Y-m-d H:i:s');
        if (DateTime::getLastErrors() !== false) {
            return null;
        }

        return $formattedDate;
    }

    /**
     * @link https://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
     */
    private function getFSP(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $read = 0;
        $time = '';
        $fsp = $columnDTO->fsp;
        if ($fsp === 1 || $fsp === 2) {
            $read = 1;
        } elseif ($fsp === 3 || $fsp === 4) {
            $read = 2;
        } elseif ($fsp === 5 || $fsp === 6) {
            $read = 3;
        }
        if ($read > 0) {
            $microsecond = $binaryDataReader->readIntBeBySize($read);
            if ($fsp % 2) {
                $microsecond = (int)($microsecond / 10);
            }
            $time = $microsecond * (10 ** (6 - $fsp));
        }

        return (string)$time;
    }

    private function getTime(BinaryDataReader $binaryDataReader): string
    {
        $data = $binaryDataReader->readUInt24();
        if ($data === 0) {
            return '00:00:00';
        }

        return sprintf('%s%02d:%02d:%02d', $data < 0 ? '-' : '', $data / 10000, ($data % 10000) / 100, $data % 100);
    }

    /**
     * TIME encoding for non-fractional part:
     * 1 bit sign    (1= non-negative, 0= negative)
     * 1 bit unused  (reserved for future extensions)
     * 10 bits hour   (0-838)
     * 6 bits minute (0-59)
     * 6 bits second (0-59)
     * ---------------------
     * 24 bits = 3 bytes
     */
    private function getTime2(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $data = $binaryDataReader->readInt24Be();

        $hour = $binaryDataReader->getBinarySlice($data, 2, 10, 24);
        $minute = $binaryDataReader->getBinarySlice($data, 12, 6, 24);
        $second = $binaryDataReader->getBinarySlice($data, 18, 6, 24);

        return (new DateTime())
            ->setTime($hour, $minute, $second)
            ->format('H:i:s') . $this->getFSP($columnDTO, $binaryDataReader);
    }

    private function getTimestamp2(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $datetime = date('Y-m-d H:i:s', $binaryDataReader->readInt32Be());
        $fsp = $this->getFSP($columnDTO, $binaryDataReader);
        if ($fsp !== '') {
            $datetime .= '.' . $fsp;
        }

        return $datetime;
    }

    private function getDate(BinaryDataReader $binaryDataReader): ?string
    {
        $time = $binaryDataReader->readUInt24();
        if ($time === 0) {
            return null;
        }

        $year = ($time & ((1 << 15) - 1) << 9) >> 9;
        $month = ($time & ((1 << 4) - 1) << 5) >> 5;
        $day = ($time & ((1 << 5) - 1));
        if ($year === 0 || $month === 0 || $day === 0) {
            return null;
        }

        return (new DateTime())->setDate($year, $month, $day)
            ->format('Y-m-d');
    }

    private function getYear(BinaryDataReader $binaryDataReader): ?int
    {
        // https://dev.mysql.com/doc/refman/5.7/en/year.html
        $year = $binaryDataReader->readUInt8();

        return $year === 0 ? null : 1900 + $year;
    }

    private function getEnum(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $value = $binaryDataReader->readUIntBySize($columnDTO->size) - 1;
        $enumValues = $columnDTO->getEnumValues();

        // check if given value exists in enums, if there not existing enum mysql returns empty string.
        if (array_key_exists($value, $enumValues)) {
            return $enumValues[$value];
        }

        return '';
    }

    private function getSet(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): array
    {
        // we read set columns as a bitmap telling us which options are enabled
        $bitMask = $binaryDataReader->readUIntBySize($columnDTO->size);
        $sets = [];
        foreach ($columnDTO->getSetValues() as $k => $item) {
            if ($bitMask & (2 ** $k)) {
                $sets[] = $item;
            }
        }

        return $sets;
    }

    private function getBit(ColumnDTO $columnDTO, BinaryDataReader $binaryDataReader): string
    {
        $res = '';
        for ($byte = 0; $byte < $columnDTO->bytes; ++$byte) {
            $currentByte = '';
            $data = $binaryDataReader->readUInt8();
            if ($byte === 0) {
                if ($columnDTO->bytes === 1) {
                    $end = $columnDTO->bits;
                } else {
                    $end = $columnDTO->bits % 8;
                    if ($end === 0) {
                        $end = 8;
                    }
                }
            } else {
                $end = 8;
            }

            for ($bit = 0; $bit < $end; ++$bit) {
                if ($data & (1 << $bit)) {
                    $currentByte .= '1';
                } else {
                    $currentByte .= '0';
                }
            }
            $res .= strrev($currentByte);
        }

        return $res;
    }

    private function bitCount(string $bitmap): int
    {
        $n = 0;
        $bitmapLength = strlen($bitmap);
        for ($i = 0; $i < $bitmapLength; ++$i) {
            $n += self::BIT_COUNT_IN_BYTE[ord($bitmap[$i])];
        }

        return $n;
    }

    private function bitGet(string $bitmap, int $position): int
    {
        return $this->getBitFromBitmap($bitmap, $position) & (1 << ($position & 7));
    }

    private function getBitFromBitmap(string $bitmap, int $position): int
    {
        return ord($bitmap[(int)($position / 8)]);
    }

    private function checkNull(string $nullBitmap, int $position): int
    {
        return $this->getBitFromBitmap($nullBitmap, $position) & (1 << ($position % 8));
    }

    private function findTableMap(EventInfo $eventInfo, BinaryDataReader $binaryDataReader): ?TableMap
    {
        $tableId = $binaryDataReader->readTableId();
        $binaryDataReader->advance(2);

        if (in_array(
            $eventInfo->type,
            [
                ConstEventType::DELETE_ROWS_EVENT_V2->value,
                ConstEventType::WRITE_ROWS_EVENT_V2->value,
                ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ],
            true
        )) {
            $binaryDataReader->read((int)($binaryDataReader->readUInt16() / 8));
        }

        $binaryDataReader->readCodedBinary();

        $tableMap = $this->getCachedTableMap($tableId);
        if ($tableMap !== null) {
            return $tableMap;
        }

        $this->logger->debug('No table map found for table ID: ' . $tableId);

        return null;
    }
}
