<?php

namespace UpdateCheck;

/**
 * 外部コマンドの実行結果。
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * 失敗の理由に添える、標準エラーの最後の空でない行。
     */
    public function tail(): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $this->stderr)), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? '(標準エラーが空)' : $lines[count($lines) - 1];
    }
}
