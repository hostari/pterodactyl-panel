<?php

namespace Pterodactyl\Http\Requests\Api\Application\Nests\Eggs;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Services\Eggs\EggParserService;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class ImportEggRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_EGGS;

    protected int $permission = AdminAcl::WRITE;

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('post')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'meta' => ['required', 'array'],
            'meta.version' => ['required', 'string', Rule::in(['PTDL_v1', 'PTDL_v2'])],
        ];

        if ($this->isMethod('post')) {
            $rules['idempotency_key'] = [
                'required',
                'string',
                'between:8,128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (strlen($this->getContent()) > EggParserService::MAX_FILE_SIZE) {
                $validator->errors()->add('document', 'The egg document may not be greater than 1000 kilobytes.');
            }
        });
    }

    /**
     * Return the complete JSON document rather than only the fields used for validation.
     */
    public function document(): array
    {
        return $this->json()->all();
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    public function documentHash(): string
    {
        return hash('sha256', $this->getContent());
    }
}
