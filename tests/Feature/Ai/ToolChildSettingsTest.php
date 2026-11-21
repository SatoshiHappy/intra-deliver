<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\ToolChildSettings;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 並べて動かす組の子の処理の設定を起動の時の確かめで落とす(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4、確かめ =
 * 設計/AI/データと失敗と検査.md 8 節)。今の設定は食い違い無しで、1 つだけ外した設定はその文が返る(ほかの確かめを止めない)。
 */
final class ToolChildSettingsTest extends TestCase
{
    private const SECTION = 'ai_runs.conversation.tool_children';

    public function test_the_current_settings_have_no_problem(): void
    {
        $settings = app(ToolChildSettings::class);

        $this->assertSame([], $settings->problems());
        $values = [$settings->concurrency(), $settings->timeoutSeconds(), $settings->memoryLimit()];
        $this->assertSame([10, 30, '64M'], $values);
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function wrongSettings(): array
    {
        $file = 'config/ai_runs.php の conversation.tool_children.';

        return [
            '同時の数が 0' => ['max_concurrency', 0, "{$file}max_concurrency が正の整数でない"],
            '同時の数が数でない字を含む' => ['max_concurrency', '2x', "{$file}max_concurrency が正の整数でない"],
            '同時の数が空' => ['max_concurrency', '', "{$file}max_concurrency が正の整数でない"],
            '同時の数が真偽' => ['max_concurrency', true, "{$file}max_concurrency が正の整数でない"],
            '時間が負' => ['timeout_seconds', -1, "{$file}timeout_seconds が正の整数でない"],
            'メモリの単位が無い' => ['memory_limit', '64', "{$file}memory_limit が数と K・M・G の 1 字でない"],
            'メモリが無い' => ['memory_limit', null, "{$file}memory_limit が数と K・M・G の 1 字でない"],
        ];
    }

    public function test_an_environment_string_of_digits_is_read_as_the_number(): void
    {
        Config::set(self::SECTION.'.max_concurrency', '2');

        $this->assertSame(2, app(ToolChildSettings::class)->concurrency());
    }

    #[DataProvider('wrongSettings')]
    public function test_a_wrong_setting_has_its_problem(string $key, mixed $value, string $expected): void
    {
        Config::set(self::SECTION.'.'.$key, $value);

        $this->assertSame([$expected], app(ToolChildSettings::class)->problems());
        $this->assertContains($expected, app(PromptKinds::class)->problems());
    }
}
