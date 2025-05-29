<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions;
use Doctrine\ORM\Query\TokenType;

class JsonElementEquals extends Functions\FunctionNode
{
    public $jsonField = null;
    public $param = null;
    public $value = null;

    public function parse(\Doctrine\ORM\Query\Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->jsonField = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->param = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->value = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(\Doctrine\ORM\Query\SqlWalker $sqlWalker): string
    {
        $field = $this->jsonField->dispatch($sqlWalker);
        $param = $this->param->dispatch($sqlWalker);
        $value = $this->value->dispatch($sqlWalker);

        return \sprintf(
            "(%s -> %s) is not null and (%s ->> %s = %s)",
            $field, $param,
            $field, $param,
            $value
        );
    }
}
