<?php

namespace UpdateCheck;

/**
 * 結果の表の 1 行。cells は外から取った文字列を含む生の値(Markdown に出すときに {@see Markdown::code()} で囲む)。
 */
final readonly class Row
{
    /**
     * @param  list<string>  $cells
     */
    public function __construct(
        public Status $status,
        public array $cells,
    ) {}
}
