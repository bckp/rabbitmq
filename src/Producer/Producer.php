<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Producer;

use Bunny\Exception\ClientException;
use Bunny\Protocol\MethodBasicNackFrame;
use Bckp\RabbitMQ\Connection\Client;
use Bckp\RabbitMQ\Connection\Exception\PublishException;
use Bckp\RabbitMQ\Connection\Exception\WaitTimeoutException;
use Bckp\RabbitMQ\Exchange\IExchange;
use Bckp\RabbitMQ\LazyDeclarator;
use Bckp\RabbitMQ\Queue\IQueue;
use Exception;

final class Producer implements IProducer
{
	/**
	 * @var callable[]
	 */
	private array $publishCallbacks = [];

	public function __construct(
		private ?IExchange $exchange,
		private ?IQueue $queue,
		private string $contentType,
		private int $deliveryMode,
		private LazyDeclarator $lazyDeclarator
	) {
	}

	/**
	 * @param array<string, scalar|null|\DateTimeInterface|array<string, scalar|null|\DateTimeInterface>> $headers
	 * @throws Exception
	 */
	public function publish(string $message, array $headers = [], ?string $routingKey = null): void
	{
		$headers = array_merge($this->getBasicHeaders(), $headers);

		if ($this->queue !== null) {
			$this->tryPublish($this->queue, $message, $headers, '', $this->queue->getName());
		}

		if ($this->exchange !== null) {
			$this->tryPublish($this->exchange, $message, $headers, $this->exchange->getName(), $routingKey ?? '');
		}

		foreach ($this->publishCallbacks as $callback) {
			($callback)($message, $headers, $routingKey);
		}
	}


	public function addOnPublishCallback(callable $callback): void
	{
		$this->publishCallbacks[] = $callback;
	}


	/**
	 * @return array<string, string|int>
	 */
	private function getBasicHeaders(): array
	{
		return [
			'content-type' => $this->contentType,
			'delivery-mode' => $this->deliveryMode,
		];
	}


	/**
	 * @param array<string, scalar|null|\DateTimeInterface|array<string, scalar|null|\DateTimeInterface>> $headers
	 * @throws Exception
	 */
	private function tryPublish(IQueue|IExchange $target, string $message, array $headers, string $exchange, string $routingKey, int $try = 0): void
	{
		try {
			$deliveryTag = $target->getConnection()->getChannel()->publish(
				$message,
				$headers,
				$exchange,
				$routingKey
			);

			if ($target->getConnection()->isPublishConfirm()) {
				$client = $target->getConnection()->getChannel()->getClient();
				if (!$client instanceof Client) {
					return;
				}

				$frame = $client->waitForConfirm($target->getConnection()->getChannel()->getChannelId(), $target->getConnection()->getPublishConfirm());
				if ($frame instanceof MethodBasicNackFrame && $frame->deliveryTag === $deliveryTag) {
					throw new PublishException("Publish of message failed.\nExchange:{$exchange}\nRoutingKey:{$routingKey}");
				}
			}
		} catch (WaitTimeoutException $e) {
			throw new PublishException(
				"Confirm message timeout.\nExchange:{$exchange}\nRoutingKey:{$routingKey}\n",
				previous: $e
			);
		} catch (ClientException $e) {
			if ($try >= 2) {
				throw $e;
			}

			// Exchange do not exists, lazy declare
			if ($e->getCode() === 404) {
				$this->lazyDeclarator->declare();
				$this->tryPublish($target, $message, $headers, $exchange, $routingKey, ++$try);
				return;
			}

			// Try to reset connection if issue is broken pipe or closed connection
			if (in_array(
				$e->getMessage(),
				['Broken pipe or closed connection.', 'Could not write data to socket.'],
				true
			)) {
				$target->getConnection()->resetChannel();
				$target->getConnection()->resetConnection();

				$this->tryPublish($target, $message, $headers, $exchange, $routingKey, ++$try);
				return;
			}

			throw $e;
		}
	}
}
