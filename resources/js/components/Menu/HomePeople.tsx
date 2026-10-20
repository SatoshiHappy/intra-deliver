import { HomeActionButton } from '@/components/Menu/HomeActionButton';
import { StatusBadge } from '@/components/StatusBadge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { nameOrCode } from '@/lib/nameOrCode';
import type { HomePeopleSection } from '@/types';

/**
 * 人のカードの並び(docs の 設計/メニュー/ホーム.md 6.3 の「役割カード」。3 列の格子)。頭文字の丸(ヘッダーの利用者メニューの丸と同じ色)・氏名・所属・
 * 役どころの札・小見出しと文・下端の副ボタン。見出しは描かず、区画の読み上げの名前にする。名前が null ならコードを出す(README の方針 9)。
 */
export function HomePeople({ section }: { section: HomePeopleSection }) {
    return (
        <section
            id={section.code}
            aria-label={section.heading}
            className="grid grid-cols-3 gap-4"
        >
            {section.items.map((item) => {
                const label = nameOrCode(item);

                return (
                    <div
                        key={item.code}
                        className="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
                    >
                        <div className="flex items-center gap-3">
                            <Avatar className="size-11" aria-hidden="true">
                                <AvatarFallback className="bg-primary text-base font-bold text-primary-foreground">
                                    {Array.from(label)[0]}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                                <div className="text-base leading-[1.3] font-bold">
                                    {label}
                                </div>
                                {item.affiliation !== null && (
                                    <div className="text-xs text-muted-foreground">
                                        {item.affiliation}
                                    </div>
                                )}
                            </div>
                        </div>
                        <div>
                            <StatusBadge label={item.role} tone="settled" />
                        </div>
                        <div className="text-sm text-pretty">
                            <div className="text-xs text-muted-foreground">
                                {section.noteLabel}
                            </div>
                            <div>{item.note}</div>
                        </div>
                        <div className="mt-auto">
                            <HomeActionButton
                                action={item.action}
                                ownerName={label}
                                variant="outline"
                            />
                        </div>
                    </div>
                );
            })}
        </section>
    );
}
