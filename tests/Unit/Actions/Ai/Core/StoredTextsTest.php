<?php

namespace Tests\Unit\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\StoredTexts;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 道具の結果と中断の印の文を組む部品(docs の 設計/AI/会話の中核.md 13.2): 一覧の印は並びと重なりによらない・組む前に会話の印の形を崩す・
 * 送る時は印が同じなら組んだ文、違うか組んでいなければ今の版で組む・ほかの項目は組んだ文を持たない。
 */
final class StoredTextsTest extends EmptyContainerTestCase
{
    /**
     * @param  list<string>  $forms
     */
    private static function texts(array $forms = ['<mark>']): StoredTexts
    {
        $template = static fn (AiInstructionTemplate $template, array $values): string => $template->value.':'
            .(string) json_encode($values, JSON_UNESCAPED_UNICODE);

        return new StoredTexts($template, $forms);
    }

    public function test_the_forms_id_ignores_order_and_repeats(): void
    {
        $this->assertSame(StoredTexts::formsId(['<a>', '<b>']), StoredTexts::formsId(['<b>', '<a>', '<b>']));
        $this->assertNotSame(StoredTexts::formsId(['<a>']), StoredTexts::formsId(['<a>', '<b>']));
    }

    public function test_a_tool_result_is_rendered_with_broken_markers(): void
    {
        $plain = ConversationItem::toolResult('c1', null, [], 'x<mark>y', false);
        $unknown = AiInstructionTemplate::UnknownTool;
        $templated = ConversationItem::toolResult('c2', $unknown, ['tool_name' => 'a<mark>'], null, true);

        $this->assertSame(
            ['text' => 'x< mark>y', 'forms_id' => StoredTexts::formsId(['<mark>'])],
            self::texts()->withRendered($plain)->content['rendered'],
        );
        $text = self::texts()->withRendered($templated)->content['rendered']['text'] ?? null;
        $this->assertSame('unknown_tool:{"tool_name":"a< mark>"}', $text);
    }

    public function test_an_interruption_is_rendered_by_its_form(): void
    {
        $during = self::texts()->withRendered(ConversationItem::interruption(true));
        $outside = self::texts()->withRendered(ConversationItem::interruption(false));

        $this->assertSame('interrupted_for_tool_use:[]', $during->content['rendered']['text'] ?? null);
        $this->assertSame('interrupted:[]', $outside->content['rendered']['text'] ?? null);
    }

    /** 送る文: 同じ印なら組んだ文(今の版の文と違っても)、違う印か組んでいなければ今の版で組む */
    public function test_the_sent_text_uses_the_stored_text_only_with_the_same_forms(): void
    {
        $same = ['text' => '組んだ文', 'forms_id' => StoredTexts::formsId(['<mark>'])];
        $other = ['text' => '組んだ文', 'forms_id' => StoredTexts::formsId(['<old>'])];

        $this->assertSame('組んだ文', self::texts()->sent(self::bodyResult($same)));
        $this->assertSame('b< mark>', self::texts()->sent(self::bodyResult($other)));
        $this->assertSame('b< mark>', self::texts()->sent(self::bodyResult(null)));
        $this->assertSame('組んだ文', self::texts()->sent(ConversationItem::interruption(false, $same)));
        $this->assertSame('interrupted:[]', self::texts()->sent(ConversationItem::interruption(false, $other)));
    }

    public function test_other_items_keep_no_text(): void
    {
        $user = ConversationItem::userText('問い');

        $this->assertSame($user, self::texts()->withRendered($user));
        $this->expectException(LogicException::class);
        self::texts()->sent($user);
    }

    /**
     * @param  array{text: string, forms_id: string}|null  $rendered
     */
    private static function bodyResult(?array $rendered): ConversationItem
    {
        return ConversationItem::toolResult('c1', null, [], 'b<mark>', false, $rendered);
    }
}
