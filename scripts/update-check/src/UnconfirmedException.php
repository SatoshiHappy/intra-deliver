<?php

namespace UpdateCheck;

use RuntimeException;

/**
 * 項目を確認できなかった(出力が読めない・期待した鍵が無い・取得できない・時間切れなど)。理由は報告に書く。
 */
class UnconfirmedException extends RuntimeException {}
