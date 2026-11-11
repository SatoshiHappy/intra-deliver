<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TransactionalTestCase;

/**
 * 指示の組み立ての口の結び(docs の 設計/AI/設定とプロンプト/README.md 11.4 の 2 つの版)と、結んだ版の起動の時の確かめ(設計/AI/データと失敗と検査.md 8 節
 * の「手元の文と既定の文と ③ が持つ定型」): 既定の結びでは口と代わりの版が同じ 1 つ。代わりの版でない版が結ばれていれば、ai:check-settings は
 * その版の文の食い違いと代わりの版の確かめの結果を両方出して 1 で終わり、同じ文は 1 回だけ出る。代わりの版が結ばれていれば、その文は 1 回だけ出る。
 * 手元のリポジトリのプロバイダの登録は PrivateProvidersRegistrationTest。
 */
final class InstructionBuilderBindingTest extends TransactionalTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/instruction-binding-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_the_default_binding_is_one_instance(): void
    {
        $this->assertInstanceOf(DefaultInstructionBuilder::class, app(InstructionBuilder::class));
        $this->assertSame(app(DefaultInstructionBuilder::class), app(InstructionBuilder::class));
    }

    public function test_a_bound_version_other_than_the_default_adds_its_problems_to_the_default_ones(): void
    {
        $missing = $this->useDefaultWithMissingSharedTexts();
        $bound = new ProblemInstructionBuilder([ProblemInstructionBuilder::PROBLEM]);
        $this->app->instance(InstructionBuilder::class, $bound);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame("指示の文のファイル {$missing} が無い\n".ProblemInstructionBuilder::PROBLEM."\n", Artisan::output());
    }

    /** 共有の定型の分のように 2 つの版から同じ文が出ても、1 つにまとめる */
    public function test_the_same_problem_from_both_versions_is_listed_once(): void
    {
        $missing = $this->useDefaultWithMissingSharedTexts();
        $same = "指示の文のファイル {$missing} が無い";
        $bound = new ProblemInstructionBuilder([$same, ProblemInstructionBuilder::PROBLEM]);
        $this->app->instance(InstructionBuilder::class, $bound);

        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame("{$same}\n".ProblemInstructionBuilder::PROBLEM."\n", Artisan::output());
    }

    public function test_the_default_version_bound_is_checked_once(): void
    {
        $missing = $this->useDefaultWithMissingSharedTexts();

        $this->assertInstanceOf(DefaultInstructionBuilder::class, app(InstructionBuilder::class));
        $this->assertSame(1, Artisan::call('ai:check-settings'));
        $this->assertSame("指示の文のファイル {$missing} が無い\n", Artisan::output());
    }

    /** 代わりの版の ③ の定型の置き場を無いファイルに差し替え、その道を返す(代わりの版の確かめが 1 行の食い違いを出す) */
    private function useDefaultWithMissingSharedTexts(): string
    {
        $missing = $this->directory.'/missing-shared.php';
        $this->app->instance(DefaultInstructionBuilder::class, new DefaultInstructionBuilder(null, $missing));

        return $missing;
    }
}
