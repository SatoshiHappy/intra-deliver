<?php

namespace App\Actions\Ai;

/**
 * 音声の長さを測れない(壊れた音声・音声の流れが無い。内部)。依頼の口は 422「音声を読み取れませんでした」に、実行の中では
 * 変換の失敗にする(docs の 設計/AI/公開の口.md 3 節の文字起こしの依頼の行)。時間切れはこの型にしない(音声のせいと限らない)。
 */
final class AudioUnreadable extends AudioConversionFailed {}
