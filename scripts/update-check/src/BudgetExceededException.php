<?php

namespace UpdateCheck;

/**
 * 時間の予算(全体 25 分)が切れた。項目の「確認できなかった(時間の予算切れ)」として扱う。
 */
final class BudgetExceededException extends UnconfirmedException
{
    public function __construct()
    {
        parent::__construct('時間の予算切れ');
    }
}
