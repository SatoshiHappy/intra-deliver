<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 業務モジュールにアイコン icon を足す(docs の 設計/業務データの入れ物/データモデル/表.md 2.1、制約の分担と検査.md 5 節)。
 * 列を NULL 可で足し、既存の親の無い行には、その業務モジュールの global-nav-main のアイテム(メニュー ID global-nav-main-records-<コード>)の
 * icon_32 が許可表に含まれる値ならそれを、それ以外(アイテムが無い・NULL・許可表の外)は table を入れてから、検査制約
 * (親の無い業務モジュールだけがアイコンを持つ)を足す。明細のモジュールは NULL のまま。
 * 許可表はこの時点の MenuIcon::recordModuleChoices() の値をここに書く(列挙を読まない。マイグレーションは固定の履歴で、後で許可表を変えても結果が変わらないように)。
 *
 * down は検査制約と列を消す(アイコンは失われる)。
 */
return new class extends Migration
{
    /** この時点の業務モジュールに選べるアイコン(MenuIcon::recordModuleChoices() の値) */
    private const CHOICES = [
        'table', 'folder-kanban', 'list-todo', 'clipboard-list', 'circle-alert',
        'users', 'building-2', 'briefcase', 'calendar', 'file-text', 'package', 'truck', 'shopping-cart', 'receipt', 'wrench',
        'book-open', 'mail', 'phone', 'chart-bar', 'tag', 'map-pin', 'ticket', 'graduation-cap', 'heart-pulse', 'coins',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('record_modules', function (Blueprint $table) {
            $table->string('icon', 100)->nullable();
        });

        $choices = implode(', ', array_fill(0, count(self::CHOICES), '?'));
        DB::update(
            "UPDATE record_modules SET icon = COALESCE(
                (SELECT menus.icon_32 FROM menus WHERE menus.code = 'global-nav-main-records-' || record_modules.code AND menus.icon_32 IN ({$choices})),
                'table'
            ) WHERE parent_record_module_id IS NULL",
            self::CHOICES,
        );
        DB::statement('ALTER TABLE record_modules ADD CONSTRAINT record_modules_parent_record_module_id_icon_check CHECK ((parent_record_module_id IS NULL) = (icon IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE record_modules DROP CONSTRAINT record_modules_parent_record_module_id_icon_check');

        Schema::table('record_modules', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
