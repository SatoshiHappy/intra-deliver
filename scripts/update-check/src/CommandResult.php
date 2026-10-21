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
     * 標準エラーの空でない行(前後の空白を除く)。
     *
     * @return list<string>
     */
    public function stderrLines(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->stderr)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * 失敗の理由に添える、標準エラーの最後の空でない行。
     */
    public function tail(): string
    {
        $lines = $this->stderrLines();

        return $lines === [] ? '(標準エラーが空)' : $lines[count($lines) - 1];
    }
}
