<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordScreenAddons;
use App\Support\AccessContext;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 後ろのモジュールが一覧と詳細に足すもの(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)を、タグ {@see RecordScreenAddons::TAG}
 * の実装から作る 1 か所(内部)。形の誤りは実装の作りの誤りなので例外にし、実装の例外は握らない(予期する失敗は実装が閉じるため)。
 *
 * @phpstan-import-type RecordAddonAction from RecordScreenAddons
 * @phpstan-import-type RecordAddonBadge from RecordScreenAddons
 * @phpstan-import-type RecordIndexAddons from RecordScreenAddons
 * @phpstan-import-type RecordShowAddons from RecordScreenAddons
 */
class RecordScreenAddonCatalog
{
    /** 部品の名前の形(<モジュール名の小文字>.<名前>) */
    private const DIALOG_PATTERN = '/^[a-z][a-z0-9]*\.[a-z][A-Za-z0-9-]*\z/';

    /**
     * @param  iterable<RecordScreenAddons>  $recordScreenAddons
     */
    public function __construct(
        #[Tag(RecordScreenAddons::TAG)] private iterable $recordScreenAddons,
        private RecordScreenAddonShape $shape,
    ) {}

    /**
     * 業務の一覧の操作。
     *
     * @return RecordIndexAddons
     *
     * @throws LogicException 形の誤り・操作の code の重なり
     */
    public function index(AccessContext $context, string $moduleCode): array
    {
        $actions = [];

        foreach ($this->addonsFor($moduleCode) as $addons) {
            $index = $addons->index($context);
            $this->shape->ensureIndex($addons::class, $index);
            $actions = [...$actions, ...$index['actions']];
        }

        return ['actions' => $this->checkedActions($actions)];
    }

    /**
     * 一覧の行の札(行の id → 札。札の無い行は持たない)。行が無ければ実装を呼ばない(口の約束)。
     *
     * @param  list<int>  $recordIds
     * @return array<int, list<RecordAddonBadge>>
     *
     * @throws LogicException 形の誤り・渡していない行の id
     */
    public function badges(AccessContext $context, string $moduleCode, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $merged = [];

        foreach ($this->addonsFor($moduleCode) as $addons) {
            $badges = $addons->badges($context, $recordIds);
            $this->shape->ensureBadges($addons::class, $badges, $recordIds);

            foreach ($badges as $recordId => $list) {
                $merged[$recordId] = [...($merged[$recordId] ?? []), ...$list];
            }
        }

        return array_filter($merged, fn (array $list) => $list !== []);
    }

    /**
     * レコードの詳細の操作・見出しの右の札・項目の欄の上の注。
     *
     * @return RecordShowAddons
     *
     * @throws LogicException 形の誤り・操作の code の重なり
     */
    public function show(AccessContext $context, string $moduleCode, int $recordId): array
    {
        $merged = ['actions' => [], 'badges' => [], 'fieldNotes' => []];

        foreach ($this->addonsFor($moduleCode) as $addons) {
            $show = $addons->show($context, $recordId);
            $this->shape->ensureShow($addons::class, $show);
            $merged = [
                'actions' => [...$merged['actions'], ...$show['actions']],
                'badges' => [...$merged['badges'], ...$show['badges']],
                // 同じ項目の注は先に登録した実装を残す(配列の和は左を優先する)
                'fieldNotes' => $merged['fieldNotes'] + $show['fieldNotes'],
            ];
        }

        return [...$merged, 'actions' => $this->checkedActions($merged['actions'])];
    }

    /**
     * 足す先が $moduleCode の実装(登録の順)。
     *
     * @return list<RecordScreenAddons>
     */
    private function addonsFor(string $moduleCode): array
    {
        $matched = [];

        foreach ($this->recordScreenAddons as $addons) {
            if ($addons->moduleCode() === $moduleCode) {
                $matched[] = $addons;
            }
        }

        return $matched;
    }

    /**
     * 1 つの画面の操作の部品の名前の形と、code が重ならないこと。
     *
     * @param  list<RecordAddonAction>  $actions
     * @return list<RecordAddonAction>
     *
     * @throws LogicException 部品の名前の形・code の重なり
     */
    private function checkedActions(array $actions): array
    {
        $codes = [];

        foreach ($actions as $action) {
            if (preg_match(self::DIALOG_PATTERN, $action['dialog']) !== 1) {
                throw new LogicException("後ろのモジュールの操作 {$action['code']} の dialog の形が違う: {$action['dialog']}");
            }

            if (in_array($action['code'], $codes, true)) {
                throw new LogicException("後ろのモジュールの操作の code {$action['code']} が重なる");
            }

            $codes[] = $action['code'];
        }

        return $actions;
    }
}
