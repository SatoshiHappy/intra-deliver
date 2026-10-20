<?php

namespace Tests\Architecture\Engine;

/**
 * 外部コマンドの実行結果(汎用)。
 */
final readonly class ProcessResult
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * 失敗の報告に使う説明(コマンド・終了コード・出力)。
     */
    public function describe(): string
    {
        return sprintf(
            "コマンド: %s\n終了コード: %d\n標準出力:\n%s\n標準エラー:\n%s",
            implode(' ', $this->command),
            $this->exitCode,
            $this->stdout,
            $this->stderr,
        );
    }
}
