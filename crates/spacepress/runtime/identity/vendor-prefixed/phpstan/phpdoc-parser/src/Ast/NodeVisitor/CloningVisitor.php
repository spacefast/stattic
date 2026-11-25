<?php declare(strict_types = 1);

namespace SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\NodeVisitor;

use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Attribute;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Node;

final class CloningVisitor extends AbstractNodeVisitor
{

	public function enterNode(Node $originalNode): Node
	{
		$node = clone $originalNode;
		$node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);

		return $node;
	}

}
