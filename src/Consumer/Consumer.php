<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Consumer;

use Bunny\Channel;
use Bunny\Client;
use Bunny\Exception\ClientException;
use Bunny\Message;
use Bckp\RabbitMQ\LazyDeclarator;
use Bckp\RabbitMQ\Queue\IQueue;

class Consumer
{

	/**
	 * @var callable
	 */
	protected $callback;
	protected int $messages = 0;
	protected ?int $maxMessages = null;


	public function __construct(
		private LazyDeclarator $lazyDeclarator,
		protected string $name,
		protected IQueue $queue,
		callable $callback,
		protected ?int $prefetchSize,
		protected ?int $prefetchCount,
	) {
		$this->callback = $callback;
	}

	public function getQueue(): IQueue
	{
		return $this->queue;
	}

	public function getCallback(): callable
	{
		return $this->callback;
	}

	public function consume(?int $maxSeconds = null, ?int $maxMessages = null): void
	{
		$this->maxMessages = $maxMessages;
		$channel = $this->queue->getConnection()->getChannel();

		if ($this->prefetchSize !== null || $this->prefetchCount !== null) {
			$channel->qos($this->prefetchSize ?? 0, $this->prefetchCount ?? 0);
		}

		$callback = function (Message $message, Channel $channel, Client $client): void {
			$this->messages++;
			$result = call_user_func($this->callback, $message);

			$this->sendResponse($message, $channel, $result, $client);

			if ($this->isMaxMessages()) {
				$client->stop();
			}
		};

		try {
			$channel->consume($callback, $this->queue->getName());
		} catch (ClientException $e) {
			if ($e->getCode() !== 404) {
				throw $e;
			}

			$this->lazyDeclarator->declare();
			$channel = $this->queue->getConnection()->getChannel();
			$channel->consume($callback, $this->queue->getName());
		}

		$channel->getClient()->run($maxSeconds);
	}

	protected function sendResponse(Message $message, Channel $channel, int $result, Client $client): void
	{
		match ($result) {
			IConsumer::MESSAGE_ACK, IConsumer::MESSAGE_ACK_AND_TERMINATE => $channel->ack($message),
			IConsumer::MESSAGE_NACK, IConsumer::MESSAGE_NACK_AND_TERMINATE => $channel->nack($message),
			IConsumer::MESSAGE_NACK_REJECT, IConsumer::MESSAGE_NACK_REJECT_AND_TERMINATE => $channel->nack($message, requeue: false),
			IConsumer::MESSAGE_REJECT, IConsumer::MESSAGE_REJECT_AND_TERMINATE => $channel->reject($message, false),
			default => throw new \InvalidArgumentException("Unknown return value of consumer [{$this->name}] user callback"),
		};

		if (in_array(
			$result,
			[
				IConsumer::MESSAGE_REJECT_AND_TERMINATE,
				IConsumer::MESSAGE_ACK_AND_TERMINATE,
				IConsumer::MESSAGE_NACK_AND_TERMINATE,
				IConsumer::MESSAGE_NACK_REJECT_AND_TERMINATE,
			],
			true
		)
		) {
			$client->stop();
		}
	}

	protected function isMaxMessages(): bool
	{
		return $this->maxMessages !== null && $this->messages >= $this->maxMessages;
	}
}
