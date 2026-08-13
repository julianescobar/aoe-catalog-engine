<?php

namespace AOE\CatalogEngine\Import;

use AOE\CatalogEngine\Import\Processors\ProcessorInterface;

class ProcessorManager {

	private $processors = [];

	public function __construct() {
		$this->register_default_processors();
	}

	private function register_default_processors() {
		$this->register_processor( new Processors\SamtecProcessor() );
		$this->register_processor( new Processors\AmphenolProcessor() );
		$this->register_processor( new Processors\CamdenBossProcessor() );
		$this->register_processor( new Processors\EdacProcessor() );
		$this->register_processor( new Processors\BulginProcessor() );
		$this->register_processor( new Processors\PanduitProcessor() );
		$this->register_processor( new Processors\BivarProcessor() );
		$this->register_processor( new Processors\MediKabelProcessor() );
		$this->register_processor( new Processors\YokowoProcessor() );
		$this->register_processor( new Processors\AmphenolAnytekProcessor() );

		// Allow other plugins/themes to register their own processors
		do_action( 'aoe_catalog_register_processors', $this );
	}

	public function register_processor( ProcessorInterface $processor ) {
		$this->processors[ $processor::get_manufacturer_slug() ] = $processor;
	}

	/**
	 * @return ProcessorInterface|null
	 */
	public function get_processor( string $manufacturer_slug ) {
		return $this->processors[ $manufacturer_slug ] ?? null;
	}
	
	public function get_registered_processors(): array {
		return $this->processors;
	}
}
