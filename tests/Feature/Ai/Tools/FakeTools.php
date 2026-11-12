<?php

namespace Tests\Feature\Ai\Tools;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Enums\Ai\AiToolAction;
use Tests\Feature\Ai\Tools\Generic\GenericTool;
use Tests\Feature\Ai\Tools\Product\Widget\WidgetTool;

/**
 * 偽の道具の名前空間の頭の対応と、よく使う偽の道具(汎用の読む・作る・直すと、製品の読む)。
 */
final class FakeTools
{
    /** 偽の道具の頭の対応(汎用 = Generic → ai_、製品 = Product\<M>\ → <M> の蛇の形 + _) */
    public const NAMESPACES = [
        ['namespace' => 'Tests\Feature\Ai\Tools\Generic\\', 'prefix' => 'ai_', 'generic' => true],
        ['namespace' => 'Tests\Feature\Ai\Tools\Product\\', 'prefix' => null, 'generic' => false],
    ];

    /**
     * @param  list<AgentTool>  $tools
     */
    public static function registry(array $tools): AgentTools
    {
        return new AgentTools($tools, self::NAMESPACES);
    }

    public static function read(string $name = 'ai_read'): GenericTool
    {
        return new GenericTool($name);
    }

    public static function create(string $name = 'ai_create'): GenericTool
    {
        return new GenericTool($name, readOnly: false, action: AiToolAction::Create);
    }

    public static function update(string $name = 'ai_update'): GenericTool
    {
        return new GenericTool($name, readOnly: false, action: AiToolAction::Update);
    }

    /**
     * @param  list<string>  $targets
     */
    public static function productRead(array $targets, string $name = 'widget_read'): WidgetTool
    {
        return new WidgetTool($name, targets: $targets);
    }
}
