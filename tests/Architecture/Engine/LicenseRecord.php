<?php

namespace Tests\Architecture\Engine;

/**
 * 依存パッケージ 1 つのライセンスの記録(汎用)。
 */
final readonly class LicenseRecord
{
    /**
     * @param  string  $ecosystem  composer か npm
     * @param  list<string>  $licenses  宣言されたライセンス(SPDX の式)。複数あれば「どれか 1 つを選べる」(composer の license 配列・npm の旧形式 licenses と同じ読み方)。空 = license 欄なし
     * @param  string|null  $problem  ライセンスを読めなかった理由(package.json が無いなど)。読めたら null
     */
    public function __construct(
        public string $ecosystem,
        public string $name,
        public string $version,
        public array $licenses,
        public ?string $problem = null,
    ) {}

    /**
     * 台帳 ⑧ の package の行の値と同じ形(`composer:<名前>` / `npm:<名前>`)。
     */
    public function key(): string
    {
        return $this->ecosystem.':'.$this->name;
    }
}
