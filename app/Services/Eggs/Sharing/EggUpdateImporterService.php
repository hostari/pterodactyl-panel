<?php

namespace Pterodactyl\Services\Eggs\Sharing;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Models\EggVariable;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Eggs\EggParserService;

class EggUpdateImporterService
{
    private const VARIABLE_ATTRIBUTES = [
        'name',
        'description',
        'env_variable',
        'default_value',
        'user_viewable',
        'user_editable',
        'rules',
    ];

    /**
     * EggUpdateImporterService constructor.
     */
    public function __construct(protected ConnectionInterface $connection, protected EggParserService $parser)
    {
    }

    /**
     * Update an existing Egg using an uploaded JSON file.
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException|\Throwable
     */
    public function handle(Egg $egg, UploadedFile $file): Egg
    {
        return $this->update($egg, $this->parser->handle($file), true);
    }

    /**
     * Update an existing egg from an already-decoded JSON document.
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException|\Throwable
     */
    public function handleDocument(Egg $egg, array $document, ?string $syncHash = null): Egg
    {
        return $this->update($egg, $this->parser->parse($document), false, $syncHash);
    }

    private function update(Egg $egg, array $parsed, bool $importUpdateUrl, ?string $syncHash = null): Egg
    {
        return $this->connection->transaction(function () use ($egg, $parsed, $importUpdateUrl, $syncHash) {
            $egg = Egg::query()->lockForUpdate()->findOrFail($egg->id);
            $egg = $this->parser->fillFromParsed($egg, $parsed, $importUpdateUrl);
            if (!is_null($egg->sync_key) && !is_null($syncHash)) {
                $egg->sync_hash = $syncHash;
            }
            $egg->save();

            // Update existing variables or create new ones.
            foreach ($parsed['variables'] ?? [] as $variable) {
                EggVariable::unguarded(function () use ($egg, $variable) {
                    $attributes = Arr::only($variable, self::VARIABLE_ATTRIBUTES);
                    $egg->variables()->updateOrCreate([
                        'env_variable' => $attributes['env_variable'],
                    ], Arr::except($attributes, 'env_variable'));
                });
            }

            $imported = array_map(fn ($value) => $value['env_variable'], $parsed['variables'] ?? []);

            $egg->variables()->whereNotIn('env_variable', $imported)->delete();

            return $egg->refresh();
        });
    }
}
