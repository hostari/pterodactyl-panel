<?php

namespace Pterodactyl\Services\Eggs;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Pterodactyl\Models\EggVariable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;

class EggParserService
{
    public const MAX_FILE_SIZE = 1024 * 1000;

    public function __construct(private ValidationFactory $validation)
    {
    }

    /**
     * Takes an uploaded file and parses out the egg configuration from within.
     *
     * @throws \JsonException
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException
     */
    public function handle(UploadedFile $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK || !$file->isFile()) {
            throw new InvalidFileUploadException('The selected file is not valid and cannot be imported.');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidFileUploadException('The JSON file provided may not be greater than 1000 kilobytes.');
        }

        try {
            $parsed = json_decode($file->openFile()->fread($file->getSize()), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidFileUploadException('The JSON file provided is not valid JSON.', $exception);
        }

        if (!is_array($parsed)) {
            throw new InvalidFileUploadException('The JSON file provided must contain an egg document.');
        }

        return $this->parse($parsed);
    }

    /**
     * Validates and normalizes a decoded egg document.
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException
     */
    public function parse(array $parsed): array
    {
        if (!in_array(Arr::get($parsed, 'meta.version') ?? '', ['PTDL_v1', 'PTDL_v2'])) {
            throw new InvalidFileUploadException('The egg document is not in a format that can be recognized.');
        }

        $parsed = $this->convertToV2($parsed);
        $validator = $this->validation->make($parsed, [
            'meta' => ['required', 'array'],
            'meta.version' => ['required', 'string', Rule::in(['PTDL_v1', 'PTDL_v2'])],
            'meta.update_url' => ['sometimes', 'nullable', 'string'],
            'name' => ['required', 'string', 'max:191'],
            'author' => ['required', 'string', 'email', 'max:191'],
            'description' => ['present', 'nullable', 'string'],
            'features' => ['present', 'nullable', 'array'],
            'features.*' => ['string'],
            'docker_images' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_array($value) && array_values($value) === $value) {
                        $fail('The docker images must be an object mapping labels to image names.');
                    }
                },
            ],
            'docker_images.*' => ['required', 'string'],
            'file_denylist' => ['present', 'nullable', 'array'],
            'file_denylist.*' => ['string'],
            'startup' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('The startup command must not be blank.');
                    }
                },
            ],
            'config' => ['required', 'array'],
            'config.files' => ['present', 'nullable', 'json'],
            'config.startup' => ['present', 'nullable', 'json'],
            'config.logs' => ['present', 'nullable', 'json'],
            'config.stop' => ['present', 'nullable', 'string', 'max:191'],
            'scripts' => ['required', 'array'],
            'scripts.installation' => ['required', 'array'],
            'scripts.installation.script' => ['present', 'nullable', 'string'],
            'scripts.installation.container' => ['present', 'nullable', 'string', 'max:191'],
            'scripts.installation.entrypoint' => ['present', 'nullable', 'string', 'max:191'],
            'variables' => ['required', 'array'],
            'variables.*' => ['array'],
            'variables.*.name' => ['required', 'string', 'between:1,191'],
            'variables.*.description' => ['present', 'string'],
            'variables.*.env_variable' => [
                'required',
                'string',
                'distinct',
                'regex:/^[\w]{1,191}$/',
                Rule::notIn(explode(',', EggVariable::RESERVED_ENV_NAMES)),
            ],
            'variables.*.default_value' => ['present', 'string'],
            'variables.*.user_viewable' => ['required', 'boolean'],
            'variables.*.user_editable' => ['required', 'boolean'],
            'variables.*.rules' => ['required', 'string', 'max:191'],
            'variables.*.field_type' => ['sometimes', 'string', Rule::in(['text'])],
        ]);

        if ($validator->fails()) {
            throw new InvalidFileUploadException(sprintf(
                'The egg document is invalid: %s',
                $validator->errors()->first()
            ));
        }

        return $parsed;
    }

    /**
     * Fills the provided model with the parsed JSON data.
     */
    public function fillFromParsed(Egg $model, array $parsed, bool $importUpdateUrl = true): Egg
    {
        $attributes = [
            'name' => Arr::get($parsed, 'name'),
            'description' => Arr::get($parsed, 'description'),
            'features' => Arr::get($parsed, 'features'),
            'docker_images' => Arr::get($parsed, 'docker_images'),
            'file_denylist' => Collection::make(Arr::get($parsed, 'file_denylist'))
                ->filter(fn ($value) => !empty($value)),
            'config_files' => Arr::get($parsed, 'config.files'),
            'config_startup' => Arr::get($parsed, 'config.startup'),
            'config_logs' => Arr::get($parsed, 'config.logs'),
            'config_stop' => Arr::get($parsed, 'config.stop'),
            'startup' => Arr::get($parsed, 'startup'),
            'script_install' => Arr::get($parsed, 'scripts.installation.script'),
            'script_entry' => Arr::get($parsed, 'scripts.installation.entrypoint'),
            'script_container' => Arr::get($parsed, 'scripts.installation.container'),
        ];

        // API imports must not be able to inject a URL that the panel may later fetch.
        // Multipart imports are trusted admin actions and retain their existing behavior.
        if ($importUpdateUrl) {
            $attributes['update_url'] = Arr::get($parsed, 'meta.update_url');
        }

        return $model->forceFill($attributes);
    }

    /**
     * Converts a PTDL_V1 egg into the expected PTDL_V2 egg format. This just handles
     * the "docker_images" field potentially not being present, and not being in the
     * expected "key => value" format.
     */
    protected function convertToV2(array $parsed): array
    {
        if (Arr::get($parsed, 'meta.version') === Egg::EXPORT_VERSION) {
            return $parsed;
        }

        // Maintain backwards compatability for eggs that are still using the old single image
        // string format. New eggs can provide an array of Docker images that can be used.
        if (!isset($parsed['images'])) {
            $images = [Arr::get($parsed, 'image') ?? 'nil'];
        } else {
            $images = $parsed['images'];
        }

        unset($parsed['images'], $parsed['image']);

        if (is_array($images)) {
            $parsed['docker_images'] = [];
            foreach ($images as $image) {
                // V1 represented images as a list. Convert every valid value into
                // the associative label => image shape required by PTDL v2.
                if (!is_string($image)) {
                    $parsed['docker_images'] = $images;

                    break;
                }

                $parsed['docker_images'][$image] = $image;
            }
        } else {
            // Leave malformed input in place so that schema validation can produce a
            // controlled client error rather than PHP raising an error during conversion.
            $parsed['docker_images'] = $images;
        }

        if (isset($parsed['variables']) && is_array($parsed['variables'])) {
            $parsed['variables'] = array_map(function ($value) {
                return is_array($value) ? array_merge($value, ['field_type' => 'text']) : $value;
            }, $parsed['variables']);
        }

        return $parsed;
    }
}
