<?php declare(strict_types = 1);

namespace SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine;

use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Node;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\NodeAttributes;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;

/**
 * @phpstan-import-type ValueType from DoctrineArgument
 * @phpstan-type KeyType = ConstExprIntegerNode|ConstExprStringNode|IdentifierTypeNode|ConstFetchNode|null
 */
class DoctrineArrayItem implements Node
{

	use NodeAttributes;

	/** @var KeyType */
	public $key;

	/** @var ValueType */
	public $value;

	/**
	 * @param KeyType $key
	 * @param ValueType $value
	 */
	public function __construct($key, $value)
	{
		$this->key = $key;
		$this->value = $value;
	}

	public function __toString(): string
	{
		if ($this->key === null) {
			return (string) $this->value;
		}

		return $this->key . '=' . $this->value;
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	public static function __set_state(array $properties): self
	{
		$instance = new self($properties['key'], $properties['value']);
		if (isset($properties['attributes'])) {
			foreach ($properties['attributes'] as $key => $value) {
				$instance->setAttribute($key, $value);
			}
		}
		return $instance;
	}

}
