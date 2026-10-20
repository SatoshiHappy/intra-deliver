<?php

namespace Database\Seeders;

use App\Actions\Master\CreateUser;
use App\Actions\Master\InvalidateUserTerm;
use App\Actions\Master\SplitUserTerm;
use Illuminate\Database\Seeder;

/**
 * 初期データ: ユーザー(氏名は架空)。役割の代表 4 人(一般社員・課長・部長・システム管理者)を並び順の先頭に置く。
 * 退職者は退職の日で期間を分けて後ろを無効にし(関係の行を作る前に行う)、入社予定者は入社の日から有効にする。
 */
class UserSeeder extends Seeder
{
    use FindsCommonMaster;

    public const RETIREMENT = '2025-10-01';

    public const JOINING = '2026-11-01';

    /** ユーザー: コード => [氏名(ja), 氏名(en), 並び順, 開始日, 退職の日(null = なし)] */
    public const USERS = [
        'u0001' => ['佐々木 陽太', 'Yota Sasaki', 1, OrganizationSeeder::START, null],
        'u0002' => ['中村 美咲', 'Misaki Nakamura', 2, OrganizationSeeder::START, null],
        'u0003' => ['高橋 誠', 'Makoto Takahashi', 3, OrganizationSeeder::START, null],
        'u0004' => ['伊藤 涼子', 'Ryoko Ito', 4, OrganizationSeeder::START, null],
        'u0005' => ['渡辺 健一', 'Kenichi Watanabe', 10, OrganizationSeeder::START, null],
        'u0006' => ['山本 浩二', 'Koji Yamamoto', 11, OrganizationSeeder::START, null],
        'u0007' => ['小林 直樹', 'Naoki Kobayashi', 12, OrganizationSeeder::START, null],
        'u0008' => ['加藤 由美', 'Yumi Kato', 13, OrganizationSeeder::START, null],
        'u0009' => ['吉田 達也', 'Tatsuya Yoshida', 14, OrganizationSeeder::START, null],
        'u0010' => ['山田 恵', 'Megumi Yamada', 15, OrganizationSeeder::START, null],
        'u0011' => ['佐藤 拓也', 'Takuya Sato', 16, OrganizationSeeder::START, null],
        'u0012' => ['松本 さくら', 'Sakura Matsumoto', 17, OrganizationSeeder::START, null],
        'u0013' => ['井上 大輔', 'Daisuke Inoue', 18, OrganizationSeeder::START, null],
        'u0014' => ['木村 綾', 'Aya Kimura', 19, OrganizationSeeder::START, self::RETIREMENT],
        'u0015' => ['林 翔太', 'Shota Hayashi', 20, self::JOINING, null],
        'u0016' => ['清水 美穂', 'Miho Shimizu', 21, OrganizationSeeder::START, null],
        'u0017' => ['森 健太', 'Kenta Mori', 22, OrganizationSeeder::START, null],
    ];

    public function run(CreateUser $createUser, SplitUserTerm $splitUserTerm, InvalidateUserTerm $invalidateUserTerm): void
    {
        foreach (self::USERS as $code => [$ja, $en, $sortKey, $start, $retirement]) {
            $user = $createUser->execute($code, self::day($start), ['ja' => ['name' => $ja], 'en' => ['name' => $en]], sortKey: $sortKey);

            if ($retirement !== null) {
                $splitUserTerm->execute($user->id, self::day($retirement));
                $invalidateUserTerm->execute($user->id, self::day($retirement));
            }
        }
    }
}
