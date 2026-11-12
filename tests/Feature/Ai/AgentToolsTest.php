<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\ToolCalls\AgentTools;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\Feature\Ai\Tools\Generic\GenericTool;
use Tests\Feature\Ai\Tools\Product\Widget\WidgetTool;

/**
 * 道具の登録を束ねる 1 か所(docs の 設計/AI/道具と権限/README.md 10.1 の名前・渡す道具の並び): 名前でも別名でも 1 回で引け、名前だけで引くときは
 * 別名で当てない(10.3 の A5)。並びは汎用を名前の順に頭に置き、製品を名前の順に続ける。汎用か製品かと名前の頭は、名前空間の頭の対応の
 * いちばん長い物で決める(既定の対応では Ai の道具の名前空間が汎用、ほかの App\Actions\<M>\ が製品)。
 */
final class AgentToolsTest extends TestCase
{
    public function test_a_tool_is_found_by_its_name_or_alias_and_named_only_by_its_name(): void
    {
        $tool = new GenericTool('ai_read_v2', aliases: ['ai_read_v1']);
        $tools = FakeTools::registry([FakeTools::create(), $tool]);

        $found = [$tools->find('ai_read_v2'), $tools->find('ai_read_v1'), $tools->find('ai_x')];
        $this->assertSame([$tool, $tool, null], $found);
        $this->assertSame([$tool, null], [$tools->named('ai_read_v2'), $tools->named('ai_read_v1')]);
    }

    public function test_generic_tools_come_first_by_name_then_product_tools_by_name(): void
    {
        $tools = FakeTools::registry([
            new WidgetTool('widget_b', targets: ['m']),
            FakeTools::update('ai_c'),
            new WidgetTool('widget_a', targets: ['m']),
            FakeTools::read('ai_a'),
        ]);

        $this->assertSame(['ai_a', 'ai_c', 'widget_a', 'widget_b'], array_map(
            fn (AgentTool $tool): string => $tool->name(),
            $tools->all(),
        ));
        $prefixes = [$tools->prefixOf(FakeTools::read()), $tools->prefixOf(new WidgetTool('w'))];
        $this->assertSame(['ai_', 'widget_'], $prefixes);
    }

    /**
     * 2 つ以上の頭が当たるクラスは、いちばん長い頭の対応を使う(既定の対応 = Ai の道具の名前空間は App\Actions\ より長いので汎用。
     * 頭が null の対応は次の段の名前の蛇の形 + _)
     */
    public function test_the_longest_namespace_prefix_decides_the_kind_and_the_prefix(): void
    {
        $tools = new AgentTools([], [
            ['namespace' => 'Tests\Feature\Ai\Tools\\', 'prefix' => null, 'generic' => false],
            ['namespace' => 'Tests\Feature\Ai\Tools\Generic\\', 'prefix' => 'ai_', 'generic' => true],
        ]);
        $product = new WidgetTool('product_w', targets: ['m']);

        $this->assertSame([true, 'ai_'], [$tools->isGeneric(FakeTools::read()), $tools->prefixOf(FakeTools::read())]);
        $this->assertSame([false, 'product_'], [$tools->isGeneric($product), $tools->prefixOf($product)]);
        $this->assertSame([
            ['namespace' => 'App\Actions\Ai\Tools\\', 'prefix' => 'ai_', 'generic' => true],
            ['namespace' => 'App\Actions\\', 'prefix' => null, 'generic' => false],
        ], AgentTools::DEFAULT_NAMESPACES);
    }
}
