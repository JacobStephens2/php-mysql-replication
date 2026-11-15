<?php

declare(strict_types=1);

namespace MySQLReplication\Event;

use MySQLReplication\BinLog\BinLogSocketConnect;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class Event
{
    public function __construct(
        private BinLogSocketConnect $binLogSocketConnect,
        private EventDecoder $eventDecoder,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function consume(): void
    {
        $eventDTO = $this->eventDecoder->decode($this->binLogSocketConnect->getResponse());
        if ($eventDTO !== null) {
            $this->eventDispatcher->dispatch($eventDTO, $eventDTO->getType());
        }
    }
}
