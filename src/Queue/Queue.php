<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Queue;

use Bckp\RabbitMQ\Connection\IConnection;

final class Queue implements IQueue
{

	public function __construct(private string $name, private IConnection $connection)
	{
	}


	public function getName(): string
	{
		return $this->name;
	}


	public function getConnection(): IConnection
	{
		return $this->connection;
	}
}
