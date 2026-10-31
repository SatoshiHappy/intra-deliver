<?php

namespace Tests\Unit\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * レコードの入力のキーの一覧を固定する(型の制約のテスト)。判定しない更新 {@see UpdateRecordUnchecked} は、主体なしで確かめられない入力
 * (参照制限の 2 つのキー・関係者の印の付いた項目・明細の入力・他モジュール参照)を受けない(docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表の判定しない更新の行、ADR 0029 の決定 6)。キーを足す線が判定しない更新の
 * 断る入力を見落とさないように、一覧が変われば落とす。
 */
final class RecordInputKeysTest extends TestCase
{
    public function test_the_keys_of_the_record_input_are_the_ones_the_unchecked_update_has_reviewed(): void
    {
        $keys = array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(RecordInput::class))->getProperties(),
        );

        $this->assertSame(
            ['values', 'details', 'attachFiles', 'attachUploads', 'rowAccessRestricted', 'rowAccessViewers'],
            $keys,
            'RecordInput のキーが変わった。判定しない更新(UpdateRecordUnchecked)が受けない入力を見直す(ADR 0029 の決定 6)。'
                .'見直した後に、この一覧を新しいキーに合わせる',
        );
    }
}
