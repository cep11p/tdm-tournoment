<?php

namespace App\Http\Requests\Audit;

use App\Enums\AuditAction;
use App\Enums\AuditSubjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'string', Rule::enum(AuditAction::class)],
            'log_name' => ['sometimes', 'string', Rule::in([
                'tournaments',
                'competitions',
                'players',
                'registrations',
                'groups',
                'bracket',
                'games',
            ])],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'tournament_id' => ['sometimes', 'integer', 'min:1'],
            'competition_id' => ['sometimes', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'string', Rule::in(AuditSubjectType::publicValues())],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $search = trim((string) $this->input('search', ''));

        $merge = [
            'page' => $this->input('page', 1),
            'per_page' => $this->input('per_page', 25),
        ];

        if ($search !== '') {
            $merge['search'] = $search;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        if ($key !== null) {
            return parent::validated($key, $default);
        }

        $validated = parent::validated();

        foreach ([
            'page',
            'per_page',
            'actor_id',
            'tournament_id',
            'competition_id',
            'group_id',
            'game_id',
            'subject_id',
        ] as $integerField) {
            if (array_key_exists($integerField, $validated)) {
                $validated[$integerField] = (int) $validated[$integerField];
            }
        }

        $validated['page'] = (int) ($validated['page'] ?? 1);
        $validated['per_page'] = (int) ($validated['per_page'] ?? 25);

        return $validated;
    }
}
