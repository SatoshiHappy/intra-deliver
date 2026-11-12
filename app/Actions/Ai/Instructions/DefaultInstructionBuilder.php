<?php

namespace App\Actions\Ai\Instructions;

use App\Enums\Ai\AiInstructionCall;
use App\Enums\Ai\AiInstructionTemplate;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Config;

/**
 * 指示の組み立ての口の代わりの版(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「2 つの版」): 呼び出しの種類ごとの自前の日本語の既定の文
 * (resources/ai/instructions/default.php)を読み、値を入れて返すだけ。持つ条件は、モデルの表示名が無ければ表示名の無い環境の文を使う・
 * 知識の期限があるときだけ期限の文を足す・定義の指示文が空なら出力の形の部分を捨てる、の 3 つ。持ち主が ③ の文は
 * {@see SharedInstructionTemplates} から引く。
 */
final readonly class DefaultInstructionBuilder implements InstructionBuilder
{
    /** 文の置き場(resources の下の位置) */
    public const PATH = 'ai/instructions/default.php';

    /** 会話のシステムの文(会話のステップと要約) */
    public const CONVERSATION_SYSTEM = 'conversation_system';

    /** 出力の形の部分(定義の指示文が空なら捨てる) */
    public const OUTPUT_STYLE = 'output_style';

    /** 環境の部分(モデルの行。会話とファイルを作る。表示名があるとき) */
    public const ENVIRONMENT = 'environment';

    /** 表示名の無いときの環境の部分(モデルの ID だけ) */
    public const ENVIRONMENT_WITHOUT_DISPLAY_NAME = 'environment_without_display_name';

    /** 要約の指示(要約と要約の予備の履歴の末尾。追加の指示の口に ③ の決まった文を入れる) */
    public const COMPACTION_INSTRUCTION = 'compaction_instruction';

    /** 要約の予備のシステムの文 */
    public const COMPACTION_FALLBACK_SYSTEM = 'compaction_fallback_system';

    /** 要約の後に続ける文 */
    public const COMPACT_CONTINUATION = 'compact_continuation';

    /** 題のシステムの文 */
    public const TITLE_SYSTEM = 'title_system';

    /** 題の 1 欄の説明 */
    public const TITLE_FIELD_DESCRIPTION = 'title_field_description';

    /** ファイルを作るのシステムの文 */
    public const CREATE_FILE_SYSTEM = 'create_file_system';

    /** 日付の文 */
    public const DATE = 'date';

    /** 知識の期限の文(期限があるときだけシステムの文に足す) */
    public const KNOWLEDGE_CUTOFF = 'knowledge_cutoff';

    /** 題の 1 欄の名前 */
    public const TITLE_FIELD = 'title';

    /** この版の会話の印の書き方(行の全体を角かっこで囲む = 中断の印・外した印・落とした印・応答が無い旨の印) */
    public const MARKER_LINE = '/^\[[^\[\]]+\]$/uD';

    /** 値を入れないで済む口(代わりの版の文は、持つ口にいつも値を入れる) */
    private const OPTIONAL_PLACEHOLDERS = [];

    private InstructionTextFile $texts;

    private SharedInstructionTemplates $shared;

    /** @var array<string, list<string>> 文ごとに渡す値の鍵({@see self::valueKeys()}) */
    private array $valueKeys;

    /**
     * @param  string|null  $textsPath  版の文の置き場(null = {@see self::PATH}。テストが差し替える)
     * @param  string|null  $sharedPath  ③ の文の置き場(null = {@see SharedInstructionTemplates::PATH})
     */
    public function __construct(?string $textsPath = null, ?string $sharedPath = null)
    {
        $this->texts = new InstructionTextFile($textsPath ?? resource_path(self::PATH));
        $this->shared = new SharedInstructionTemplates($sharedPath);
        $this->valueKeys = self::valueKeys();
    }

    public function build(InstructionRequest $request): BuiltInstructions
    {
        $values = self::values($request);
        $parts = array_map(fn (string $key): string => $this->filled($key, $values), self::systemParts($request));
        $system = implode("\n\n", array_filter($parts, fn (string $part): bool => $part !== ''));

        return new BuiltInstructions($system, $this->history($request->call, $values));
    }

    public function template(AiInstructionTemplate $template, array $values): string
    {
        return $template->isShared()
            ? $this->shared->text($template, $values)
            : $this->filled($template->value, $values);
    }

    /** 既定の要約の指示は下書きの節を求めないので、整えは前後の空白を除くだけ */
    public function compactSummary(string $answer): string
    {
        return $this->filled(self::COMPACT_CONTINUATION, ['summary' => trim($answer)]);
    }

    public function titleField(): TitleField
    {
        return new TitleField(self::TITLE_FIELD, $this->texts->text(self::TITLE_FIELD_DESCRIPTION));
    }

    /** この版の文と ③ の文のうち、行の全体が {@see self::MARKER_LINE} に当たる行(重ねない。文の順) */
    public function conversationMarkerForms(): array
    {
        $forms = [];

        foreach ([...array_values($this->texts->all()), ...array_values($this->shared->texts())] as $text) {
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                if (preg_match(self::MARKER_LINE, trim($line)) === 1) {
                    $forms[] = trim($line);
                }
            }
        }

        return array_values(array_unique($forms));
    }

    public function problems(): array
    {
        $own = $this->texts->problems(self::valueKeys(), self::OPTIONAL_PLACEHOLDERS);

        return [...$own, ...$this->shared->problems()];
    }

    /**
     * 版の文の鍵と、その文に渡す値の鍵(定型でない文と、持ち主が版の定型の種類)。組み立ては文ごとにこの鍵の値だけを渡し、起動の時の確かめも
     * この鍵と比べる(渡す鍵の正本)。
     *
     * @return array<string, list<string>>
     */
    public static function valueKeys(): array
    {
        $request = array_keys(self::requestValues());
        $keys = [
            self::CONVERSATION_SYSTEM => $request,
            self::OUTPUT_STYLE => $request,
            self::ENVIRONMENT => [...$request, 'model_marketing_name'],
            self::ENVIRONMENT_WITHOUT_DISPLAY_NAME => $request,
            self::COMPACTION_INSTRUCTION => [...$request, 'custom_instructions'],
            self::COMPACTION_FALLBACK_SYSTEM => $request,
            self::COMPACT_CONTINUATION => ['summary'],
            self::TITLE_SYSTEM => $request,
            self::TITLE_FIELD_DESCRIPTION => [],
            self::CREATE_FILE_SYSTEM => $request,
            self::DATE => $request,
            self::KNOWLEDGE_CUTOFF => [...$request, 'knowledge_cutoff'],
        ];

        foreach (AiInstructionTemplate::versionCases() as $template) {
            $keys[$template->value] = $template->valueKeys();
        }

        return $keys;
    }

    /**
     * システムの文の部分の鍵(並べる順)。
     *
     * @return list<string>
     */
    private static function systemParts(InstructionRequest $request): array
    {
        $environment = $request->modelDisplayName === null ? self::ENVIRONMENT_WITHOUT_DISPLAY_NAME : self::ENVIRONMENT;
        $model = $request->knowledgeCutoff === null ? [$environment] : [$environment, self::KNOWLEDGE_CUTOFF];
        $style = trim($request->instructions) === '' ? [] : [self::OUTPUT_STYLE];

        return match ($request->call) {
            AiInstructionCall::ConversationStep,
            AiInstructionCall::Compaction => [self::CONVERSATION_SYSTEM, ...$style, ...$model],
            AiInstructionCall::CompactionFallback => [self::COMPACTION_FALLBACK_SYSTEM],
            AiInstructionCall::Title => [self::TITLE_SYSTEM],
            AiInstructionCall::CreateFile => [self::CREATE_FILE_SYSTEM, ...$model],
        };
    }

    /**
     * 送る履歴に足す文(会話のステップ・要約・ファイルを作るは先頭に日付の文、要約と要約の予備は末尾に要約の指示。題には足さない。
     * どれも送るときだけ)。
     *
     * @param  array<string, string>  $values
     * @return list<HistoryText>
     */
    private function history(AiInstructionCall $call, array $values): array
    {
        $date = fn (): HistoryText => HistoryText::sentAtStart($this->filled(self::DATE, $values));
        $compaction = fn (): HistoryText => HistoryText::sentAtEnd($this->filled(self::COMPACTION_INSTRUCTION, [
            ...$values,
            'custom_instructions' => $this->shared->compactionCustomInstructions(),
        ]));

        return match ($call) {
            AiInstructionCall::Title => [],
            AiInstructionCall::ConversationStep, AiInstructionCall::CreateFile => [$date()],
            AiInstructionCall::Compaction => [$date(), $compaction()],
            AiInstructionCall::CompactionFallback => [$compaction()],
        };
    }

    /**
     * 呼び出しの値(モデルの表示名と知識の期限は無ければ口ごと入れない)。
     *
     * @return array<string, string>
     */
    private static function values(InstructionRequest $request): array
    {
        $name = $request->modelDisplayName === null ? [] : ['model_marketing_name' => $request->modelDisplayName];
        $cutoff = $request->knowledgeCutoff === null ? [] : ['knowledge_cutoff' => $request->knowledgeCutoff];
        $values = array_map(fn (Closure $value): string => $value($request), self::requestValues());

        return [...$values, ...$name, ...$cutoff];
    }

    /**
     * 呼び出しの種類の文にいつも渡す値の鍵と作り方(日付は実行の始めの東京の日)。
     *
     * @return array<string, Closure(InstructionRequest): string>
     */
    private static function requestValues(): array
    {
        return [
            'agent_name' => fn (): string => Config::string('app.name'),
            'language' => fn (InstructionRequest $request): string => $request->language,
            'output_style_prompt' => fn (InstructionRequest $request): string => $request->instructions,
            'model_id' => fn (InstructionRequest $request): string => $request->modelId,
            'current_date' => fn (InstructionRequest $request): string => CarbonImmutable::instance($request->startedAt)
                ->setTimezone(AccessContext::TIMEZONE)
                ->format('Y-m-d'),
        ];
    }

    /**
     * 文に、その文に渡す鍵({@see self::valueKeys()})の値だけを 1 回で入れた文。
     *
     * @param  array<string, string|list<string>>  $values
     */
    private function filled(string $key, array $values): string
    {
        $passed = array_intersect_key($values, array_flip($this->valueKeys[$key] ?? []));

        return InstructionPlaceholders::fill($key, $this->texts->text($key), $passed, self::OPTIONAL_PLACEHOLDERS);
    }
}
