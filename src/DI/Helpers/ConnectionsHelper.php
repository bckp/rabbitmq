<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\DI\Helpers;

use Bckp\RabbitMQ\Connection\ConnectionFactory;
use Bckp\RabbitMQ\Connection\ConnectionsDataBag;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class ConnectionsHelper extends AbstractHelper
{
	public function getConfigSchema(): Schema
	{
		return Expect::arrayOf(
			Expect::structure([
				'user' => Expect::string('guest'),
				'password' => Expect::string('guest')->dynamic(),
				'host' => Expect::string('127.0.0.1'),
				'port' => Expect::int(5672),
				'vhost' => Expect::string('/')->dynamic(),
				'path' => Expect::string('/')->dynamic(),
				'timeout' => Expect::anyOf(Expect::float(), Expect::int())->default(10)->castTo('float'),
				'heartbeat' => Expect::anyOf(Expect::float(), Expect::int())->default(60)->castTo('float'),
				'persistent' => Expect::bool(false),
				'tcpNoDelay' => Expect::bool(false),
				'lazy' => Expect::bool(true),
				'ssl' => Expect::array(null)->required(false),
				'heartbeatCallback' => Expect::array(null)->required(false),
				'publishConfirm' => Expect::anyOf(
					Expect::bool(),
					Expect::int(),
				)->default(false),
				'admin' => Expect::structure([
					'port' => Expect::int(15672),
					'secure' => Expect::bool(false),
				])->castTo('array'),
			])->castTo('array'),
			'string'
		);
	}

	/**
	 * @param ContainerBuilder $builder
	 * @param array<string, mixed> $config
	 * @return ServiceDefinition
	 */
	public function setup(ContainerBuilder $builder, array $config = []): ServiceDefinition
	{
		$connectionsDataBag = $builder->addDefinition($this->extension->prefix('connectionsDataBag'))
			->setFactory(ConnectionsDataBag::class)
			->setArguments([$config]);

		return $builder->addDefinition($this->extension->prefix('connectionFactory'))
			->setFactory(ConnectionFactory::class)
			->setArguments([$connectionsDataBag]);
	}
}
