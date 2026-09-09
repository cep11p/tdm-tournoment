<?php

namespace App\Http\Requests\Group;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRandomGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'groups_count' => ['required', 'integer', 'min:1'],
            'seeded_entry_ids' => ['sometimes', 'nullable', 'array'],
            'seeded_entry_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function seededEntryIds(): array
    {
        $ids = $this->validated('seeded_entry_ids') ?? [];

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
