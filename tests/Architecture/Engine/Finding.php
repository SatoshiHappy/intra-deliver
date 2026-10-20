<?php

namespace Tests\Architecture\Engine;

/**
 * 検査ツールが報告した 1 件の指摘(規則 ID・ファイル・行)。
 */
final readonly class Finding
{
    public function __construct(
        public string $ruleId,
        public string $file,
        public ?int $line,
    ) {}

    /**
     * この指摘が「規則 $ruleId が $relativeFile の $line 行を指した」ものかを判定する。
     *
     * ファイルは区切り文字を / にそろえて比べ、ツールが絶対パスや別の起点で報告しても末尾が $relativeFile なら一致とする。
     * $line が null なら行は比べない(行を報告しないツール向け)。
     */
    public function matches(string $ruleId, string $relativeFile, ?int $line): bool
    {
        if ($this->ruleId !== $ruleId) {
            return false;
        }

        $reported = str_replace('\\', '/', $this->file);
        $expected = ltrim(str_replace('\\', '/', $relativeFile), '/');

        if ($reported !== $expected && ! str_ends_with($reported, '/'.$expected)) {
            return false;
        }

        return $line === null || $this->line === $line;
    }

    /**
     * 失敗の報告に使う 1 行の説明。
     */
    public function describe(): string
    {
        return $this->ruleId.' '.$this->file.':'.($this->line ?? '-');
    }
}
