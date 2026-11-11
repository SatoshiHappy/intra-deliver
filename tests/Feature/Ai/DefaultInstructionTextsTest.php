<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\Instructions\InstructionTextFile;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use Tests\TestCase;

/**
 * 公開の側の既定の文(resources/ai/instructions)を自前で書き、公開の側に Claude Code の文と前提を入れないことの確かめ(docs の
 * 設計/AI/設定とプロンプト/README.md 11.4・使う文と組み立て.md 11.7。禁止の語の前提 = 設計/AI/README.md の採った方針の 18)。
 */
final class DefaultInstructionTextsTest extends TestCase
{
    /** 文に出さない英語の語(大文字小文字を問わず語の境で見る) */
    private const FORBIDDEN_WORDS = [
        'Claude', 'Anthropic', 'OpenAI', 'ChatGPT', 'GPT', 'Gemini', 'Google', 'Mistral', 'Bedrock', 'Azure',
        'git', 'GitHub', 'shell', 'bash', 'terminal', 'CLI', 'command line', 'repository', 'repo', 'codebase', 'code',
        'pull request', 'commit', 'IDE',
    ];

    /** 文に出さない日本語の語(上の英語の語のカタカナの言い方。前と後ろの字がカタカナでない所で見る) */
    private const FORBIDDEN_KATAKANA_WORDS = [
        'クロード', 'アンソロピック', 'アンスロピック', 'オープンエーアイ', 'チャットジーピーティー', 'ジェミニ', 'グーグル',
        'ミストラル', 'ベッドロック', 'アジュール', 'ギット', 'ギットハブ', 'シェル', 'バッシュ', 'ターミナル', 'コマンドライン',
        'リポジトリ', 'コードベース', 'コード', 'ソースコード', 'プルリクエスト', 'コミット',
    ];

    /** カタカナの字(長音の「ー」を含む。語の境を見る字) */
    private const KATAKANA = '[\x{30A1}-\x{30FA}\x{30FC}-\x{30FE}]';

    private const SOURCE_NOTE = '出どころ = 自前';

    /** 日本語の字(ひらがな・カタカナ・漢字・々) */
    private const JAPANESE_LETTER = '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}々]/u';

    /** 英語の語(英字だけの語。前後の句読点と所有格を許す。識別子のような英字でない字を含む語は当たらない) */
    private const ENGLISH_WORD = '/^[("\']?[A-Za-z]+(?:[\'’][A-Za-z]+)?[.,;:!?)"\']*\z/u';

    /** 英語の語がこの数以上、空白だけで続けば英語の文と見る */
    private const ENGLISH_RUN = 6;

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
        $this->assertSame([], self::wordProblems(self::allTexts()));
        $this->assertSame(
            ['a の Claude', 'b の git', 'c の pull request', 'd の code', 'e の クロード', 'f の コード', 'g の ソースコード'],
            self::wordProblems([
                'a' => 'You are claude.',
                'b' => 'Use Git here.',
                'c' => 'Open a Pull Request.',
                'd' => 'Run (code).',
                'e' => 'クロードとして答える。',
                'f' => 'コードを書く。',
                'g' => 'ソースコードを読む。',
            ]),
        );
        $this->assertSame([], self::wordProblems([
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
     * 置き換え口と `[...]` の角かっこを除いて、日本語の字を 1 つも含まない文の鍵。
     *
     * @param  array<string, string>  $texts
     * @return list<string>
     */
    private static function textsNotInJapanese(array $texts): array
    {
        $keys = [];

        foreach ($texts as $key => $text) {
            $rest = str_replace(['[', ']'], '', self::withoutPlaceholders($text));

            if (preg_match(self::JAPANESE_LETTER, $rest) !== 1) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 置き換え口とバッククォートの中を除いて、英語の語が ENGLISH_RUN 語以上、空白だけで続く所を持つ文の鍵。
     *
     * @param  array<string, string>  $texts
     * @return list<string>
     */
    private static function textsWithEnglishRuns(array $texts): array
    {
        $keys = [];

        foreach ($texts as $key => $text) {
            $plain = preg_replace('/`[^`]*`/u', ' ', self::withoutPlaceholders($text)) ?? $text;
            $run = 0;

            foreach (preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $run = preg_match(self::ENGLISH_WORD, $token) === 1 ? $run + 1 : 0;

                if ($run >= self::ENGLISH_RUN) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    private static function withoutPlaceholders(string $text): string
    {
        return preg_replace('/\{\{[a-z_]+\}\}/', ' ', $text) ?? $text;
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
        $patterns = [];

        foreach (self::FORBIDDEN_WORDS as $word) {
            $patterns[$word] = '/\b'.str_replace(' ', '\s+', preg_quote($word, '/')).'\b/i';
        }

        foreach (self::FORBIDDEN_KATAKANA_WORDS as $word) {
            $patterns[$word] = '/(?<!'.self::KATAKANA.')'.preg_quote($word, '/').'(?!'.self::KATAKANA.')/u';
        }

        $problems = [];

        foreach ($texts as $key => $text) {
            foreach ($patterns as $word => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $problems[] = "{$key} の {$word}";
                }
            }
        }

        return $problems;
    }
}
