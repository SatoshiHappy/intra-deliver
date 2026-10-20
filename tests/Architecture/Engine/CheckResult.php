<?php

namespace Tests\Architecture\Engine;

/**
 * 照合 1 件の結果(汎用)。指摘が 0 件なら OK、1 件でもあれば NG。
 *
 * 指摘({@see Finding})の file は「指摘の対象」を指す = ファイルのパス、または台帳の要素・設定の値(例 = 余分な依存の名前)。
 */
final readonly class CheckResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  string  $summary  OK のときに添える、照合した対象の要約(対象 0 件と照合の未実行を見分けるため)
     */
    public function __construct(
        public array $findings,
        public string $summary,
    ) {}

    public function passed(): bool
    {
        return $this->findings === [];
    }

    /**
     * 終了コード(OK = 0 / NG = 1)。照合の実行コマンドと自己証明が同じ規則で使う。
     */
    public function exitCode(): int
    {
        return $this->passed() ? 0 : 1;
    }

    /**
     * 結果の 1 行(「OK  名前 — 要約」か「NG  名前: 指摘 / 指摘」)。
     */
    public function line(string $name): string
    {
        if ($this->passed()) {
            return "OK  {$name} — {$this->summary}";
        }

        return "NG  {$name}: ".implode(' / ', array_map(self::describe(...), $this->findings));
    }

    private static function describe(Finding $finding): string
    {
        return $finding->ruleId.' '.$finding->file.($finding->line === null ? '' : ':'.$finding->line);
    }
}
