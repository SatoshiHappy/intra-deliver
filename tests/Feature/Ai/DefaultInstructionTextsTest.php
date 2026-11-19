<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\Instructions\InstructionTextFile;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use Tests\TestCase;

/**
 * 公開の側の既定の文(resources/ai/instructions)を自前で書き、公開の側に移した版の文と前提を入れないことの確かめ(docs の
 * 設計/AI/設定とプロンプト/README.md 11.4・使う文と組み立て.md 11.7。禁止の語の前提 = 設計/AI/README.md の採った方針の 18)。
 */
final class DefaultInstructionTextsTest extends TestCase
{
    use ChecksModelTexts;

    /** 文に出さない英語の語(接続先の会社と製品の名前と、コーディングの語。大文字小文字を問わず語の境で見る) */
    private const FORBIDDEN_WORDS = [
        ...self::VENDOR_WORDS,
        'git', 'GitHub', 'shell', 'bash', 'terminal', 'CLI', 'command line', 'repository', 'repo', 'codebase', 'code',
        'pull request', 'commit', 'IDE',
    ];

    /** 文に出さない日本語の語(上の英語の語のカタカナの言い方。前と後ろの字がカタカナでない所で見る) */
    private const FORBIDDEN_KATAKANA_WORDS = [
        ...self::VENDOR_KATAKANA_WORDS,
        'ギット', 'ギットハブ', 'シェル', 'バッシュ', 'ターミナル', 'コマンドライン',
        'リポジトリ', 'コードベース', 'コード', 'ソースコード', 'プルリクエスト', 'コミット',
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
        $this->assertSame([], self::forbiddenWords(self::allTexts()));
        $this->assertSame(
            ['a の Claude', 'b の git', 'c の pull request', 'd の code', 'e の クロード', 'f の コード', 'g の ソースコード'],
            self::forbiddenWords([
                'a' => 'You are claude.',
                'b' => 'Use Git here.',
                'c' => 'Open a Pull Request.',
                'd' => 'Run (code).',
                'e' => 'クロードとして答える。',
                'f' => 'コードを書く。',
                'g' => 'ソースコードを読む。',
            ]),
        );
        $this->assertSame([], self::forbiddenWords([
            'h' => 'A barcode, a digit, a shellfish and a repost.',
            'i' => 'レコードを読む。バーコードを付ける。ミストラルソース。',
        ]));
    }

    /** 言葉の口に入れる値は「日本語」(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.8。口に入ることは約束のテストが見分けの値で見る) */
    public function test_the_language_value_is_japanese(): void
    {
        $this->assertSame('日本語', InstructionRequest::JAPANESE);
    }

    /** モデルに渡す文はすべて日本語(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.8): 英語に戻った文を拾う */
    public function test_every_text_is_written_in_japanese(): void
    {
        $this->assertSame([], self::textsNotInJapanese(self::allTexts()));
        $this->assertSame(['a', 'b'], self::textsNotInJapanese([
            'a' => 'The tool {{tool_name}} failed.',
            'b' => '[{{summary}}]',
            'c' => '道具 {{tool_name}} は失敗した。',
            'd' => '[止めた。]',
        ]));
    }

    /** 英語の文が混じらない(置き換え口・識別子・バッククォートの中は見ない) */
    public function test_no_run_of_english_words_appears_in_the_texts(): void
    {
        $this->assertSame([], self::textsWithEnglishRuns(self::allTexts()));
        $this->assertSame(['a', 'b'], self::textsWithEnglishRuns([
            'a' => '道具 {{tool_name}}: The user declined this tool call, so it was not run.',
            'b' => "Your last response was cut off.\n続ける。",
            'c' => 'モデル {{model_marketing_name}}(model ID: {{model_id}})で動く。',
            'd' => '`The user declined this tool call` のときの文。',
            'e' => 'one two three four five 六',
            'f' => 'search_records read_record update_record delete_record create_record list_records',
        ]));
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
     * 2 つのファイルの文に同じ確かめを当てるためのまとめ(鍵 = ファイルの名前:文の鍵。docs の 設計/AI/設定とプロンプト/README.md 11.4)。
     *
     * @return array<string, string>
     */
    private static function allTexts(): array
    {
        $all = [];

        foreach ([DefaultInstructionBuilder::PATH, SharedInstructionTemplates::PATH] as $path) {
            foreach (self::texts($path) as $key => $text) {
                $all[basename($path).':'.$key] = $text;
            }
        }

        return $all;
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
    private static function forbiddenWords(array $texts): array
    {
        return self::wordProblems($texts, self::FORBIDDEN_WORDS, self::FORBIDDEN_KATAKANA_WORDS);
    }
}
