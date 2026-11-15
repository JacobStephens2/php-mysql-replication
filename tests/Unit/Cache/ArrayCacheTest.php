<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Cache;

use MySQLReplication\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

class ArrayCacheTest extends TestCase
{
    private ArrayCache $arrayCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrayCache = new ArrayCache();
    }

    public function testShouldGet(): void
    {
        $this->arrayCache->set('foo', 'bar');
        self::assertSame('bar', $this->arrayCache->get('foo'));
    }

    public function testShouldSet(): void
    {
        $this->arrayCache->set('foo', 'bar');
        self::assertSame('bar', $this->arrayCache->get('foo'));
    }

    public function testShouldClearCacheOnSet(): void
    {
        $cache = new ArrayCache(2);

        $cache->set('a', '1');
        $cache->set('b', '2');
        $cache->set('c', '3');
        $cache->set('d', '4');

        self::assertFalse($cache->has('a'));
        self::assertSame('2', $cache->get('b'));
        self::assertSame('3', $cache->get('c'));
        self::assertSame('4', $cache->get('d'));
    }

    public function testShouldIsolateWritesAndClearAcrossInstances(): void
    {
        $first = new ArrayCache(10);
        $second = new ArrayCache(10);

        $first->set('table_1', 'first-value');
        self::assertFalse($second->has('table_1'));
        self::assertNull($second->get('table_1'));

        $second->set('table_1', 'second-value');
        $second->set('table_2', 'only-second');

        self::assertSame('first-value', $first->get('table_1'));
        self::assertFalse($first->has('table_2'));

        $first->clear();

        self::assertFalse($first->has('table_1'));
        self::assertSame('second-value', $second->get('table_1'));
        self::assertSame('only-second', $second->get('table_2'));
    }

    public function testShouldDelete(): void
    {
        $this->arrayCache->set('foo', 'bar');
        $this->arrayCache->delete('foo');
        self::assertNull($this->arrayCache->get('foo'));
    }

    public function testShouldClear(): void
    {
        $this->arrayCache->set('foo', 'bar');
        $this->arrayCache->set('foo1', 'bar1');
        $this->arrayCache->clear();
        self::assertNull($this->arrayCache->get('foo'));
    }

    public function testShouldGetMultiple(): void
    {
        $expect = [
            'foo' => 'bar',
            'foo1' => 'bar1',
        ];
        $this->arrayCache->setMultiple($expect);
        self::assertSame([
            'foo' => 'bar',
        ], $this->arrayCache->getMultiple(['foo']));
    }

    public function testShouldSetMultiple(): void
    {
        $expect = [
            'foo' => 'bar',
            'foo1' => 'bar1',
        ];
        $this->arrayCache->setMultiple($expect);
        self::assertSame($expect, $this->arrayCache->getMultiple(['foo', 'foo1']));
    }

    public function testShouldDeleteMultiple(): void
    {
        $expect = [
            'foo' => 'bar',
            'foo1' => 'bar1',
            'foo2' => 'bar2',
        ];
        $this->arrayCache->setMultiple($expect);
        $this->arrayCache->deleteMultiple(['foo', 'foo1']);
        self::assertSame([
            'foo2' => 'bar2',
        ], $this->arrayCache->getMultiple(['foo2']));
    }

    public function testShouldHas(): void
    {
        self::assertFalse($this->arrayCache->has('foo'));
        $this->arrayCache->set('foo', 'bar');
        self::assertTrue($this->arrayCache->has('foo'));
    }
}
