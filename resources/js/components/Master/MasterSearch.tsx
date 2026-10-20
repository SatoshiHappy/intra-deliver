import { router } from '@inertiajs/react';
import { Command as CommandPrimitive } from 'cmdk';
import { Search } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { MasterSearchResults } from '@/components/Master/MasterSearchResults';
import { Command } from '@/components/ui/command';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useMasterSearch } from '@/hooks/use-master-search';

/** 欄のプレースホルダーと読み上げの名前(容器によらず同じ) */
const LABEL = 'ユーザー・組織を検索';

/**
 * ヘッダーの共通マスタの検索(docs の 設計/共通マスタ/検索と組織図.md 3 節、設計/画面の意匠/骨格とヘッダー.md 3 節の 4)。容器 = Popover 版。
 * Command の中に、欄(自前の包み + cmdk の CommandPrimitive.Input。高さ 40px・左に Search・枠は --input・フォーカスの輪は --ring)を
 * PopoverAnchor にした Popover を置き、PopoverContent(欄と同じ幅)に結果を入れる。開いてもフォーカスは欄に残し、欄を押しても閉じない。
 * Esc と欄の外を押すと閉じる(Radix の既定)。欄にフォーカス・欄を押す・↓で開き直す。Inertia のページ遷移が始まると語を空にして閉じる。結果の中身と取得は MasterSearchResults・useMasterSearch で、容器を Dialog に替えてもそのまま使う(3.4)。
 */
export function MasterSearch() {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const search = useMasterSearch(query);
    const anchorRef = useRef<HTMLDivElement>(null);

    const clearAndClose = () => {
        setQuery('');
        setOpen(false);
    };
    const clearAndCloseOnNavigation = useEffectEvent(clearAndClose);

    // 結果の選択でない Inertia の遷移(サイドバーのリンク・利用者の切替など)でも、語を空にして閉じる(語が空になるとフックが結果を捨てる)
    useEffect(() => router.on('start', () => clearAndCloseOnNavigation()), []);

    return (
        <Command
            shouldFilter={false}
            label={LABEL}
            className="h-auto overflow-visible bg-transparent"
        >
            <Popover open={open && query.trim() !== ''} onOpenChange={setOpen}>
                <PopoverAnchor asChild>
                    <div
                        ref={anchorRef}
                        className="flex h-10 items-center gap-2 rounded-md border border-input bg-background px-3 shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
                    >
                        <Search className="size-5 shrink-0 text-muted-foreground" />
                        <CommandPrimitive.Input
                            value={query}
                            onValueChange={(value) => {
                                setQuery(value);
                                setOpen(true);
                            }}
                            onFocus={() => setOpen(true)}
                            onClick={() => setOpen(true)}
                            onKeyDown={(event) => {
                                // Esc で閉じた後も、押す・↓で開き直す(既定の動作と伝播は止めない = Command の ↑↓ の移動を壊さない)
                                if (event.key === 'ArrowDown') {
                                    setOpen(true);
                                }
                            }}
                            placeholder={LABEL}
                            className="h-full w-full min-w-0 bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                        />
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    align="start"
                    className="w-(--radix-popover-trigger-width) p-0"
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onInteractOutside={(event) => {
                        if (
                            event.target instanceof Node &&
                            anchorRef.current?.contains(event.target)
                        ) {
                            event.preventDefault();
                        }
                    }}
                >
                    <MasterSearchResults
                        search={search}
                        onSelected={clearAndClose}
                    />
                </PopoverContent>
            </Popover>
        </Command>
    );
}
