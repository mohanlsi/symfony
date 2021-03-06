<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\Transport\RedisExt;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\RedisExt\Connection;

/**
 * @requires extension redis
 */
class ConnectionTest extends TestCase
{
    public function testFromInvalidDsn()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The given Redis DSN "redis://" is invalid.');

        Connection::fromDsn('redis://');
    }

    public function testFromDsn()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue'], [
                'host' => 'localhost',
                'port' => 6379,
            ]),
            Connection::fromDsn('redis://localhost/queue')
        );
    }

    public function testFromDsnWithOptions()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue', 'group' => 'group1', 'consumer' => 'consumer1'], [
                'host' => 'localhost',
                'port' => 6379,
            ], [
                'blocking_timeout' => 30,
            ]),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1', ['blocking_timeout' => 30])
        );
    }

    public function testFromDsnWithQueryOptions()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue', 'group' => 'group1', 'consumer' => 'consumer1'], [
                'host' => 'localhost',
                'port' => 6379,
            ], [
                'blocking_timeout' => 30,
            ]),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1?blocking_timeout=30')
        );
    }

    public function testKeepGettingPendingMessages()
    {
        $redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();

        $redis->expects($this->exactly(3))->method('xreadgroup')
            ->with('symfony', 'consumer', ['queue' => 0], 1, null)
            ->willReturn(['queue' => [['message' => json_encode(['body' => 'Test', 'headers' => []])]]]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $this->assertNotNull($connection->get());
        $this->assertNotNull($connection->get());
        $this->assertNotNull($connection->get());
    }

    public function testFirstGetPendingMessagesThenNewMessages()
    {
        $redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();

        $count = 0;

        $redis->expects($this->exactly(2))->method('xreadgroup')
            ->with('symfony', 'consumer', $this->callback(function ($arr_streams) use (&$count) {
                ++$count;

                if (1 === $count) {
                    return '0' === $arr_streams['queue'];
                }

                return '>' === $arr_streams['queue'];
            }), 1, null)
            ->willReturn(['queue' => []]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $connection->get();
    }

    public function testUnexpectedRedisError()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Redis error happens');
        $redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();
        $redis->expects($this->once())->method('xreadgroup')->willReturn(false);
        $redis->expects($this->once())->method('getLastError')->willReturn('Redis error happens');

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $connection->get();
    }
}
