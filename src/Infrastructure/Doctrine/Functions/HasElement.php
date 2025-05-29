<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions;
use Doctrine\ORM\Query\TokenType;

class HasElement extends Functions\FunctionNode
{
    public $leftHandSide = null;
    public $rightHandSide = null;

    public function parse(\Doctrine\ORM\Query\Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->leftHandSide = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->rightHandSide = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(\Doctrine\ORM\Query\SqlWalker $sqlWalker): string
    {
        return '(' .
            $this->leftHandSide->dispatch($sqlWalker) . " -> " .
            $this->rightHandSide->dispatch($sqlWalker) .
        ') IS NOT NULL';
    }
}
