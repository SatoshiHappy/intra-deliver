<?php

namespace Database\Seeders;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use LogicException;

/**
 * {@see WorkflowSeeder} の案件の見本が登録・参照する業務データ(計画の案件のプロジェクトの値と、RecordsSeeder のプロジェクトを名前で引く)。
 * 使う側は departmentId()・userId() を持つ。
 */
trait BuildsWorkflowSampleRecords
{
    /** 計画の案件のプロジェクト(WF-000001・3・4・5・8 と、版 3 の WF-000009〜000014): [プロジェクト名, 担当部署のコード, 責任者のコード, 開始日, 終了日, 予算, 概要, マイルストーン [名称, 期日, 工数, 完了]] */
    private const PLANS = [
        1 => ['営業支援ツールの導入', 'tokyo-sales', 'u0002', '2026-11-02', '2027-01-29', 200, null, [['候補の比較', '2026-11-20', '5', false], ['試行', '2026-12-18', '10', false]]],
        3 => ['展示会への出展', 'sales-1', 'u0003', '2027-02-01', '2027-03-31', 450, null, [['出展の申し込み', '2026-12-11', '2', false]]],
        4 => ['販促資料の刷新', 'tokyo-sales', 'u0001', '2026-12-01', null, 80, null, []],
        5 => ['代理店向け説明会の開催', 'sales-1', 'u0003', '2026-11-10', '2027-03-31', 300, '代理店に新しい料金体系を説明する。', []],
        8 => ['社内勉強会の開催', 'tokyo-sales', 'u0001', '2026-11-05', null, 20, null, []],
        9 => ['新規顧客開拓の訪問強化', 'sales-1', 'u0003', '2026-12-01', '2027-03-31', 350, '見込み顧客のリストを作り、第一営業部で訪問と提案を進める。',
            [['対象リスト作成', '2026-11-15', '3', false], ['初回訪問', '2026-12-20', '12', false]]],
        10 => ['社内研修の刷新', 'tokyo-sales', 'u0001', '2026-12-01', null, 120, null, []],
        11 => ['海外展示会への出展', 'sales-1', 'u0003', '2027-04-01', '2027-06-30', 800, null, []],
        12 => ['営業資料の多言語化', 'tokyo-sales', 'u0001', '2026-11-16', '2027-02-26', 90, null, []],
        13 => ['新製品の発売計画', 'sales-1', 'u0003', '2026-12-01', '2027-03-31', 400, null, []],
        14 => ['第二四半期の営業計画', 'tokyo-sales', 'u0001', '2027-01-04', null, 320, null, []],
    ];

    /**
     * 計画の案件のプロジェクトの入力(状態は計画中。日付は流す日からの相対 = {@see SeedingDates})。
     *
     * @param  array<string, mixed>  $changes  上書きする値(再申請。日付は流す日からの相対にした値で渡す)
     */
    private function planInput(int $plan, array $changes = []): RecordInput
    {
        [$name, $department, $manager, $start, $end, $budget, $summary, $milestones] = self::PLANS[$plan];

        return new RecordInput([
            'name' => $name, 'department' => $this->departmentId($department), 'manager' => $this->userId($manager), 'status' => 'planning',
            'start_date' => SeedingDates::day($start), 'end_date' => SeedingDates::day($end), 'budget' => $budget, 'summary' => $summary,
            ...$changes,
        ], ['milestones' => array_map(fn (array $row) => new RecordDetailRowInput(values: [
            'name' => $row[0], 'due_date' => SeedingDates::day($row[1]), 'effort' => $row[2], 'is_done' => $row[3],
        ]), $milestones)]);
    }

    /**
     * 業務データの入れ物の初期データのプロジェクト(プロジェクト名で引く)。
     */
    private function projectIdOf(string $name): int
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $nameField = RecordField::query()->where('record_module_id', $module->id)->where('code', 'name')->sole();
        $project = Record::query()->where('record_module_id', $module->id)->orderBy('id')->get()
            ->first(fn (Record $record) => ($record->field_values[$nameField->id] ?? null) === $name);

        return $project->id ?? throw new LogicException("プロジェクト「{$name}」がありません");
    }
}
