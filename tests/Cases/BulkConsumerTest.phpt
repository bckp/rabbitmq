<?php

declare(strict_types=1);

namespace Mallgroup\RabbitMQ\Tests\Cases;

use Bunny\Client;
use Bunny\Message;
use Mallgroup\RabbitMQ\Connection\ConnectionFactory;
use Mallgroup\RabbitMQ\Connection\IConnection;
use Mallgroup\RabbitMQ\Consumer\BulkConsumer;
use Mallgroup\RabbitMQ\Consumer\Exception\UnexpectedConsumerResultTypeException;
use Mallgroup\RabbitMQ\Consumer\IConsumer;
use Mallgroup\RabbitMQ\Exchange\ExchangeDeclarator;
use Mallgroup\RabbitMQ\Exchange\ExchangesDataBag;
use Mallgroup\RabbitMQ\LazyDeclarator;
use Mallgroup\RabbitMQ\Queue\IQueue;
use Mallgroup\RabbitMQ\Queue\QueueDeclarator;
use Mallgroup\RabbitMQ\Queue\QueuesDataBag;
use Mallgroup\RabbitMQ\Tests\Mocks\ChannelMock;
use Mallgroup\RabbitMQ\Tests\Mocks\QueueMock;
use Tester\Assert;
use Tester\TestCase;

require __DIR__ . '/../bootstrap.php';

final class BulkConsumerTest extends TestCase
{

	public function testConsumeMessagesToLimit(): void
	{
		$client = $this->createClient();

		$channelMock = new ChannelMock();
		$channelMock->setClient($client);

		$connectionMock = \Mockery::mock(IConnection::class)
			->shouldReceive('getChannel')->andReturn($channelMock)->getMock();

		$queueMock = \Mockery::mock(IQueue::class)
			->shouldReceive('getConnection')->andReturn($connectionMock)->getMock()
			->shouldReceive('getName')->andReturn('testQueue')->getMock();

		$countOfConsumerCallbackCalls = 0;
		$callback = function ($messages) use (&$countOfConsumerCallbackCalls) {
			$countOfConsumerCallbackCalls++;
			return array_map(fn($message) => IConsumer::MESSAGE_ACK, $messages);
		};

		$instance = new BulkConsumer($this->createLazyDeclarator(), 'bulkTest', $queueMock, $callback, null, null, 3, 2);

		$instance->consume(2);

		Assert::same(2, $countOfConsumerCallbackCalls, 'Number of consumer callback calls');
		Assert::count(2, $channelMock->acks, 'Number of ACKs');
		Assert::same([
			1 => [
				1 => '{"test":"1"}',
				2 => '{"test":"2"}',
				3 => '{"test":"3"}',
			],
			2 => [
				4 => '{"test":"4"}',
				5 => '{"test":"5"}',
			]
		], $channelMock->acks, 'ACKs data');
	}

	public function testConsumeMessagesException(): void
	{
		$client = $this->createClient();

		$channelMock = new ChannelMock();
		$channelMock->setClient($client);

		$connectionMock = \Mockery::mock(IConnection::class)
			->shouldReceive('getChannel')->andReturn($channelMock)->getMock();

		$queueMock = \Mockery::mock(IQueue::class)
			->shouldReceive('getConnection')->andReturn($connectionMock)->getMock()
			->shouldReceive('getName')->andReturn('testQueue')->getMock();

		$countOfConsumerCallbackCalls = 0;
		$callback = function ($messages) use (&$countOfConsumerCallbackCalls) {
			$countOfConsumerCallbackCalls++;
			throw new \Exception("test");
		};

		$instance = new BulkConsumer($this->createLazyDeclarator(), 'bulkTest', $queueMock, $callback, null, null, 3, 2);

		$instance->consume(2);

		Assert::same(2, $countOfConsumerCallbackCalls, 'Number of consumer callback calls');
		Assert::count(2, $channelMock->nacks, 'Number of NACKs');
		Assert::same([
			1 => [
				1 => '{"test":"1"}',
				2 => '{"test":"2"}',
				3 => '{"test":"3"}',
			],
			2 => [
				4 => '{"test":"4"}',
				5 => '{"test":"5"}',
			]
		], $channelMock->nacks, 'NACKs data');
	}

	public function testConsumeMessagesBadResult(): void
	{
		$client = $this->createClient();

		$channelMock = new ChannelMock();
		$channelMock->setClient($client);

		$connectionMock = \Mockery::mock(IConnection::class)
			->shouldReceive('getChannel')->andReturn($channelMock)->getMock();

		$queueMock = \Mockery::mock(IQueue::class)
			->shouldReceive('getConnection')->andReturn($connectionMock)->getMock()
			->shouldReceive('getName')->andReturn('testQueue')->getMock();

		$countOfConsumerCallbackCalls = 0;
		$callback = function ($messages) use (&$countOfConsumerCallbackCalls) {
			$countOfConsumerCallbackCalls++;
			return true;
		};

		$instance = new BulkConsumer($this->createLazyDeclarator(), 'bulkTest', $queueMock, $callback, null, null, 3, 2);

		Assert::exception(fn () => $instance->consume(2), UnexpectedConsumerResultTypeException::class);

		Assert::same(1, $countOfConsumerCallbackCalls, 'Number of consumer callback calls');
		Assert::count(1, $channelMock->nacks, 'Number of NACKs');
		Assert::same([
			1 => [
				1 => '{"test":"1"}',
				2 => '{"test":"2"}',
				3 => '{"test":"3"}',
			]
		], $channelMock->nacks, 'NACKs data');
	}

	protected function createClient()
	{
		return new class([
			['key' => '1', 'content' => '{"test":"1"}'],
			['key' => '2', 'content' => '{"test":"2"}'],
			['key' => '3', 'content' => '{"test":"3"}'],
			['key' => '4', 'content' => '{"test":"4"}'],
			['key' => '5', 'content' => '{"test":"5"}'],
		]) extends Client {
			private array $dataToConsume;
			private $callback;
			private $channel;

			public function __construct($dataToConsume)
			{
				$this->dataToConsume = $dataToConsume;
			}

			public function setCallback($callback)
			{
				$this->callback = $callback;
			}

			public function setChannel($channel)
			{
				$this->channel = $channel;
			}

			public function disconnect($replyCode = 0, $replyText = "")
			{
			}

			protected function feedReadBuffer()
			{
			}

			protected function flushWriteBuffer()
			{
			}

			public function run($maxSeconds = null)
			{
				$this->channel->ackPos++;
				$this->channel->nackPos++;
				if (count($this->dataToConsume) > 0) {
					$this->running = true;
					do {
						$data = array_shift($this->dataToConsume);
						if ($data !== null) {
							call_user_func($this->callback, new Message($data['key'], $data['key'], false, 'bulkTest', '', [], $data['content']), $this->channel, $this);
						}
					} while ($this->running && $data !== null);
				}
			}
		};
	}

	protected function createLazyDeclarator(): LazyDeclarator
	{
		return new LazyDeclarator(
			\Mockery::spy(QueuesDataBag::class),
			\Mockery::spy(ExchangesDataBag::class),
			\Mockery::spy(QueueDeclarator::class),
			\Mockery::spy(ExchangeDeclarator::class),
			\Mockery::spy(ConnectionFactory::class),
		);
	}
}

(new BulkConsumerTest())->run();
