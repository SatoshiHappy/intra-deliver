<?php

namespace App\Queries\Records\Api;

use App\Support\AccessContext;

/**
 * 業務の一覧とレコードの詳細に、後ろのモジュールがダイアログを開く操作・札・注の 1 行を足す口(依存の逆転。docs の
 * 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。足すモジュールが自分の読み取りの層にこれを実装し、起動がタグ {@see self::TAG}
 * で登録する。Records は判定(一覧 = 業務モジュールの read、詳細 = レコードの閲覧)の後に、足す先が今の業務モジュールに合う実装だけを
 * 登録の順に呼び、形を確かめる。操作はダイアログを開くだけで権限を与えない(誰に出すかは実装が $context で決め、送った先の入口がもう一度判定する)。
 *
 * 予期する失敗は実装が狭い型で捕まえて報告し、空の操作・札を返す。予期しない例外は投げたまま(Records は握らない)。
 *
 * dialog = 画面の部品の名前(<モジュール名の小文字>.<名前>。例 meeting.create)、props = 部品に渡す値、disabledReason = 押せない理由
 * (Tooltip に出す)、changesValues = 値を変える操作か(持ち主のあるレコードでは Records の画面が押せない形にする)。
 *
 * @phpstan-type RecordAddonAction array{code: string, label: string, dialog: string,
 *     props: array<string, string|int|bool|null>, disabledReason: string|null, changesValues: bool}
 * @phpstan-type RecordAddonBadge array{label: string, tone: 'pending'|'returned'|'settled'|'rejected'|null}
 * @phpstan-type RecordIndexAddons array{actions: list<RecordAddonAction>}
 * @phpstan-type RecordShowAddons array{actions: list<RecordAddonAction>, badges: list<RecordAddonBadge>,
 *     fieldNotes: array<string, string>}
 */
interface RecordScreenAddons
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-screen-addons';

    /**
     * 足す先の業務モジュール(親の無い業務モジュール)のコード。定数を返すだけで、DB を読まない。
     */
    public function moduleCode(): string;

    /**
     * 業務の一覧の見出しの操作(全体の応答でだけ呼ぶ)。
     *
     * @return RecordIndexAddons
     */
    public function index(AccessContext $context): array;

    /**
     * 一覧の行の札(行の id → 札の一覧)。$recordIds = 今のページの行(行の判定で絞った後)。渡していない id は返さない。
     * 今のページに行が無ければ呼ばない。
     *
     * @param  non-empty-list<int>  $recordIds
     * @return array<int, list<RecordAddonBadge>>
     */
    public function badges(AccessContext $context, array $recordIds): array;

    /**
     * レコードの詳細の頭の操作・見出しの右の札・項目の欄の上の注(項目のコード → 1 行)。全体の応答でだけ呼ぶ。
     *
     * @return RecordShowAddons
     */
    public function show(AccessContext $context, int $recordId): array;
}
