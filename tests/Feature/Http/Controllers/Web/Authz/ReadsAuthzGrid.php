<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認可設定の画面(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.2)の props を読むテストの手助け: props・格子・コードで引く行・
 * アクションのセルの要約。
 */
trait ReadsAuthzGrid
{
    private const URL = '/authz/overview';

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function grid(TestResponse $response): array
    {
        return $this->props($response)['grid'];
    }

    /**
     * リソースグループ ID(code)の行。
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function row(TestResponse $response, string $code): array
    {
        $rows = array_values(array_filter($this->grid($response)['rows'], fn (array $row) => $row['code'] === $code));
        $this->assertCount(1, $rows, "{$code} の行");

        return $rows[0];
    }

    /**
     * 行のアクションのセルの要約: [対象者の id, エフェクト, 継承の置き場のコード(直接なら null)](並びのまま)。
     *
     * @param  array<string, mixed>  $row
     * @return list<array{mixed, mixed, mixed}>
     */
    private function cells(array $row, string $action): array
    {
        return array_values(array_map(
            fn (array $setting) => [$setting['subjectGroupId'], $setting['effect'], $setting['inheritedFrom']['code'] ?? null],
            array_filter($row['settings'], fn (array $setting) => $setting['action'] === $action),
        ));
    }
}
