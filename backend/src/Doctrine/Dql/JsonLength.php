<?php

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Fonction DQL : JSON_LENGTH(field) → MariaDB/MySQL JSON_LENGTH().
 *
 * Enregistrée dans config/packages/doctrine.yaml comme string_function.
 */
class JsonLength extends FunctionNode
{
    private mixed $jsonExpr = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->jsonExpr = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'JSON_LENGTH('.$this->jsonExpr->dispatch($sqlWalker).')';
    }
}
