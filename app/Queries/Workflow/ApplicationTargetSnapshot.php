<?php

namespace App\Queries\Workflow;

/**
 * 申請対象(業務レコード)の値を申請書に写す規則を 1 か所に置く。申請の画面の初期値と写しの値の入口が同じ規則を使うため、判定も読み取りもしない
 * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1・10.3 節)。
 *
 * @phpstan-type SnapshotFields array{fields: list<string>, details: array<string, list<string>>}
 */
class ApplicationTargetSnapshot
{
    /**
     * 写す項目のコードと、同じコードの明細の項目ごとの写す行の項目のコード。申請対象の項目は含めない(元のレコードを入れるため)。
     * 行の中に写す項目が無い明細の項目は含めない(写す値の無い行を作らないため)。
     *
     * @param  array<string, mixed>  $applicationDefinition  申請書の業務モジュールの定義
     * @param  array<string, mixed>  $applicationTargetDefinition  申請対象の業務モジュールの定義
     * @param  string  $applicationTargetFieldCode  申請書の業務モジュールの申請対象の項目のコード
     * @return SnapshotFields
     */
    public function fields(
        array $applicationDefinition,
        array $applicationTargetDefinition,
        string $applicationTargetFieldCode,
    ): array {
        $fields = [];
        $details = [];

        $pairs = $this->matched($applicationDefinition, $applicationTargetDefinition, $applicationTargetFieldCode);

        foreach ($pairs as $code => [$application, $applicationTarget]) {
            if ($application['type'] !== 'details') {
                $fields[] = $code;
            } elseif (is_array($application['childModule'] ?? null)
                && is_array($applicationTarget['childModule'] ?? null)) {
                $valueCodes = $this->valueCodes($application['childModule'], $applicationTarget['childModule']);

                if ($valueCodes !== []) {
                    $details[$code] = $valueCodes;
                }
            }
        }

        return ['fields' => $fields, 'details' => $details];
    }

    /**
     * 写す値。元に無い値は null、明細の行は `id` を外して並びのまま。申請対象の項目には $applicationTarget を入れる。
     *
     * @param  array<string, mixed>  $applicationDefinition
     * @param  array<string, mixed>  $applicationTargetDefinition
     * @param  array<array-key, mixed>  $values  申請対象の項目のコード → 値
     * @param  array<array-key, mixed>  $details  申請対象の明細の項目のコード → 行(行 = `values` を持つ配列)
     * @return array{values: array<string, mixed>, details: array<string, list<array{values: array<string, mixed>}>>}
     */
    public function snapshot(
        array $applicationDefinition,
        array $applicationTargetDefinition,
        array $values,
        array $details,
        string $applicationTargetFieldCode,
        mixed $applicationTarget,
    ): array {
        $fields = $this->fields($applicationDefinition, $applicationTargetDefinition, $applicationTargetFieldCode);
        $snapshot = $this->pick($values, $fields['fields']);
        $snapshot[$applicationTargetFieldCode] = $applicationTarget;
        $rows = [];

        foreach ($fields['details'] as $code => $codes) {
            $source = $details[$code] ?? [];
            $rows[$code] = array_values(array_map(
                fn (mixed $row): array => ['values' => $this->pick($this->rowValues($row), $codes)],
                is_array($source) ? $source : [],
            ));
        }

        return ['values' => $snapshot, 'details' => $rows];
    }

    /**
     * 明細のモジュールどうしで写す行の項目のコード(明細は入れ子にならないので、明細の型の項目は写さない)。
     *
     * @param  array<array-key, mixed>  $application
     * @param  array<array-key, mixed>  $applicationTarget
     * @return list<string>
     */
    private function valueCodes(array $application, array $applicationTarget): array
    {
        $codes = [];

        foreach ($this->matched($application, $applicationTarget, null) as $code => [$field]) {
            if ($field['type'] !== 'details') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * 同じコードで値の形を決める属性(型・複数・参照先)が同じ項目の組。コード → [申請書の項目, 申請対象の項目]。$excludedCode は組にしない。
     *
     * @param  array<array-key, mixed>  $application
     * @param  array<array-key, mixed>  $applicationTarget
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function matched(array $application, array $applicationTarget, ?string $excludedCode): array
    {
        $applicationTargetFields = $this->byCode($applicationTarget);
        $pairs = [];

        foreach ($this->byCode($application) as $code => $field) {
            $counterpart = $applicationTargetFields[$code] ?? null;

            if ($code !== $excludedCode && $counterpart !== null && $this->sameShape($field, $counterpart)) {
                $pairs[$code] = [$field, $counterpart];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    private function byCode(array $definition): array
    {
        $byCode = [];

        foreach (is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['code'] ?? null)) {
                $byCode[$field['code']] = $field;
            }
        }

        return $byCode;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameShape(array $a, array $b): bool
    {
        return ($a['type'] ?? null) === ($b['type'] ?? null)
            && (bool) ($a['isMultiple'] ?? false) === (bool) ($b['isMultiple'] ?? false)
            && $this->referencedCode($a) === $this->referencedCode($b);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function referencedCode(array $field): ?string
    {
        $referenced = $field['referencedModule'] ?? null;

        return is_array($referenced) && is_string($referenced['code'] ?? null) ? $referenced['code'] : null;
    }

    /**
     * 明細の行の値(行 = `values` を持つ配列。`id` は見ない)。
     *
     * @return array<array-key, mixed>
     */
    private function rowValues(mixed $row): array
    {
        return is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [];
    }

    /**
     * 元に無いキーだけ null。複製と同じく値はそのまま写し、既定値は使わない。
     *
     * @param  array<array-key, mixed>  $source
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function pick(array $source, array $codes): array
    {
        $picked = [];

        foreach ($codes as $code) {
            $picked[$code] = $source[$code] ?? null;
        }

        return $picked;
    }
}
