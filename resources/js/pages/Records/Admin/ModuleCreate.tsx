import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, invalidProps, UnshownErrors } from '@/components/FormBlock';
import { FormActions, FormSurface } from '@/components/FormLayout';
import { MenuIcon } from '@/components/MenuIcon';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCodeCheck } from '@/hooks/Records/use-code-check';
import { clearServerErrors } from '@/lib/formErrors';
import { codeError } from '@/lib/Records/recordDefinitionCode';
import { create, index, store } from '@/routes/records/admin/modules';
import type { DefinitionTextMaxLengths, RecordModuleIconChoice } from '@/types';

/** 画面名(見出し・パンくず・題) */
const HEADING = '業務モジュールの作成';

/** icons = アイコンの選択肢(サーバーの列挙を props で受ける)、textMaxLengths = コード・名前・説明の文字数の上限 */
type Props = {
    icons: RecordModuleIconChoice[];
    textMaxLengths: DefinitionTextMaxLengths;
};

/**
 * 管理の業務モジュールの作成(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.2。誤りの出し方 = 3 節の冒頭)。
 */
export default function ModuleCreate({ icons, textMaxLengths }: Props) {
    const form = useForm({
        code: '',
        name: '',
        description: '',
        // 列挙の既定は先頭(MenuIcon::recordModuleChoices())
        icon: icons[0].value,
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(store());
    };
    // コードの書式はサーバーの規則の写しで、欄を離れたときから出す(管理の画面.md 3.2)
    const codeCheck = useCodeCheck({
        value: form.data.code,
        serverError: form.errors.code,
        clearServerError: () => clearServerErrors(form, ['code']),
        check: codeError,
    });

    return (
        <>
            <Head title={HEADING} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{HEADING}</h1>
                <form
                    noValidate
                    onSubmit={submit}
                    aria-label="業務モジュールの入力"
                    className="flex flex-col gap-6"
                >
                    <UnshownErrors
                        errors={
                            form.errors as Record<string, string | undefined>
                        }
                        shownKeys={['code', 'name', 'description', 'icon']}
                    />
                    <FormSurface inner="max-w-2xl">
                        <FormBlock
                            id="module-code"
                            label="コード"
                            isRequired
                            hint="英小文字で始まる英小文字・数字・_ だけ。作成後は変更できません"
                            error={codeCheck.shownError}
                        >
                            <CountedTextInput
                                id="module-code"
                                value={form.data.code}
                                max={textMaxLengths.code}
                                invalid={codeCheck.shownError !== undefined}
                                onChange={(event) => {
                                    form.setData('code', event.target.value);
                                    codeCheck.onChange();
                                }}
                                onBlur={codeCheck.onBlur}
                            />
                        </FormBlock>
                        <FormBlock
                            id="module-name"
                            label="名前"
                            isRequired
                            error={form.errors.name}
                        >
                            <CountedTextInput
                                id="module-name"
                                value={form.data.name}
                                max={textMaxLengths.name}
                                invalid={form.errors.name !== undefined}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormBlock>
                        <FormBlock
                            id="module-description"
                            label="説明"
                            error={form.errors.description}
                        >
                            <CountedTextInput
                                id="module-description"
                                isMultiline
                                value={form.data.description}
                                max={textMaxLengths.description}
                                invalid={form.errors.description !== undefined}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormBlock>
                        <FormBlock
                            id="module-icon"
                            label="アイコン"
                            hint="サイドバーとサイトマップに表示されます。編集で変更できます"
                            error={form.errors.icon}
                        >
                            <Select
                                value={form.data.icon}
                                onValueChange={(icon) =>
                                    form.setData('icon', icon)
                                }
                            >
                                <SelectTrigger
                                    id="module-icon"
                                    className="w-full"
                                    {...invalidProps(
                                        'module-icon',
                                        form.errors.icon,
                                    )}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {icons.map((icon) => (
                                        <SelectItem
                                            key={icon.value}
                                            value={icon.value}
                                        >
                                            <MenuIcon
                                                name={icon.value}
                                                className="size-4"
                                            />
                                            {icon.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormBlock>
                    </FormSurface>
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={index()}>キャンセル</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            作成
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

ModuleCreate.layout = () => ({
    breadcrumbs: [
        { title: '業務モジュール', href: index() },
        { title: HEADING, href: create() },
    ],
});
