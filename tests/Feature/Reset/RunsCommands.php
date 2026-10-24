<?php

namespace Tests\Feature\Reset;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * コマンドを流し、終了コードと出力を確かめる(初期化の出力は docs の 設計/公開の配備/毎日の初期化.md 5 節)。出力は自分の受け口で受ける
 * (Artisan::output はコマンドの中の db:seed の出力に置き換わるため)。
 */
trait RunsCommands
{
    /** $command を流し、終了コードが $exitCode で、出力が $contains を含む(null なら見ない)ことを確かめる。出力を返す */
    protected function assertCommand(string $command, int $exitCode, ?string $contains = null): string
    {
        $buffer = new BufferedOutput;
        $code = Artisan::call($command, [], $buffer);
        $output = $buffer->fetch();

        $this->assertSame($exitCode, $code, $output);

        if ($contains !== null) {
            $this->assertStringContainsString($contains, $output);
        }

        return $output;
    }
}
