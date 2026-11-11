<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionTextFile;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use Tests\TestCase;

/**
 * 公開の側の既定の文(resources/ai/instructions)を自前で書き、公開の側に Claude Code の文と前提を入れないことの確かめ(docs の
 * 設計/AI/設定とプロンプト/README.md 11.4・使う文と組み立て.md 11.7。禁止の語の前提 = 設計/AI/README.md の採った方針の 18)。
 */
final class DefaultInstructionTextsTest extends TestCase
{
    /** 文に出さない語(大文字小文字を問わず語の境で見る) */
    private const FORBIDDEN_WORDS = [
        'Claude', 'Anthropic', 'OpenAI', 'ChatGPT', 'GPT', 'Gemini', 'Google', 'Mistral', 'Bedrock', 'Azure',
        'git', 'GitHub', 'shell', 'bash', 'terminal', 'CLI', 'command line', 'repository', 'repo', 'codebase', 'code',
        'pull request', 'commit', 'IDE',
    ];

    private const SOURCE_NOTE = '出どころ = 自前';

    public function test_both_files_carry_the_own_source_note(): void
    {
        foreach ([DefaultInstructionBuilder::PATH, SharedInstructionTemplates::PATH] as $path) {
            $this->assertTrue(self::hasSourceNote((string) file_get_contents(resource_path($path))), $path);
        }

        $this->assertFalse(self::hasSourceNote("<?php\n\n/*\n * 既定の文。\n */\n\nreturn [];\n"));
    }

    /** 鍵の欠けと置き換え口は起動の時の確かめそのもので見る(破る文の場合 = {@see InstructionTextChecksTest}) */
    public function test_the_current_texts_pass_the_startup_check(): void
    {
        $default = new InstructionTextFile(resource_path(DefaultInstructionBuilder::PATH));
        $shared = new InstructionTextFile(resource_path(SharedInstructionTemplates::PATH));

        $this->assertSame([], $default->problems(DefaultInstructionBuilder::valueKeys(), []));
        $this->assertSame([], $shared->problems(SharedInstructionTemplates::valueKeys(), []));
        $this->assertArrayHasKey('compaction_custom_instructions', SharedInstructionTemplates::valueKeys());
    }

    /** 起動の時の確かめが見ない向き: 文のファイルに、要る鍵の一覧に無い鍵が余らない */
    public function test_no_key_is_left_over(): void
    {
        $default = self::texts(DefaultInstructionBuilder::PATH);
        $shared = self::texts(SharedInstructionTemplates::PATH);
        $broken = [...$shared, 'tool_use_rejected' => 'Owned by the version.'];

        $this->assertSame([], self::extraKeys($default, DefaultInstructionBuilder::valueKeys()));
        $this->assertSame([], self::extraKeys($shared, SharedInstructionTemplates::valueKeys()));
        $this->assertSame(['tool_use_rejected'], self::extraKeys($broken, SharedInstructionTemplates::valueKeys()));
    }

    public function test_no_forbidden_word_appears_in_the_texts(): void
    {
        $this->assertSame([], self::wordProblems([
            ...self::texts(DefaultInstructionBuilder::PATH),
            ...self::texts(SharedInstructionTemplates::PATH),
        ]));
        $this->assertSame(
            ['a の Claude', 'b の git', 'c の pull request', 'd の code'],
            self::wordProblems([
                'a' => 'You are claude.',
                'b' => 'Use Git here.',
                'c' => 'Open a Pull Request.',
                'd' => 'Run (code).',
            ]),
        );
        $this->assertSame([], self::wordProblems(['e' => 'A barcode, a digit, a shellfish and a repost.']));
    }

    private static function hasSourceNote(string $source): bool
    {
        $head = strstr($source, 'return [', true);

        return $head !== false && str_contains($head, self::SOURCE_NOTE);
    }

    /**
     * @return array<string, string>
     */
    private static function texts(string $path): array
    {
        /** @var array<string, string> $texts */
        $texts = require resource_path($path);

        return $texts;
    }

    /**
     * @param  array<string, string>  $texts
     * @param  array<string, list<string>>  $valueKeys
     * @return list<string>
     */
    private static function extraKeys(array $texts, array $valueKeys): array
    {
        return array_values(array_diff(array_keys($texts), array_keys($valueKeys)));
    }

    /**
     * @param  array<string, string>  $texts
     * @return list<string>
     */
    private static function wordProblems(array $texts): array
    {
        $problems = [];

        foreach ($texts as $key => $text) {
            foreach (self::FORBIDDEN_WORDS as $word) {
                if (preg_match('/\b'.str_replace(' ', '\s+', preg_quote($word, '/')).'\b/i', $text) === 1) {
                    $problems[] = "{$key} の {$word}";
                }
            }
        }

        return $problems;
    }
}
