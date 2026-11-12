<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Tools\BaseTool;

/**
 * 約束を実装する基底のクラスを、道具の名前空間の外で継ぐのは禁止
 */
abstract class ExtendsBaseOutside extends BaseTool {}
