<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\AgentRecordTool;

/**
 * 道具の約束を継いだ interface を、道具の名前空間の外で実装するのは禁止
 */
abstract class OutsideRecordTool implements AgentRecordTool {}
