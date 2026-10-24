<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Queries\Workflow\ApplicableFlows;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * フローの一覧 Workflow/FlowIndex(docs の 設計/ワークフロー/画面/README.md 2 節): 今の利用者が申請できるフロー(並びの順)。ゲスト・申請できない人は空。
 */
class FlowController extends Controller
{
    public function __construct(private ApplicableFlows $flows) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Workflow/FlowIndex', [
            'flows' => $this->flows->flows(AccessContext::of($request)),
        ]);
    }
}
