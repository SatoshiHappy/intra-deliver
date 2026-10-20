<?php

namespace UpdateCheck;

/**
 * 1 節(#1〜#8 の 1 項目)の結果。
 */
final readonly class Section
{
    /**
     * @param  list<string>  $headers  区分の列の後ろに並ぶ列の見出し
     * @param  list<Row>  $rows
     * @param  list<Unconfirmed>  $unconfirmed
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $headers,
        public array $rows,
        public array $unconfirmed,
    ) {}

    public function count(Status $status): int
    {
        return count(array_filter($this->rows, static fn (Row $row): bool => $row->status === $status));
    }
}
