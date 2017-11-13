<?php

namespace Prologuetech\Exee;

use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

class Client
{
	const TRANS_PREFIX = '0,';
	const TRANS_AFFIX = '99,""';
	const TRANS_SEPARATOR = ',';

	/**
	 * URI endpoint for socket connection
	 *
	 * @var string
	 */
	public $uri;

	/**
	 * React Event Loop
	 *
	 * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop
	 */
	public $eventLoop;

	/**
	 * React Socket connector
	 *
	 * @var Connector
	 */
	public $socketConnector;

	/**
	 * Optional transaction ID for multiple requests
	 *
	 * @var null|string
	 */
	public $transactionId;

	/**
	 * Transaction Type
	 *
	 * @var string
	 */
	public $transactionType;

	/**
	 * Computed transaction string
	 *
	 * @var string
	 */
	public $transactionString;

	/**
	 * Toggles transaction string reordering
	 *
	 * @var bool
	 */
	public $reorder = true;

	/**
	 * Client constructor.
	 *
	 * @param null|string $uri
	 * @param null|string $transactionId
	 */
	public function __construct($uri = null, $transactionId = null)
	{
		// Default our URI to localhost
		$this->uri = $uri ?? 'localhost:80';

		// Create react event loop
		$this->eventLoop = Factory::create();

		// Prepare our socket connection
		$this->socketConnector = new Connector($this->eventLoop);

		// TODO: generate transaction ID on the fly for every new transaction call
		$this->transactionId = $transactionId ?? 'General Transaction';
	}

	/**
	 * Push our transaction through our socket connection
	 *
	 * @param bool $withValidation
	 */
	public function push($withValidation = true)
	{
		if ($withValidation) {
			$this->validateModel();
		}

		// Make our connection
		$this->socketConnector->connect($this->uri)->then(function (ConnectionInterface $connection) {
			// When we receive data, output it to our page and end
			$connection->on('data', function ($data) use ($connection) {
				echo $data;
				$connection->close();
			});

			// Write our transaction to our URI
			$connection->write($this->prepare($this->transactionString));

			// Pipe the response back out
			$connection->pipe('');

			// Cleanup
			$connection->close();
		});

		// Fire event loop and process
		$this->eventLoop->run();
	}

	/**
	 * Prepare our transaction string by wrapping it with our prefix/affix.
	 *
	 * @param string $transactionString
	 * @return string
	 */
	public function prepare($transactionString)
	{
		return static::TRANS_PREFIX . $transactionString . static::TRANS_AFFIX;
	}

	/**
	 * Transaction string wrapper
	 *
	 * @param string $transactionString
	 * @return string
	 */
	public static function wrapper($transactionString)
	{
		return '"' . $transactionString . '"';
	}

	/**
	 * Set our transaction type.
	 *
	 * @param $type
	 * @return $this
	 */
	public function setTransactionType($type)
	{
		// Set our transaction type
		$this->transactionType = $type;

		// Set our transaction ID based on type
		$this->setTransactionId($this->getTransactionIdFromType($type));

		// Add our transaction ID to our fields
		$this->addTransactionFields($type);

		// Ever onward
		return $this;
	}

	/**
	 * Set our transaction ID
	 *
	 * @param string $id
	 */
	public function setTransactionId($id)
	{
		$this->transactionId = $id;
	}

	/**
	 * Set our reorder toggle
	 *
	 * @param bool $bool
	 */
	public function setReorder($bool)
	{
		$this->reorder = $bool;
	}

	/**
	 * Reflects our constant name from it's type
	 *
	 * @param string $type
	 * @return bool|string
	 * @throws \Exception
	 */
	public function getTransactionIdFromType($type)
	{
		// Reflect our transaction type class
		$reflect = new \ReflectionClass(TransactionTypes::class);

		// Array our constant list
		$constants = $reflect->getConstants();

		// Find our result
		$id = array_search($type, $constants, true);

		// Use our default if no match was found
		if (empty($id)) {
			$id = $this->transactionId;
		}

		// Humane treatments
		return ucwords(strtolower(str_replace('_', ' ', $id)));
	}

	/**
	 * Reflects our constant name from it's type
	 *
	 * @param string $type
	 * @return bool|string
	 * @throws \Exception
	 */
	public static function reflectFields($type)
	{
		// Reflect our transaction type class
		$reflect = new \ReflectionClass(FieldTypes::class);

		// Array our constant list
		$constants = $reflect->getConstants();

		// Find our result
		$id = array_search($type, $constants, true);

		var_dump($id);

		return $id;
	}

	/**
	 * Convert a data model into a transaction string
	 *
	 * @param Model $data
	 * @param bool $withValidation
	 * @return $this
	 */
	public function with($model, $withValidation = true)
	{
		// Validate our model
		if ($withValidation) {
			$this->validateModel($model);
		}

		// Generate our transaction ID if the developer does not specify
		if (!$model->getAttribute(FieldTypes::CUSTOMER_TRANSACTION_ID)) {
			$model->setAttribute(FieldTypes::CUSTOMER_TRANSACTION_ID, $this->transactionId);
		}

		// Pull all attributes
		$data = $model->getAttributes();

		// Rerder unless disabled
		if ($this->reorder) {
			ksort($data);
		}

		// Add our data to our transaction string
		$this->addTransactionFields($data);
		return $this;
	}

	/**
	 * Add a single or many transaction fields to our transaction string
	 *
	 * Note: Order is not important
	 *
	 * @param string|array $data
	 */
	public function addTransactionFields($data)
	{
		// Handle single string additions
		if (!is_array($data)) {
			$this->transactionString .= static::wrapper($data);
			return;
		}

		// Handle array of strings
		foreach ($data as $field => $value) {
			$this->transactionString .= $field . static::TRANS_SEPARATOR . static::wrapper($value);
		}

		return;
	}

	/**
	 * Call our model validate method
	 *
	 * @param Model $model
	 * @throws \Exception
	 */
	public function validateModel($model)
	{
		if (!$model instanceof Model) {
			throw new \Exception(__METHOD__ . ': Unknown model type.');
		}

		if ($model->validate($model->getAttributes())) {
			return true;
		}

		$missingFieldsString = implode(', ', $model->getMissingFields());

		throw new \Exception('Unable to validate ' . get_class($model) . ', missing required fields: ' . $missingFieldsString);
	}
}
