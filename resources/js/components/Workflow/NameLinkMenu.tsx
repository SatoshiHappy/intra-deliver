import { Ellipsis, ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PersonLinkItem } from '@/lib/Workflow/personLinks';

/**
 * 名前の横の「…」(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1・2.2): 項目を新しいタブで開く(申請の画面・処理のダイアログの
 * 入力を失わない)。出す項目が無ければ「…」も出さない。メニューは modal にしない(ダイアログの中でも閉じたあとに操作を受けられるように)。
 * nopan・nodrag は図の中で押したときに図を動かさない印。label = 「…」の読み上げの名前(<名前>のメニュー)。
 */
export function NameLinkMenu({
    label,
    items,
}: {
    label: string;
    items: PersonLinkItem[];
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="nodrag nopan size-5 align-middle"
                    aria-label={label}
                >
                    <Ellipsis aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                {items.map((item) => (
                    <DropdownMenuItem key={item.label} asChild>
                        <a
                            href={item.href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {item.label}
                            <ExternalLink
                                aria-hidden="true"
                                className="ml-auto"
                            />
                        </a>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
