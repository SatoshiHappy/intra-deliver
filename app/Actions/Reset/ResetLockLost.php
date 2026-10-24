<?php

namespace App\Actions\Reset;

use RuntimeException;

/**
 * 初期化の途中で鎖錠を失った(接続が切れてつなぎ直したなど)。扱い = docs の 設計/公開の配備/毎日の初期化.md 4 節。
 */
class ResetLockLost extends RuntimeException {}
