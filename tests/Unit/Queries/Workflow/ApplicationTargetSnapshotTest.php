<?php

namespace Tests\Unit\Queries\Workflow;

use App\Queries\Workflow\ApplicationTargetSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * 申請対象の値の写しの規則(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1)。
 * 定義は RecordFormQuery::definition の形。
 */
final class ApplicationTargetSnapshotTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function field(string $code, string $type = 'string', array $extra = []): array
    {
        return [
            'code' => $code, 'name' => $code, 'type' => $type, 'isMultiple' => false,
            'referencedModule' => null, 'childModule' => null,
            ...$extra,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function module(string $code, array $fields): array
    {
        return ['code' => $code, 'name' => $code, 'fields' => $fields, 'rules' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ref(string $code, string $module, bool $multiple = false, string $type = 'record'): array
    {
        $referenced = ['code' => $module, 'name' => $module];

        return self::field($code, $type, ['referencedModule' => $referenced, 'isMultiple' => $multiple]);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function details(string $code, array $fields): array
    {
        return self::field($code, 'details', ['childModule' => self::module($code, $fields)]);
    }

    private function snapshotter(): ApplicationTargetSnapshot
    {
        return new ApplicationTargetSnapshot;
    }

    public function test_same_code_and_shape_are_copied_and_others_are_not(): void
    {
        $application = self::module('app', [
            self::field('name'), self::field('amount', 'integer'), self::field('kind', 'integer'),
            self::field('only_app'),
            self::ref('owner', 'user', false, 'user'), self::ref('members', 'user', true, 'user'),
            self::ref('customer', 'customers'), self::ref('partner', 'customers'),
            self::ref('target', 'projects'),
        ]);
        $applicationTarget = self::module('projects', [
            self::field('name', 'string', ['maxLength' => 5, 'isRequired' => true]),
            self::field('amount', 'integer'), self::field('kind', 'string'), self::field('only_target'),
            self::ref('owner', 'user', false, 'user'), self::ref('members', 'user', false, 'user'),
            self::ref('customer', 'customers'), self::ref('partner', 'vendors'),
            self::ref('target', 'projects'),
        ]);

        $fields = $this->snapshotter()->fields($application, $applicationTarget, 'target');

        $this->assertSame(['name', 'amount', 'owner', 'customer'], $fields['fields']);
        $this->assertSame([], $fields['details']);
    }

    public function test_details_rows_are_copied_as_new_rows_in_order_with_the_same_rule_inside(): void
    {
        $application = self::module('app', [
            self::details('lines', [self::field('item'), self::field('qty', 'integer'), self::field('note')]),
            self::details('other', [self::field('x')]),
            self::details('app_only', [self::field('x')]),
            self::field('lines_like'),
        ]);
        $applicationTarget = self::module('projects', [
            self::details('lines', [self::field('item'), self::field('qty', 'string'), self::field('extra')]),
            self::field('other'),
            self::details('empty', [self::field('x')]),
            self::details('lines_like', [self::field('x')]),
        ]);

        $matched = $this->snapshotter()->fields($application, $applicationTarget, 'target');
        $this->assertSame([], $matched['fields']);
        $this->assertSame(['lines' => ['item']], $matched['details']);

        $snapshot = $this->snapshotter()->snapshot($application, $applicationTarget, [], [
            'lines' => [
                ['id' => 7, 'values' => ['item' => 'A', 'qty' => 1, 'extra' => 'e']],
                ['id' => 3, 'values' => ['item' => 'B']],
            ],
            'empty' => [],
        ], 'target', 5);

        $this->assertSame(
            ['lines' => [['values' => ['item' => 'A']], ['values' => ['item' => 'B']]]],
            $snapshot['details'],
        );
    }

    public function test_missing_keys_become_null_but_present_values_are_copied_as_they_are(): void
    {
        $fields = [
            self::field('a'), self::field('b'), self::field('c', 'integer'), self::field('d', 'boolean'),
            self::field('e', 'integer'), self::field('f', 'user', ['isMultiple' => true]),
        ];
        $lines = self::details('lines', [self::field('item'), self::field('qty')]);
        $application = self::module('app', [...$fields, $lines]);
        $applicationTarget = self::module('projects', [...$fields, $lines]);
        $values = ['a' => '', 'c' => null, 'd' => false, 'e' => 0, 'f' => []];

        $snapshot = $this->snapshotter()->snapshot($application, $applicationTarget, $values, [
            'lines' => [['id' => 1, 'values' => ['item' => '']], ['id' => 2]],
        ], 'target', null);

        $this->assertSame(
            ['a' => '', 'b' => null, 'c' => null, 'd' => false, 'e' => 0, 'f' => [], 'target' => null],
            $snapshot['values'],
        );
        $this->assertSame([
            'lines' => [
                ['values' => ['item' => '', 'qty' => null]],
                ['values' => ['item' => null, 'qty' => null]],
            ],
        ], $snapshot['details']);
    }

    public function test_the_detail_with_no_common_row_field_is_not_copied_at_all(): void
    {
        $application = self::module('app', [
            self::details('lines', [self::field('item'), self::field('qty', 'integer')]),
            self::details('notes', [self::field('text')]),
        ]);
        $applicationTarget = self::module('projects', [
            self::details('lines', [self::field('item'), self::field('qty', 'string')]),
            self::details('notes', [self::field('body')]),
        ]);
        $details = [
            'lines' => [['id' => 1, 'values' => ['item' => 'A']]],
            'notes' => [['id' => 2, 'values' => ['body' => 'b']], ['id' => 3, 'values' => ['body' => 'c']]],
        ];

        $matched = $this->snapshotter()->fields($application, $applicationTarget, 'target');
        $snapshot = $this->snapshotter()->snapshot($application, $applicationTarget, [], $details, 'target', 1);

        $this->assertSame(['lines' => ['item']], $matched['details']);
        $this->assertSame(['lines'], array_keys($snapshot['details']));
    }

    public function test_the_detail_with_no_source_rows_copies_no_rows(): void
    {
        $module = self::module('m', [self::details('lines', [self::field('item')])]);

        $snapshot = $this->snapshotter()->snapshot($module, $module, [], [], 'target', 1);

        $this->assertSame(['lines' => []], $snapshot['details']);
    }

    public function test_the_target_field_gets_the_target_even_when_the_rule_would_match_it(): void
    {
        $application = self::module('app', [self::field('name'), self::ref('target', 'projects')]);
        $applicationTarget = self::module('projects', [self::field('name'), self::ref('target', 'projects')]);
        $values = ['name' => 'N', 'target' => ['id' => 9]];

        $matched = $this->snapshotter()->fields($application, $applicationTarget, 'target');
        $snapshot = $this->snapshotter()->snapshot($application, $applicationTarget, $values, [], 'target', 42);

        $this->assertSame(['name'], $matched['fields']);
        $this->assertSame(['name' => 'N', 'target' => 42], $snapshot['values']);
    }

    public function test_snapshot_keys_are_the_fields_and_the_target_field_only(): void
    {
        $application = self::module('app', [
            self::field('name'), self::field('amount', 'integer'), self::ref('target', 'projects'),
            self::details('lines', [self::field('item'), self::field('qty', 'integer')]),
        ]);
        $applicationTarget = self::module('projects', [
            self::field('name'), self::field('amount', 'string'), self::field('stray'),
            self::details('lines', [self::field('item'), self::field('qty', 'string')]),
        ]);
        $details = ['lines' => [['id' => 1, 'values' => ['item' => 'A', 'qty' => 'q']]]];

        $matched = $this->snapshotter()->fields($application, $applicationTarget, 'target');
        $values = ['name' => 'N', 'amount' => 1, 'stray' => 'x'];
        $snapshot = $this->snapshotter()->snapshot($application, $applicationTarget, $values, $details, 'target', 3);

        $this->assertSame([...$matched['fields'], 'target'], array_keys($snapshot['values']));
        $this->assertSame(array_keys($matched['details']), array_keys($snapshot['details']));
        $this->assertSame(['item'], array_keys($snapshot['details']['lines'][0]['values']));
        $this->assertSame(['lines' => ['item']], $matched['details']);
    }
}
