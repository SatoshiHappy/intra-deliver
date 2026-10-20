<?php

namespace Tests\Architecture\Engine;

/**
 * SPDX のライセンス式が、許可リストで満たされるかを判定する(汎用)。
 *
 * 式の文法 = `A OR B`(どれか 1 つ)・`A AND B`(すべて)・`( )`・`A WITH 例外`(例外は権利を足すだけなので `A` として扱う)。
 * 識別子は大文字小文字を区別せずに比べる。`GPL-2.0+` のような `+` つきは別の識別子(許可リストに書かない限り不許可)。
 * 解釈できない式(`SEE LICENSE IN …`・括弧の対応違い・空)は不許可。
 */
final class SpdxExpression
{
    /** @var list<string> */
    private array $tokens;

    private int $position = 0;

    private bool $wellFormed = true;

    /**
     * @param  list<string>  $allowed  許可する SPDX 識別子
     */
    private function __construct(string $expression, private readonly array $allowed)
    {
        $split = preg_split('/\s+|(?=[()])|(?<=[()])/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);
        $this->tokens = $split === false ? [] : $split;
    }

    /**
     * @param  list<string>  $allowed  許可する SPDX 識別子
     */
    public static function isAllowed(string $expression, array $allowed): bool
    {
        $parser = new self($expression, array_map(strtolower(...), $allowed));
        $result = $parser->orExpression();

        return $result && $parser->wellFormed && $parser->position === count($parser->tokens);
    }

    private function orExpression(): bool
    {
        $result = $this->andExpression();

        while ($this->consume('OR')) {
            $result = $this->andExpression() || $result;
        }

        return $result;
    }

    private function andExpression(): bool
    {
        $result = $this->term();

        while ($this->consume('AND')) {
            $result = $this->term() && $result;
        }

        return $result;
    }

    private function term(): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null || in_array(strtoupper($token), ['OR', 'AND', 'WITH', ')'], true)) {
            $this->wellFormed = false;

            return false;
        }

        $this->position++;

        if ($token === '(') {
            $result = $this->orExpression();
            $this->wellFormed = $this->consume(')') && $this->wellFormed;

            return $result;
        }

        if ($this->consume('WITH')) {
            $this->exception();
        }

        return in_array(strtolower($token), $this->allowed, true);
    }

    /**
     * `WITH` の次のトークン(例外の識別子)を 1 つ読む。無い、または演算子・括弧なら解釈できない式。
     */
    private function exception(): void
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null || in_array(strtoupper($token), ['OR', 'AND', 'WITH', '(', ')'], true)) {
            $this->wellFormed = false;

            return;
        }

        $this->position++;
    }

    private function consume(string $keyword): bool
    {
        if (strtoupper($this->tokens[$this->position] ?? '') !== $keyword) {
            return false;
        }

        $this->position++;

        return true;
    }
}
