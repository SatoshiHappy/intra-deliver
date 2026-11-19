<?php

namespace App\Actions\Ai\Api;

/**
 * 止める口の返す物(docs の 設計/AI/公開の口.md 3 節の止めるの行、設計/AI/エージェントの実行/道具の包みと止める.md 9.5)。
 */
enum StopOutcome: string
{
    /** 待ちの実行を止めた(利用者)で終えた */
    case StoppedWhileQueued = 'stopped_while_queued';

    /** 処理中の実行に止める印を書いた(印がもうあれば書き直さない) */
    case Marked = 'marked';

    /** 終わっていて何もしなかった(確認待ちを含む) */
    case AlreadyFinished = 'already_finished';
}
