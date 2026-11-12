<?php

namespace App\Actions\Ai\ToolsLookalike;

use App\Actions\Ai\Api\AgentTool;

/**
 * 名前空間の頭だけが道具の名前空間と同じ別の名前空間で、約束を実装するのは禁止
 */
abstract class LookalikeTool implements AgentTool {}
