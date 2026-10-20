<?php

namespace UpdateCheck;

/**
 * 実行の本体(PHP・PostgreSQL)の、イメージの中の版。読めなかったものは理由を持つ。
 */
final readonly class RuntimeVersions
{
    /**
     * @param  array<string, string>  $versions  名前(PHP・PostgreSQL)→ 版
     * @param  array<string, string>  $reasons  名前 → 読めなかった理由
     */
    public function __construct(
        public array $versions,
        public array $reasons,
    ) {}
}
