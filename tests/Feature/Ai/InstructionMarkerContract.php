<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Enums\Ai\AiInstructionCall;
use App\Enums\Ai\AiInstructionTemplate;
use App\Queries\Ai\Api\RecordMarkers;
use Carbon\CarbonImmutable;

/**
 * 指示の組み立ての口の約束のうち、会話の印の形の一覧の分(docs の 設計/AI/設定とプロンプト/README.md 11.4 の約束のテスト): 返した一覧が、その版が
 * 組むすべての文(システムの文・送る履歴の文・すべての定型・中断の印・要約の後に続ける文)に現れる会話の印の形を含み、組み立ての前と後で
 * 同じ一覧を返す。レコードの印の形はどの会話の印の形とも重ならない(設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
 * 印を拾う式は版の印の書き方を表す物で、子のクラスが返す(印の文の一覧を手で持たない)。{@see InstructionBuilderContract} が使う。
 */
trait InstructionMarkerContract
{
    /** 試す版 */
    abstract protected function instructionBuilder(): InstructionBuilder;

    /** その版の会話の印の書き方の式(当たった文字列 = 印の形 1 つ) */
    abstract protected function conversationMarkerPattern(): string;

    public function test_the_marker_forms_include_every_marker_in_the_built_texts_and_stay_the_same(): void
    {
        $builder = $this->instructionBuilder();
        $before = $builder->conversationMarkerForms();
        $found = [];

        foreach (self::markerTexts($builder) as $key => $text) {
            preg_match_all($this->conversationMarkerPattern(), $text, $matches);
            array_push($found, ...array_map(fn (string $match): string => "{$key}: {$match}", $matches[0]));
            $missing = array_diff($matches[0], $builder->conversationMarkerForms());

            $this->assertSame([], array_values($missing), "{$key} の会話の印が一覧に無い");
        }

        $this->assertNotSame([], $found, '組んだ文に会話の印が 1 つも無い(式が版の書き方に合わない)');
        $this->assertSame($before, $builder->conversationMarkerForms());
    }

    public function test_the_record_marker_overlaps_no_marker_form(): void
    {
        $marker = RecordMarkers::of('meetings', 12);

        foreach ($this->instructionBuilder()->conversationMarkerForms() as $form) {
            $this->assertSame([], RecordMarkers::extract($form), $form);
            $this->assertSame($form, RecordMarkers::neutralize($form), $form);
            $this->assertStringNotContainsString($form, $marker, $form);
        }
    }

    /**
     * 版が組むすべての文(呼び出しの種類ごとのシステムの文と送る履歴の文・すべての定型・要約の後に続ける文)。
     *
     * @return array<string, string>
     */
    private static function markerTexts(InstructionBuilder $builder): array
    {
        $texts = ['compact_summary' => $builder->compactSummary('Asked for overdue deals')];

        foreach (AiInstructionCall::cases() as $call) {
            $built = $builder->build(self::markerRequest($call));
            $texts["{$call->value}:system"] = $built->system;

            foreach ($built->history as $index => $text) {
                $texts["{$call->value}:history:{$index}"] = $text->text;
            }
        }

        foreach (AiInstructionTemplate::cases() as $template) {
            $texts[$template->value] = $builder->template($template, self::markerTemplateValues());
        }

        return $texts;
    }

    private static function markerRequest(AiInstructionCall $call): InstructionRequest
    {
        return new InstructionRequest(
            call: $call,
            instructions: 'Answer as the sales desk.',
            toolNames: ['search_records'],
            provider: 'test-provider',
            modelId: 'model-id-x',
            modelDisplayName: 'Model Display X',
            knowledgeCutoff: 'March 2031',
            language: 'language-marker-x',
            startedAt: CarbonImmutable::parse('2026-11-10T01:00:00Z'),
        );
    }

    /**
     * どの定型にも足りる値(印の形を含まない)。
     *
     * @return array<string, string|list<string>>
     */
    private static function markerTemplateValues(): array
    {
        $keys = array_merge(...array_map(
            fn (AiInstructionTemplate $template): array => $template->valueKeys(),
            AiInstructionTemplate::cases(),
        ));

        return array_fill_keys($keys, 'value-x');
    }
}
