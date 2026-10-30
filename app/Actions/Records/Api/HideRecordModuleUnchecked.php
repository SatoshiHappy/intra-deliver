<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\UpdateRecordModule;
use App\Models\Records\RecordModule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 業務モジュールを判定せずに非表示にする公開の口(止めを立てる書き込みの業務処理だけが呼ぶ = ADR 0031 の決定 2。保証の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表と「表示の止めを立てる側の保証」)。業務モジュールを変える業務処理
 * {@see UpdateRecordModule} を is_visible = 偽だけの入力で通す(新しい規則を作らない = 失敗と定義の変更.md 11 節の「表示の止め」)。
 */
class HideRecordModuleUnchecked
{
    public function __construct(private UpdateRecordModule $updateModule) {}

    /**
     * 主体の判定をしない。呼び手(止めを立てる書き込みの業務処理)が、自分のトランザクションの初めに、止めを書く前に呼ぶ。
     *
     * 鍵は業務モジュールを変える業務処理と同じ(共通マスタ → 業務モジュールの排他の勧告ロック)で、呼び手のトランザクションの終わりまで残る
     * (呼び手は鍵の下で検証と書き込みを行う)。「業務」のアイテムを消す(既に非表示なら変えない)。外側のトランザクションの中では savepoint になり、
     * デッドロックを含む例外はやり直さずに伝わる(やり直すかは呼び手が決める)。
     *
     * @throws LogicException 業務モジュールが無い・明細のモジュールのとき(呼び手の作りの誤り)
     * @throws ValidationException 下位の業務処理(メニューを消す)が拒むとき(キー is_visible)
     */
    public function execute(string $moduleCode): void
    {
        $isTopLevel = RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')->exists();

        if (! $isTopLevel) {
            throw new LogicException("非表示にする業務モジュールがありません(明細のモジュールを含む): {$moduleCode}");
        }

        $this->updateModule->execute($moduleCode, ['is_visible' => false]);
    }
}
