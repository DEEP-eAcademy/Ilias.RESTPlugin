<?php

namespace RESTController\extensions\ILIASApp\V2\data\block;

use RESTController\extensions\ILIASApp\V2\data\JsonSerializableAware;

require_once __DIR__ . '/../JsonSerializableAware.php';
require_once __DIR__ . '/BaseBlock.php';

/**
 * Class Video
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class Video extends BaseBlock {

	use JsonSerializableAware;

	/**
	 * @var string $url
	 */
	private $url;
	/**
	 * @var string $hash
	 */
	private $hash;


	/**
	 * Video constructor.
	 *
	 * @param int    $id
	 * @param int    $sequence
	 * @param string $visibility
	 * @param string $url
	 * @param string $hash
	 */
	public function __construct($id, $sequence, $visibility, $url, $hash) {
		parent::__construct($id, $sequence, $visibility);
		$this->url = $url;
		$this->hash = $hash;
	}


	/**
	 * @return string
	 */
	public function getUrl() {
		return $this->url;
	}


	/**
	 * @return string
	 */
	public function getHash() {
		return $this->hash;
	}
}