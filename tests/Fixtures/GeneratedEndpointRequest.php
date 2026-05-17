<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class GeneratedEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project' => 'required|integer',
            'include' => 'nullable|string|in:owner,tasks',
            'name' => 'required|string|max:120',
            'active' => 'boolean',
        ];
    }
}
