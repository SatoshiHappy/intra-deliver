<?php

namespace Database\Seeders;

use App\Actions\Master\CreateCompany;
use App\Actions\Master\CreateDepartment;
use App\Actions\Master\CreateDepartmentInclusion;
use App\Actions\Master\CreateLocale;
use App\Actions\Master\SplitDepartmentTerm;
use App\Support\Period;
use Illuminate\Database\Seeder;

/**
 * 初期データ: ロケール・会社・組織と組織の階層(架空の「株式会社デリバー商事」)。
 *
 * 2024-04-01 に始まり、2025-04-01 に組織変更がある: 営業部 → 第一営業部(名称の履歴 = 期間の分割)、第二営業部の新設、
 * 品質保証部の付け替え(開発本部 → 管理本部)。業務処理を通して入れる(業務処理で止める規則を守るため)。
 */
class OrganizationSeeder extends Seeder
{
    use FindsCommonMaster;

    public const START = '2024-04-01';

    public const REORGANIZATION = '2025-04-01';

    /** 組織(頂点を除く): コード => [名称(ja), 名称(en), 開始日, 並び順] */
    private const DEPARTMENTS = [
        'planning' => ['経営企画室', 'Corporate Planning Office', self::START, 10],
        'administration' => ['管理本部', 'Administration Division', self::START, 20],
        'general-affairs' => ['総務部', 'General Affairs Dept.', self::START, 21],
        'accounting' => ['経理部', 'Accounting Dept.', self::START, 22],
        'human-resources' => ['人事部', 'Human Resources Dept.', self::START, 23],
        'sales' => ['営業本部', 'Sales Division', self::START, 30],
        'sales-1' => ['営業部', 'Sales Dept.', self::START, 31],
        'tokyo-sales' => ['東京営業課', 'Tokyo Sales Section', self::START, 32],
        'sales-2' => ['第二営業部', 'Sales Dept. 2', self::REORGANIZATION, 33],
        'engineering' => ['開発本部', 'Engineering Division', self::START, 40],
        'development' => ['開発部', 'Development Dept.', self::START, 41],
        'quality-assurance' => ['品質保証部', 'Quality Assurance Dept.', self::START, 42],
    ];

    /** 内包: [親, 子, 開始日, 終了日(null = 上限なし)]。兄弟の並び順は子の組織の並び順 */
    private const INCLUSIONS = [
        ['deliver', 'planning', self::START, null],
        ['deliver', 'administration', self::START, null],
        ['administration', 'general-affairs', self::START, null],
        ['administration', 'accounting', self::START, null],
        ['administration', 'human-resources', self::START, null],
        ['deliver', 'sales', self::START, null],
        ['sales', 'sales-1', self::START, null],
        ['sales-1', 'tokyo-sales', self::START, null],
        ['sales', 'sales-2', self::REORGANIZATION, null],
        ['deliver', 'engineering', self::START, null],
        ['engineering', 'development', self::START, null],
        ['engineering', 'quality-assurance', self::START, self::REORGANIZATION],
        ['administration', 'quality-assurance', self::REORGANIZATION, null],
    ];

    public function run(
        CreateLocale $createLocale,
        CreateCompany $createCompany,
        CreateDepartment $createDepartment,
        CreateDepartmentInclusion $createInclusion,
        SplitDepartmentTerm $splitDepartmentTerm,
    ): void {
        $createLocale->execute('ja', 1);
        $createLocale->execute('en', 2);
        $createCompany->execute('deliver', 'deliver', 'deliver', self::day(self::START), [
            'ja' => ['name' => '株式会社デリバー商事', 'short_name' => 'デリバー商事'],
            'en' => ['name' => 'Deliver Trading Inc.', 'short_name' => 'Deliver Trading'],
        ]);
        $setId = $this->departmentSetId();

        foreach (self::DEPARTMENTS as $code => [$ja, $en, $start, $sortKey]) {
            $createDepartment->execute($setId, $code, self::day($start), ['ja' => ['name' => $ja], 'en' => ['name' => $en]], $sortKey);
        }

        $splitDepartmentTerm->execute($this->departmentId('sales-1'), self::day(self::REORGANIZATION), [
            'ja' => ['name' => '第一営業部'],
            'en' => ['name' => 'Sales Dept. 1'],
        ]);

        foreach (self::INCLUSIONS as [$parent, $child, $start, $end]) {
            $period = new Period(self::day($start), $end === null ? null : self::day($end));
            $createInclusion->execute($this->departmentId($parent), $this->departmentId($child), $period, self::DEPARTMENTS[$child][3]);
        }
    }
}
