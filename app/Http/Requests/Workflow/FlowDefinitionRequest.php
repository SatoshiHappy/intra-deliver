<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\FlowDefinitionGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 定義の管理の書き込み(管理のルート workflow.admin.flows.referrers.update・versions.*・nodes.update)の入口の共通: authorize が
 * フロー・版・ノードの 404 を封筒の検証より先に確かめる(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 6 節。判定 = {@see FlowDefinitionGate})。
 * システム管理者だけが通るのはルートの認可が決める。版の無効・有効は封筒を持たない。
 */
class FlowDefinitionRequest extends FormRequest
{
    public function authorize(FlowDefinitionGate $gate): bool
    {
        $gate->authorize($this->flowCode(), $this->versionNumber(), $this->nodeCode());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * ルートのフローのコード(引数の形はルートが限る)。
     */
    public function flowCode(): string
    {
        return (string) $this->route('flow');
    }

    /**
     * ルートの版の番号(版を指さないルートは null)。
     */
    public function versionNumber(): ?int
    {
        $number = $this->route('version');

        return $number === null ? null : (int) $number;
    }

    /**
     * ルートのノードのコード(ノードを指さないルートは null)。
     */
    public function nodeCode(): ?string
    {
        $code = $this->route('node');

        return $code === null ? null : (string) $code;
    }
}
