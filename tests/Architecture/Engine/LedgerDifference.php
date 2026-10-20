<?php

namespace Tests\Architecture\Engine;

/**
 * 台帳照合の差分。
 *
 * - extra = 実体にあって台帳に無いもの(余分 = 無断追加)
 * - missing = 台帳にあって実体に無いもの(不足 = 水増し)。{@see LedgerMatch::subset()} では常に空
 */
final readonly class LedgerDifference
{
    /**
     * @param  list<string>  $extra
     * @param  list<string>  $missing
     */
    public function __construct(
        public array $extra,
        public array $missing,
    ) {}

    public function isEmpty(): bool
    {
        return $this->extra === [] && $this->missing === [];
    }

    /**
     * 失敗の報告に使う 1 行の説明。
     */
    public function describe(): string
    {
        return sprintf(
            '余分 = [%s] / 不足 = [%s]',
            implode(', ', $this->extra),
            implode(', ', $this->missing),
        );
    }
}
