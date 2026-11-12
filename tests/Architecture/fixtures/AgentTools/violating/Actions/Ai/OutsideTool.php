<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\AgentTool;

/**
 * 道具の約束を、道具の名前空間の外で実装するのは禁止(抑制の注釈を付けても消せない)
 */
// @phpstan-ignore interfacePlacement
abstract class OutsideTool implements AgentTool {}
