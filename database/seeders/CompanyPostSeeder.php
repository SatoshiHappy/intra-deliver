<?php

namespace Database\Seeders;

use App\Actions\Master\CreateCompanyPost;
use Illuminate\Database\Seeder;

/**
 * 初期データ: 役職とランク(小さいほど上位)。課長と同じランクの「担当課長」は、比較(以下・以上)の見本。
 */
class CompanyPostSeeder extends Seeder
{
    use FindsCommonMaster;

    /** 役職: コード => [名称(ja), 名称(en), ランク] */
    private const POSTS = [
        'president' => ['社長', 'President', 10],
        'division-head' => ['本部長', 'Division Head', 20],
        'manager' => ['部長', 'General Manager', 30],
        'section-chief' => ['課長', 'Section Chief', 40],
        'assistant-manager' => ['担当課長', 'Assistant Manager', 40],
        'chief' => ['係長', 'Chief', 50],
        'senior' => ['主任', 'Senior Staff', 60],
    ];

    public function run(CreateCompanyPost $createCompanyPost): void
    {
        $setId = $this->departmentSetId();

        foreach (self::POSTS as $code => [$ja, $en, $rank]) {
            $createCompanyPost->execute($setId, $code, $rank, self::day(OrganizationSeeder::START), ['ja' => ['name' => $ja], 'en' => ['name' => $en]], $rank);
        }
    }
}
