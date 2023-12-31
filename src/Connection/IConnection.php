<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Connection;

use Bunny\Channel;
use Bunny\Exception\BunnyException;
use Bunny\Protocol\MethodFrame;
use Bckp\RabbitMQ\Connection\Exception\ConnectionException;

interface IConnection
{

	/**
	 * @throws ConnectionException
	 */
	public function getChannel(): Channel;

	/**
	 * @throws BunnyException
	 */
	public function sendHeartbeat(): void;
	public function isConnected(): bool;
	public function getVhost(): string;
	public function isPublishConfirm(): bool;
	public function getPublishConfirm(): int;

	/** @internal */
	public function resetChannel(): void;
	/** @internal */
	public function resetConnection(): void;
}
