<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * ファイルの最上位の文(名前空間の中の文を含む。名前空間の宣言そのものは含めない)を読む(汎用)。
 * クラスの外にどんな文があるかを判定する規則が使う。
 */
final class TopLevelStatements
{
    /**
     * 宣言だけの文(処理を持たない文)= ブロックを持たない declare・use・空文(コメントだけ)。
     * ブロックつきの declare(`declare(ticks=1) { … }`)は中に処理を書けるので含めない。
     */
    public static function isDeclarationOnly(Stmt $statement): bool
    {
        return ($statement instanceof Stmt\Declare_ && $statement->stmts === null)
            || $statement instanceof Stmt\Use_
            || $statement instanceof Stmt\GroupUse
            || $statement instanceof Stmt\Nop;
    }

    /**
     * 名前のあるクラス・interface・trait・enum の宣言か(匿名クラスは式の中にあり、文ではない)。
     */
    public static function isNamedClassLike(Stmt $statement): bool
    {
        return $statement instanceof Stmt\ClassLike && $statement->name !== null;
    }

    /**
     * 最上位の文と、それが属する名前空間(名前空間の外なら null)。
     *
     * @param  array<Node>  $nodes  ファイルの最上位のノード
     * @return list<array{Stmt, string|null}>
     */
    public static function of(array $nodes): array
    {
        $statements = [];

        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    $statements[] = [$statement, $node->name?->toString()];
                }
            } elseif ($node instanceof Stmt) {
                $statements[] = [$node, null];
            }
        }

        return $statements;
    }
}
