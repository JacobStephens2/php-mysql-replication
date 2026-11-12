<?php

declare(strict_types=1);

namespace MySQLReplication\Event;

use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\Config;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class Event
{
    private EventDecoder $eventDecoder;

    public function __construct(
        private BinLogSocketConnect $binLogSocketConnect,
        RowEventFactory $rowEventFactory,
        private EventDispatcherInterface $eventDispatcher,
        CacheInterface $cache,
        Config $config,
        BinLogServerInfo $binLogServerInfo
    ) {
        $this->eventDecoder = new EventDecoder(
            $rowEventFactory,
            $cache,
            $config,
            $binLogServerInfo,
            $binLogSocketConnect->getCheckSum(),
            $binLogSocketConnect->getBinLogCurrent()
        );
    }

    public function consume(): void
    {
        $eventDTO = $this->eventDecoder->decode($this->binLogSocketConnect->getResponse());
        if ($eventDTO !== null) {
            $this->eventDispatcher->dispatch($eventDTO, $eventDTO->getType());
        }
    }
}
