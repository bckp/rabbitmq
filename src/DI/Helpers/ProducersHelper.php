<?php

declare(strict_types=1);

namespace Contributte\RabbitMQ\DI\Helpers;

use Contributte\RabbitMQ\Producer\IProducer;
use Contributte\RabbitMQ\Producer\Producer;
use Contributte\RabbitMQ\Producer\ProducerFactory;
use Contributte\RabbitMQ\Producer\ProducersDataBag;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class ProducersHelper extends AbstractHelper
{

	public const DeliveryModes = [
		IProducer::DeliveryModeNonPersistent,
		IProducer::DeliveryModePersistent,
	];

	public function getConfigSchema(): Schema
	{
		return Expect::arrayOf(
			Expect::structure([
				'exchange' => Expect::string()->required(false),
				'queue' => Expect::string()->required(false),
				'contentType' => Expect::string('text/plain'),
				'deliveryMode' => Expect::anyOf(...self::DeliveryModes)
					->default(IProducer::DeliveryModePersistent),
			])->castTo('array'),
			'string'
		);
	}
	/**
	 * @param array<string, mixed> $config
	 */
	public function setup(ContainerBuilder $builder, array $config = []): ServiceDefinition
	{
		$producersDataBag = $builder->addDefinition($this->extension->prefix('producersDataBag'))
			->setFactory(ProducersDataBag::class)
			->setArguments([$config]);

		return $builder->addDefinition($this->extension->prefix('producerFactory'))
			->setFactory(ProducerFactory::class)
			->setArguments([$producersDataBag]);
	}
}
