<?php

namespace App\Console\Commands\Ai;

use App\Actions\Ai\Conversation\ToolCallChild;
use App\Actions\Ai\Conversation\ToolCallChildInput;
use Illuminate\Console\Command;

/**
 * 並べて動かす組の子の処理(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。会話の worker が起こし、標準入力で渡された呼び出し
 * 1 つを包みに通して、結果を標準出力に JSON 1 行で返す(業務の値をコマンドの引数と環境の値に載せない)。人が流すコマンドではない。
 */
final class RunToolCallChild extends Command
{
    protected $signature = 'ai:tool-call-child';

    protected $description = '会話の worker が起こす、並べて動かす道具の呼び出し 1 つの子の処理(人は流さない)';

    protected $hidden = true;

    public function handle(ToolCallChild $child): int
    {
        $input = ToolCallChildInput::fromJson((string) stream_get_contents(STDIN));
        $this->output->write((string) json_encode($child->run($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
