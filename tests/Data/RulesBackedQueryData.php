<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;

class RulesBackedQueryData extends Data
{
    public function __construct(
        public string $context,
        public ?string $note = null,
    ) {}

    public static function rules(): array
    {
        return [
            'context' => ['required', 'string'],
            'note' => ['nullable', 'string'],
        ];
    }
}
