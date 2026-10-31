import {
    BookOpen,
    Briefcase,
    Building2,
    Calendar,
    ChartBar,
    CircleAlert,
    ClipboardList,
    Coins,
    Database,
    Dot,
    FileText,
    Folder,
    FolderKanban,
    FolderTree,
    GraduationCap,
    HeartPulse,
    House,
    Inbox,
    ListChecks,
    ListTodo,
    Mail,
    MapIcon,
    MapPin,
    Network,
    Package,
    Phone,
    Receipt,
    Send,
    ShieldCheck,
    ShoppingCart,
    Table,
    Tag,
    Ticket,
    Truck,
    UserCog,
    Users,
    Workflow,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * メニューのアイコンの許可表(アイコン名 → lucide-react の部品。docs の 設計/画面の意匠/サイドバーとアイコン.md 5.2)。
 * 名前は menus.icon_32 の値(lucide の公式の名前)。正本は PHP の列挙 App\Enums\Menu\MenuIcon で、このキーの集合と列挙の全ケースは一致させる
 * (tests/Architecture/MenuIconTableTest が両方向で確かめる)。新しい名前は、初期データに入れるか業務モジュールの作成と編集の選択肢に足す変更と同じ変更で、列挙とここに足す。
 * Map は JavaScript の組み込みの Map とぶつかるので、別名 MapIcon で import する。
 */
const ICONS: Record<string, LucideIcon> = {
    house: House,
    map: MapIcon,
    network: Network,
    'shield-check': ShieldCheck,
    database: Database,
    table: Table,
    'folder-kanban': FolderKanban,
    'list-todo': ListTodo,
    'clipboard-list': ClipboardList,
    'circle-alert': CircleAlert,
    users: Users,
    'building-2': Building2,
    briefcase: Briefcase,
    calendar: Calendar,
    'file-text': FileText,
    package: Package,
    truck: Truck,
    'shopping-cart': ShoppingCart,
    receipt: Receipt,
    wrench: Wrench,
    'book-open': BookOpen,
    mail: Mail,
    phone: Phone,
    'chart-bar': ChartBar,
    tag: Tag,
    'map-pin': MapPin,
    ticket: Ticket,
    'graduation-cap': GraduationCap,
    'heart-pulse': HeartPulse,
    coins: Coins,
    folder: Folder,
    'folder-tree': FolderTree,
    send: Send,
    inbox: Inbox,
    'list-checks': ListChecks,
    'user-cog': UserCog,
    workflow: Workflow,
};

/**
 * メニュー(フォルダ・アイテム)のアイコン。名前が null・空文字・許可表に無いときは既定の Dot を描く(描画は落とさない)。
 * 大きさと色は自身の class(4 節の表の (a))で、容器の既定に勝つ。読み上げない(lucide-react の既定の aria-hidden)。
 */
export function MenuIcon({
    name,
    className,
}: {
    name: string | null;
    className?: string;
}) {
    const Icon =
        name !== null && Object.hasOwn(ICONS, name) ? ICONS[name] : Dot;

    return <Icon className={cn('size-5 text-muted-foreground', className)} />;
}
