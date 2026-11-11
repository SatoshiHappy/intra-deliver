<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Actions\Ai\PromptKinds;
use App\Enums\Ai\AiInstructionTemplate;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 起動の時の確かめの指示の文の分(docs の 設計/AI/データと失敗と検査.md 8 節の「手元の文と既定の文と ③ が持つ定型に、置き換え口の無い口が
 * 無いこと」): 今の既定の文と ③ の定型なら食い違いが無い。許す一覧に無い置き換え口・文の鍵の欠け・ファイルの欠けと形の誤りは、投げずに
 * 食い違いの文になり、ai:check-settings がほかの確かめと一緒に出して終了コード 1 で終わる(テストの中で文の置き場を差し替える)。
 */
final class InstructionTextChecksTest extends TransactionalTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/instruction-texts-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_the_current_texts_have_no_problems(): void
    {
        $this->assertSame([], (new DefaultInstructionBuilder)->problems());
    }

    public function test_unknown_placeholders_and_missing_texts_are_listed_by_the_command_with_the_other_checks(): void
    {
        /** @var array<string, string> $texts */
        $texts = require resource_path(DefaultInstructionBuilder::PATH);
        unset($texts['no_response']);
        $texts['date'] = 'Today is {{today}}.';
        $default = $this->write('default.php', $texts);
        $shared = $this->directory.'/missing-shared.php';
        $this->app->instance(DefaultInstructionBuilder::class, new DefaultInstructionBuilder($default, $shared));
        config(['queue.connections.database.retry_after' => 3340]);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame(implode("\n", [
            "指示の文 no_response が {$default} に無い",
            "指示の文 date({$default})に許す一覧に無い置き換え口 {{today}} がある",
            "指示の文のファイル {$shared} が無い",
            '種類 meeting.transcription のジョブの時間 3340 秒がキューの retry_after 3340 秒以上(config/queue.php)',
        ])."\n", Artisan::output());
    }

    public function test_a_file_without_a_text_array_is_a_problem_not_an_exception(): void
    {
        $default = $this->write('default.php', ['date' => ['not', 'a', 'text']]);
        $builder = new DefaultInstructionBuilder($default);

        $this->assertSame(["指示の文のファイル {$default} が文の鍵と文字列の配列を返さない"], $builder->problems());

        $this->app->instance(DefaultInstructionBuilder::class, $builder);
        $this->assertContains("指示の文のファイル {$default} が文の鍵と文字列の配列を返さない", app(PromptKinds::class)->problems());
    }

    /** 許す一覧にある口でも、その文に呼び手が渡さない口(ほかの文の口)は食い違いの文になる */
    public function test_a_placeholder_of_another_text_is_a_problem(): void
    {
        /** @var array<string, string> $texts */
        $texts = require resource_path(DefaultInstructionBuilder::PATH);
        $texts['unknown_tool'] = 'There is no tool named {{tool_name}}. {{summary}}';
        $texts['date'] = 'Today is {{current_date}} for {{tool_name}}.';
        $default = $this->write('default.php', $texts);

        $this->assertSame([
            "指示の文 date({$default})にその文に渡さない置き換え口 {{tool_name}} がある",
            "指示の文 unknown_tool({$default})にその文に渡さない置き換え口 {{summary}} がある",
        ], (new DefaultInstructionBuilder($default))->problems());
    }

    /** 構文の誤りで読めないファイルは、確かめでは食い違いの文、文を使う所では作りの誤り */
    public function test_a_file_that_cannot_be_loaded_is_a_problem_and_a_build_error(): void
    {
        $default = $this->directory.'/broken.php';
        file_put_contents($default, '<?php return [');
        $builder = new DefaultInstructionBuilder($default);

        $this->assertCount(1, $builder->problems());
        $this->assertStringStartsWith("指示の文のファイル {$default} を読めない: ", $builder->problems()[0]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("指示の文のファイル {$default} を読めない: ");

        $builder->titleField();
    }

    /** 確かめでは文になる欠けも、文を使う所では作りの誤り */
    public function test_a_missing_shared_text_is_a_build_error_where_it_is_used(): void
    {
        $shared = $this->write('shared.php', ['stopped_by_time_limit' => 'Stopped.']);
        $builder = new DefaultInstructionBuilder(null, $shared);

        $this->assertContains("指示の文 tool_exception が {$shared} に無い", $builder->problems());
        $sharedTexts = new SharedInstructionTemplates($shared);

        $this->assertSame('Stopped.', $sharedTexts->text(AiInstructionTemplate::StoppedByTimeLimit, []));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("指示の文 tool_exception が {$shared} に無い");

        $builder->template(AiInstructionTemplate::ToolException, ['tool_name' => 'search_records']);
    }

    /**
     * @param  array<string, mixed>  $texts
     */
    private function write(string $name, array $texts): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, '<?php return '.var_export($texts, true).';');

        return $path;
    }
}
