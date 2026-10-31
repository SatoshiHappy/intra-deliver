import { ChevronDown } from 'lucide-react';
import { MenuIcon } from '@/components/MenuIcon';
import { nameOrCode } from '@/lib/nameOrCode';
import type { MenuFolderNode } from '@/types';

// フォルダの行の山形。開くと 180 度回す(引き金の data-state を group/folder で見る)
const chevronClass =
    'ml-auto transition-transform group-data-[state=open]/folder:rotate-180';

/**
 * 広げたときのフォルダの引き金の中身: アイコン・表示名・山形。容器の [&>svg:first-child] の規則がアイコンに当たるよう、要素で包まずに返す。
 */
export function FolderTriggerContent({ folder }: { folder: MenuFolderNode }) {
    return (
        <>
            <MenuIcon name={folder.icon} />
            <span className="truncate">{nameOrCode(folder)}</span>
            <ChevronDown className={chevronClass} />
        </>
    );
}
