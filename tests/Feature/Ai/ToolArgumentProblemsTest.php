<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\ToolArgumentProblems;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Ai\Tools\Generic\GenericTool;

/**
 * 引数の形の誤り(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B11)を道具の schema だけから組む: 型ごとの通る値と通らない値
 * (変換しない。空の文字と空白だけの文字は文字の型だけに通る)・null を許す型と許さない型・必須は鍵があるか(空の文字と空の配列は欠けでない)・
 * 親が無いときは子の欠けを出さない・入れ子の道と並び。
 */
final class ToolArgumentProblemsTest extends TestCase
{
    /**
     * 型の表の各行(鍵 v。必須)。
     *
     * @return array<string, array{Closure(JsonSchema): Type, mixed, string|null}>
     */
    public static function types(): array
    {
        $string = fn (JsonSchema $s): Type => $s->string();
        $integer = fn (JsonSchema $s): Type => $s->integer();
        $number = fn (JsonSchema $s): Type => $s->number();
        $boolean = fn (JsonSchema $s): Type => $s->boolean();
        $array = fn (JsonSchema $s): Type => $s->array();
        $object = fn (JsonSchema $s): Type => $s->object();

        return [
            '文字に空の文字' => [$string, '', null],
            '文字に空白だけ' => [$string, ' ', null],
            '文字に整数' => [$string, 5, 'string'],
            '文字に真偽' => [$string, true, 'string'],
            '整数に整数' => [$integer, 5, null],
            '整数に文字の整数' => [$integer, '5', 'integer'],
            '整数に浮動小数の 5.0' => [$integer, 5.0, 'integer'],
            '整数に真偽' => [$integer, true, 'integer'],
            '整数に空の文字' => [$integer, '', 'integer'],
            '整数に空白だけ' => [$integer, ' ', 'integer'],
            '数に整数' => [$number, 5, null],
            '数に浮動小数' => [$number, 1.5, null],
            '数に文字の数' => [$number, '1.5', 'number'],
            '数に真偽' => [$number, true, 'number'],
            '数に空の文字' => [$number, '', 'number'],
            '数に空白だけ' => [$number, ' ', 'number'],
            '真偽に真偽' => [$boolean, false, null],
            '真偽に文字の 1' => [$boolean, '1', 'boolean'],
            '真偽に 1' => [$boolean, 1, 'boolean'],
            '真偽に 0' => [$boolean, 0, 'boolean'],
            '真偽に空の文字' => [$boolean, '', 'boolean'],
            '真偽に空白だけ' => [$boolean, ' ', 'boolean'],
            'リストに空の配列' => [$array, [], null],
            'リストにリスト' => [$array, [1, 2], null],
            'リストに鍵のある配列' => [$array, ['a' => 1], 'array'],
            'リストに空の文字' => [$array, '', 'array'],
            'リストに空白だけ' => [$array, ' ', 'array'],
            'オブジェクトに空の配列' => [$object, [], null],
            'オブジェクトに鍵のある配列' => [$object, ['a' => 1], null],
            'オブジェクトに空でないリスト' => [$object, [1, 2], 'object'],
            'オブジェクトに空の文字' => [$object, '', 'object'],
            'オブジェクトに空白だけ' => [$object, ' ', 'object'],
            'null を許さない型に null' => [$integer, null, 'integer'],
            'null を許す型に null' => [fn (JsonSchema $s): Type => $s->integer()->nullable(), null, null],
            'null を許す型に違う型' => [fn (JsonSchema $s): Type => $s->integer()->nullable(), '5', 'integer'],
        ];
    }

    #[DataProvider('types')]
    public function test_each_type_passes_only_its_php_values(Closure $type, mixed $value, ?string $expected): void
    {
        $problems = self::problems(fn (JsonSchema $s): array => ['v' => $type($s)->required()], ['v' => $value]);

        $this->assertSame($expected === null ? [] : ["v: 型が違う({$expected})"], $problems);
    }

    /** 必須は鍵があるか(空の文字と空の配列は欠けでない)。無ければ欠け */
    public function test_required_means_the_key_is_present(): void
    {
        $shape = fn (JsonSchema $s): array => ['name' => $s->string()->required(), 'tags' => $s->array()->required()];

        $this->assertSame([], self::problems($shape, ['name' => '', 'tags' => []]));
        $this->assertSame(['name: 欠けている', 'tags: 欠けている'], self::problems($shape, []));
    }

    /** 入れ子の必須は親の値があって型が合うときだけ照らし、道は点でつなぐ(リストの要素は番号) */
    public function test_nested_keys_are_checked_only_under_a_present_parent(): void
    {
        $shape = fn (JsonSchema $s): array => [
            'filter' => $s->object(fn (JsonSchema $s): array => ['field' => $s->string()->required()]),
            'items' => $s->array()->items($s->integer()),
        ];

        $this->assertSame([], self::problems($shape, []));
        $this->assertSame(['filter.field: 欠けている'], self::problems($shape, ['filter' => []]));
        $this->assertSame(['filter: 型が違う(object)'], self::problems($shape, ['filter' => 'x']));
        $this->assertSame(['items.1: 型が違う(integer)'], self::problems($shape, ['items' => [1, '2']]));
    }

    /** 並び = schema の鍵の順(深さ優先)の欠けと型の違い、その後に引数の順の知らない鍵(最上位だけ) */
    public function test_problems_follow_the_schema_order_then_the_unknown_keys(): void
    {
        $shape = fn (JsonSchema $s): array => [
            'a' => $s->object(fn (JsonSchema $s): array => ['b' => $s->integer()->required()]),
            'c' => $s->string()->required(),
        ];

        $problems = self::problems($shape, ['z' => 1, 'a' => ['b' => 'x', 'y' => 1], 'w' => 2]);

        $this->assertSame(['a.b: 型が違う(integer)', 'c: 欠けている', 'z: 知らない鍵', 'w: 知らない鍵'], $problems);
    }

    /**
     * @param  Closure(JsonSchema): array<string, Type>  $shape
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    private static function problems(Closure $shape, array $arguments): array
    {
        return (new ToolArgumentProblems)->of(new GenericTool('ai_r', shape: $shape), $arguments);
    }
}
