<?php

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Fonction DQL : JSON_CONTAINS(field, candidate) → MariaDB/MySQL JSON_CONTAINS().
 *
 * Renvoie 1 si la valeur candidate est trouvée dans le JSON, 0 sinon.
 * Enregistrée dans config/packages/doctrine.yaml comme numeric_function.
 */
class JsonContains extends FunctionNode
{
    private mixed $jsonExpr = null;
    private mixed $candidate = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->jsonExpr = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->candidate = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'JSON_CONTAINS('
            .$this->jsonExpr->dispatch($sqlWalker)
            .', '
            .$this->candidate->dispatch($sqlWalker)
            .')';
    }
}
