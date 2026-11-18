<?php

namespace App\Queries\Notice;

use App\Support\AccessContext;
use Closure;
use LogicException;

/**
 * 通知の設定の 2 つの画面の props(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.2・3.4・4.2)。
 * 面は種類の接頭辞ごと(1 つの実装は 1 つの接頭辞だけを答える = 種類と作る規則.md の 1 節)。面の見出しと種類の語は渡さない
 * (語の正本は画面の雛形 = 同じ 1 節)。ミュートと必須の印は {@see NoticeReceivingQuery} が送る口と同じ規則で答える(必須の行は
 * ミュートしていない値)。
 *
 * @phpstan-type OwnRow array{type: string, muted: bool, isRequiredType: bool}
 * @phpstan-type AdminRow array{type: string, isRequiredType: bool}
 */
class NoticeSettingsQuery
{
    public function __construct(
        private NoticeTypeCatalog $types,
        private NoticeReceivingQuery $receiving,
    ) {}

    /**
     * 利用者の画面(3.2・3.4)。
     *
     * @return array{surfaces: list<array{prefix: string, rows: list<OwnRow>}>}
     *
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function own(AccessContext $context): array
    {
        if ($context->userId === null) {
            return ['surfaces' => []];
        }

        $required = array_flip($this->receiving->requiredTypes());
        $muted = array_flip($this->receiving->mutedTypesOf($context->userId));

        return ['surfaces' => $this->surfaces(fn (string $type) => [
            'type' => $type,
            'muted' => isset($muted[$type]),
            'isRequiredType' => isset($required[$type]),
        ])];
    }

    /**
     * 管理の画面(4.2)。
     *
     * @return array{surfaces: list<array{prefix: string, rows: list<AdminRow>}>}
     *
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function admin(): array
    {
        $required = array_flip($this->receiving->requiredTypes());

        return ['surfaces' => $this->surfaces(fn (string $type) => [
            'type' => $type,
            'isRequiredType' => isset($required[$type]),
        ])];
    }

    /**
     * 一覧の種類を接頭辞ごとの面に分ける(並び = 一覧の並び。種類が無い接頭辞は面を作らない)。
     *
     * @template TRow of array<string, mixed>
     *
     * @param  Closure(string): TRow  $row
     * @return list<array{prefix: string, rows: list<TRow>}>
     *
     * @throws LogicException
     */
    private function surfaces(Closure $row): array
    {
        $surfaces = [];

        foreach ($this->types->all() as $type) {
            $prefix = explode('.', $type, 2)[0];
            $surfaces[$prefix] ??= ['prefix' => $prefix, 'rows' => []];
            $surfaces[$prefix]['rows'][] = $row($type);
        }

        return array_values($surfaces);
    }
}
