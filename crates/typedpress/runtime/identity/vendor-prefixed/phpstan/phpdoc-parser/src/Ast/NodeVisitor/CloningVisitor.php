<?php declare(strict_types = 1);

namespace TypedPress\Identity\Vendor\PHPStan\PhpDocParser\Ast\NodeVisitor;

use TypedPress\Identity\Vendor\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use TypedPress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Attribute;
use TypedPress\Identity\Vendor\PHPStan\PhpDocParser\Ast\Node;

final class CloningVisitor extends AbstractNodeVisitor
{

	public function enterNode(Node $originalNode): Node
	{
		$node = clone $originalNode;
		$node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);

		return $node;
	}

}
