<?php

namespace App\Http\OctaneFixture;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

final class InjectsRequest
{
    public function __construct(
        private readonly Request $request,
        private readonly FormRequest $form,
    ) {}

    public function path(): string
    {
        return $this->request->path().$this->form->path();
    }
}
