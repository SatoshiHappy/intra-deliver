<?php

namespace App\Enums\Menu;

/**
 * メニューのアイコンの許可表の正本(値は menus.icon_32 の値 = lucide の公式の名前。docs の 設計/画面の意匠/サイドバーとアイコン.md 5.2、
 * 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1)。画面の対応表 resources/js/components/MenuIcon.tsx は同じ集合のキーを持つ
 * (整合は tests/Architecture/MenuIconTableTest が両方向で確かめる)。
 * メニューを足す業務処理 AddMenu は値を検査しない(本家のアイコン ID は自由)。業務モジュールを作る・変える業務処理だけが {@see self::recordModuleChoices()} で照合する。
 */
enum MenuIcon: string
{
    case House = 'house';
    case Map = 'map';
    case Network = 'network';
    case ShieldCheck = 'shield-check';
    case Database = 'database';
    case Table = 'table';
    case FolderKanban = 'folder-kanban';
    case ListTodo = 'list-todo';
    case ClipboardList = 'clipboard-list';
    case CircleAlert = 'circle-alert';
    case Users = 'users';
    case Building2 = 'building-2';
    case Briefcase = 'briefcase';
    case Calendar = 'calendar';
    case FileText = 'file-text';
    case Package = 'package';
    case Truck = 'truck';
    case ShoppingCart = 'shopping-cart';
    case Receipt = 'receipt';
    case Wrench = 'wrench';
    case BookOpen = 'book-open';
    case Mail = 'mail';
    case Phone = 'phone';
    case ChartBar = 'chart-bar';
    case Tag = 'tag';
    case MapPin = 'map-pin';
    case Ticket = 'ticket';
    case GraduationCap = 'graduation-cap';
    case HeartPulse = 'heart-pulse';
    case Coins = 'coins';

    /** モジュール分類のフォルダ(docs の 設計/業務データの入れ物/モジュール分類/README.md 1.3。業務モジュールには選べない) */
    case Folder = 'folder';

    /** 「管理」のアイテム「モジュール分類」(docs の 設計/業務データの入れ物/モジュール分類/README.md 5 節。業務モジュールには選べない) */
    case FolderTree = 'folder-tree';

    /** ワークフローのアイテム「申請する」(docs の 設計/ワークフロー/認可と一覧.md 6 節。業務モジュールには選べない) */
    case Send = 'send';

    /** ワークフローのアイテム「申請一覧」(同上) */
    case Inbox = 'inbox';

    /** 「管理」のアイテム「代理の管理」(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 5 節。業務モジュールには選べない) */
    case UserCog = 'user-cog';

    /**
     * 業務モジュールの作成と編集で選べるアイコン(許可表の部分集合)の「値 → 画面に出す日本語の表示名」。先頭が既定。表示名は形で付ける。
     *
     * @return non-empty-array<string, string>
     */
    public static function recordModuleChoices(): array
    {
        return [
            self::Table->value => '表',
            self::FolderKanban->value => 'かんばんのフォルダ',
            self::ListTodo->value => 'チェックリスト',
            self::ClipboardList->value => 'クリップボード',
            self::CircleAlert->value => '注意',
            self::Users->value => '人々',
            self::Building2->value => '建物',
            self::Briefcase->value => 'かばん',
            self::Calendar->value => 'カレンダー',
            self::FileText->value => '文書',
            self::Package->value => '荷物',
            self::Truck->value => 'トラック',
            self::ShoppingCart->value => 'カート',
            self::Receipt->value => '領収書',
            self::Wrench->value => '工具',
            self::BookOpen->value => '本',
            self::Mail->value => 'メール',
            self::Phone->value => '電話',
            self::ChartBar->value => '棒グラフ',
            self::Tag->value => 'タグ',
            self::MapPin->value => '地図のピン',
            self::Ticket->value => 'チケット',
            self::GraduationCap->value => '学位帽',
            self::HeartPulse->value => '心拍',
            self::Coins->value => 'コイン',
        ];
    }

    /**
     * 業務モジュールの作成と編集で選べるアイコン(値が文字列で、{@see self::recordModuleChoices()} にあるとき。無ければ null)。
     */
    public static function tryRecordModuleChoice(mixed $value): ?self
    {
        return is_string($value) && array_key_exists($value, self::recordModuleChoices()) ? self::tryFrom($value) : null;
    }
}
