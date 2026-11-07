<?php declare(strict_types = 1);

namespace Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\NodeVisitor;

use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\Attribute;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\Node;

final class CloningVisitor extends AbstractNodeVisitor
{

	public function enterNode(Node $originalNode): Node
	{
		$node = clone $originalNode;
		$node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);

		return $node;
	}

}
