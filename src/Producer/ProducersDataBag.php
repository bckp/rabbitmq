<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\Producer;

use Bckp\RabbitMQ\AbstractDataBag;
use Bckp\RabbitMQ\DI\Helpers\ProducersHelper;

final class ProducersDataBag extends AbstractDataBag
{

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(array $data)
	{
		parent::__construct($data);

		foreach ($data as $producerName => $producer) {
			$this->addProducerByData($producerName, (array) $producer);
		}
	}


	/**
	 * @param string $producerName
	 * @param array<string, mixed> $data
	 */
	public function addProducerByData(string $producerName, array $data): void
	{
		$data['deliveryMode'] ??= IProducer::DeliveryModePersistent;
		$data['contentType'] ??= 'text/plain';
		$data['exchange'] ??= null;
		$data['queue'] ??= null;

		if (!in_array($data['deliveryMode'], ProducersHelper::DeliveryModes, true)) {
			throw new \InvalidArgumentException(
				"Unknown exchange type [{$data['type']}]"
			);
		}

		/**
		 * 1, Producer has to be subscribed to either a queue or an exchange
		 * 2, A producer can be subscribed to both a queue and an exchange
		 */
		if ($data['queue'] === [] && $data['exchange'] === []) {
			throw new \InvalidArgumentException(
				'Producer has to be subscribed to either a queue or an exchange'
			);
		}

		$this->data[$producerName] = $data;
	}
}
