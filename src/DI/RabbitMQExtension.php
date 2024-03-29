<?php

declare(strict_types=1);

namespace Bckp\RabbitMQ\DI;

use Bckp\RabbitMQ\Client;
use Bckp\RabbitMQ\Console\Command\ConsumerCommand;
use Bckp\RabbitMQ\Console\Command\DeclareQueuesAndExchangesCommand;
use Bckp\RabbitMQ\Console\Command\StaticConsumerCommand;
use Bckp\RabbitMQ\DI\Helpers\ConnectionsHelper;
use Bckp\RabbitMQ\DI\Helpers\ConsumersHelper;
use Bckp\RabbitMQ\DI\Helpers\ExchangesHelper;
use Bckp\RabbitMQ\DI\Helpers\ProducersHelper;
use Bckp\RabbitMQ\DI\Helpers\QueuesHelper;
use Bckp\RabbitMQ\LazyDeclarator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Statement;
use Nette\DI\PhpGenerator;
use Nette\PhpGenerator\Literal;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * @property-read string $name
 * @property-read Compiler $compiler
 * @property-read Closure $initialization
 */
final class RabbitMQExtension extends CompilerExtension
{
	private ConnectionsHelper $connectionsHelper;
	private QueuesHelper $queuesHelper;
	private ProducersHelper $producersHelper;
	private ExchangesHelper $exchangesHelper;
	private ConsumersHelper $consumersHelper;

	public function __construct()
	{
		$this->connectionsHelper = new ConnectionsHelper($this);
		$this->queuesHelper = new QueuesHelper($this);
		$this->exchangesHelper = new ExchangesHelper($this);
		$this->producersHelper = new ProducersHelper($this);
		$this->consumersHelper = new ConsumersHelper($this);
	}

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'connections' => $this->connectionsHelper->getConfigSchema(),
			'queues' => $this->queuesHelper->getConfigSchema(),
			'consumers' => $this->consumersHelper->getConfigSchema(),
			'exchanges' => $this->exchangesHelper->getConfigSchema(),
			'producers' => $this->producersHelper->getConfigSchema(),
		])->castTo('array');
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$this->processConfig(new PhpGenerator($builder), $config);
		$this->processExtensions($config);

		$this->connectionsHelper->setup($builder, $config['connections']);
		$this->queuesHelper->setup($builder, $config['queues']);
		$this->exchangesHelper->setup($builder, $config['exchanges']);
		$this->producersHelper->setup($builder, $config['producers']);
		$this->consumersHelper->setup($builder, $config['consumers']);

		/**
		 * Register Client class
		 */
		$builder->addDefinition($this->prefix('client'))
			->setFactory(Client::class);
		$builder->addDefinition($this->prefix('declarator'))
			->setFactory(LazyDeclarator::class);

		$this->setupConsoleCommand();
	}


	public function setupConsoleCommand(): void
	{
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('console.consumerCommand'))
			->setFactory(ConsumerCommand::class)
			->setTags(['console.command' => 'rabbitmq:consumer']);

		$builder->addDefinition($this->prefix('console.staticConsumerCommand'))
			->setFactory(StaticConsumerCommand::class)
			->setTags(['console.command' => 'rabbitmq:staticConsumer']);

		$builder->addDefinition($this->prefix('console.declareQueuesExchangesCommand'))
			->setFactory(DeclareQueuesAndExchangesCommand::class)
			->setTags(['console.command' => 'rabbitmq:declareQueuesAndExchanges']);
	}

	protected function processConfig(PhpGenerator $generator, mixed &$item): void
	{
		if (is_array($item)) {
			foreach ($item as &$value) {
				$this->processConfig($generator, $value);
			}
		} elseif ($item instanceof Statement) {
			$item = new Literal($generator->formatStatement($item));
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	protected function processExtensions(array &$config): void
	{
		foreach ($config['queues'] ?? [] as $name => $data) {
			# No dlx, we can continue
			if (!isset($data['dlx']) || !$data['dlx']) {
				continue;
			}

			$exchangeOut = "{$name}.dlx-out";
			$exchangeIn = "{$name}.dlx-in";

			# Setup dead letter exchange
			$config['queues'][$name]['arguments']['x-dead-letter-exchange'] = $exchangeIn;

			# Prepare variables
			$dlxSuffix = \is_numeric($data['dlx']) ? '-' . $data['dlx'] : '';
			$queueDlxName = "{$name}.dlx";
			$queueDlxArguments = [];

			# If we have set queue type, dlx should copy it
			if (is_string($config['queues'][$name]['arguments']['x-queue-type'] ?? false)) {
				$queueDlxArguments['x-queue-type'] = $config['queues'][$name]['arguments']['x-queue-type'];
			}

			if (!is_bool($data['dlx'])) {
				$queueDlxName .= $dlxSuffix;
				$queueDlxArguments = [
					'x-dead-letter-exchange' => $exchangeOut,
					'x-message-ttl' => $data['dlx'] * 1000,
				] + $queueDlxArguments;

				$config['exchanges'][$exchangeOut] = $this->exchangesHelper->processConfiguration([
					'connection' => $data['connection'],
					'type' => ExchangesHelper::ExchangeTypes[3],
					'queueBindings' => [
						$name => [],
					],
				]);
			}

			# DLX Exchange: will pass msg to dlx queue
			$config['exchanges'][$exchangeIn] = $this->exchangesHelper->processConfiguration([
				'connection' => $data['connection'],
				'type' => ExchangesHelper::ExchangeTypes[3],
				'queueBindings' => [
					$queueDlxName => []
				]
			]);

			# Expand dlx into new queues and exchange for them
			$config['queues'][$queueDlxName] = $this->queuesHelper->processConfiguration([
				'connection' => $data['connection'],
				'autoCreate' => true,
				'arguments' => $queueDlxArguments,
			]);

			unset($config['queues'][$name]['dlx']);
		}
	}
}
