import { Ellipsis, ExternalLink } from 'lucide-react';
import { Fragment } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePersonLinks } from '@/hooks/Workflow/use-person-links';
import type { NameSegment } from '@/lib/Workflow/nameSegments';
import { personLinkItemsOf } from '@/lib/Workflow/personLinks';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowLinkedPerson } from '@/types';

/**
 * 名前の札の「…」(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1・2.2、申請の周辺/画面.md 7 節): 「組織図で見る」「認可設定で見る」を
 * 新しいタブで開く(申請の画面・処理のダイアログの入力を失わない)。出す項目が無ければ「…」も出さない。
 * メニューは modal にしない(ダイアログの中でも閉じたあとに操作を受けられるように)。nopan・nodrag は図の中で押したときに図を動かさない印。
 */
export function PersonMenu({ person }: { person: WorkflowLinkedPerson }) {
    const items = personLinkItemsOf(person, usePersonLinks());

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
                    aria-label={`${personName(person)}のメニュー`}
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

/**
 * 名前の札を含む文(文字列はそのまま、人は名前と「…」)。文の並びと区切りは {@link NameSegment} のまま。
 */
export function NameSegmentsText({ segments }: { segments: NameSegment[] }) {
    return segments.map((segment, index) =>
        typeof segment === 'string' ? (
            <Fragment key={index}>{segment}</Fragment>
        ) : (
            <span key={index} className="whitespace-nowrap">
                {segment.text}
                <PersonMenu person={segment.person} />
            </span>
        ),
    );
}
