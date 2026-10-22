<?php

namespace App\Actions\Ai;

use RuntimeException;

/**
 * 音声の変換・区切り・長さの測りの失敗(内部。{@see AudioConverter} が投げ、呼び手が失敗の種類 conversion にする =
 * docs の 設計/AI/データと失敗と検査.md 7 節)。外部のプロセスの誤り・時間切れを含む。元の例外があれば previous。
 */
class AudioConversionFailed extends RuntimeException {}
