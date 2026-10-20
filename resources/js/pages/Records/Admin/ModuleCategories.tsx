import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    ModuleCategoryDeleteDialog,
    ModuleCategoryModulesDialog,
    ModuleCategoryNameDialog,
} from '@/components/Records/ModuleCategoryDialogs';
import { ModuleCategoryTree } from '@/components/Records/ModuleCategoryTree';
import { index } from '@/routes/records/admin/categories';
import { index as modules } from '@/routes/records/admin/modules';
import type {
    DefinitionTextMaxLengths,
    ModuleCategoryRow,
    UncategorizedModule,
} from '@/types';

type Props = {
    /** すべての分類(並びの順。modules は分類の中の並び) */
    categories: ModuleCategoryRow[];
    /** 分類なし(明細でない業務モジュール。表示中を「業務」の直下の並びで、続けて非表示) */
    uncategorized: UncategorizedModule[];
    /** 名前の欄の文字数の上限(name を使う) */
    textMaxLengths: DefinitionTextMaxLengths;
};

/** 画面名(見出し・パンくず・題) */
const HEADING = 'モジュール分類';

/**
 * モジュール分類の画面(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4、管理の画面.md 3.6)。
 * 書き込みの後は categories と uncategorized だけを読み直す(textMaxLengths は変わらない)。
 */
export default function ModuleCategories({
    categories,
    uncategorized,
    textMaxLengths,
}: Props) {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected =
        categories.find((category) => category.id === selectedId) ?? null;

    return (
        <>
            <Head title={HEADING} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{HEADING}</h1>
                <section
                    aria-label="モジュール分類の木"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <div
                        role="toolbar"
                        aria-label="モジュール分類の操作"
                        className="flex items-center gap-1"
                    >
                        <ModuleCategoryNameDialog
                            mode="create"
                            nameMaxLength={textMaxLengths.name}
                        />
                        <ModuleCategoryNameDialog
                            mode="edit"
                            category={selected}
                            nameMaxLength={textMaxLengths.name}
                        />
                        <ModuleCategoryDeleteDialog
                            category={selected}
                            categories={categories}
                        />
                        <ModuleCategoryModulesDialog
                            category={selected}
                            categories={categories}
                            uncategorized={uncategorized}
                        />
                    </div>
                    <ModuleCategoryTree
                        categories={categories}
                        uncategorized={uncategorized}
                        selectedId={selected?.id ?? null}
                        onSelect={(id) =>
                            setSelectedId((current) =>
                                current === id ? null : id,
                            )
                        }
                    />
                </section>
            </div>
        </>
    );
}

ModuleCategories.layout = () => ({
    breadcrumbs: [
        { title: '業務モジュール', href: modules() },
        { title: HEADING, href: index() },
    ],
});
