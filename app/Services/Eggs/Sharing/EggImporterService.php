<?php

namespace Pterodactyl\Services\Eggs\Sharing;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Models\EggVariable;
use Illuminate\Database\QueryException;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Eggs\EggParserService;

class EggImporterService
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

    public function __construct(protected ConnectionInterface $connection, protected EggParserService $parser)
    {
    }

    /**
     * Take an uploaded JSON file and parse it into a new egg.
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException|\Throwable
     */
    public function handle(UploadedFile $file, int $nest): Egg
    {
        return $this->import($this->parser->handle($file), $nest, true);
    }

    /**
     * Import a new egg from an already-decoded JSON document.
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException|\Throwable
     */
    public function handleDocument(array $document, int $nest): Egg
    {
        return $this->import($this->parser->parse($document), $nest, false);
    }

    /**
     * Import an API egg exactly once for a nest and idempotency key.
     *
     * @return array{egg: Egg, created: bool, conflict: bool}
     *
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException|\Throwable
     */
    public function handleIdempotentDocument(
        array $document,
        int $nest,
        string $syncKey,
        string $syncHash,
    ): array {
        $parsed = $this->parser->parse($document);
        $nest = Nest::query()->findOrFail($nest);

        try {
            return $this->connection->transaction(function () use ($parsed, $nest, $syncKey, $syncHash) {
                $existing = Egg::query()
                    ->where('nest_id', $nest->id)
                    ->where('sync_key', $syncKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->existingResult($existing, $syncHash);
                }

                $egg = $this->createEgg($parsed, $nest, false, $syncKey, $syncHash);

                return ['egg' => $egg, 'created' => true, 'conflict' => false];
            }, 3);
        } catch (QueryException $exception) {
            // Concurrent requests can both pass the initial lookup. The unique index is
            // the final arbiter; inspect its winner after this transaction rolls back.
            if (!$this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Egg::query()
                ->where('nest_id', $nest->id)
                ->where('sync_key', $syncKey)
                ->first();

            if (!$existing) {
                throw $exception;
            }

            return $this->existingResult($existing, $syncHash);
        }
    }

    private function import(array $parsed, int $nest, bool $importUpdateUrl): Egg
    {
        /** @var Nest $nest */
        $nest = Nest::query()->with('eggs', 'eggs.variables')->findOrFail($nest);

        return $this->connection->transaction(
            fn () => $this->createEgg($parsed, $nest, $importUpdateUrl)
        );
    }

    private function createEgg(
        array $parsed,
        Nest $nest,
        bool $importUpdateUrl,
        ?string $syncKey = null,
        ?string $syncHash = null,
    ): Egg {
        $egg = (new Egg())->forceFill([
            'uuid' => Uuid::uuid4()->toString(),
            'nest_id' => $nest->id,
            'author' => Arr::get($parsed, 'author'),
            'copy_script_from' => null,
            'sync_key' => $syncKey,
            'sync_hash' => $syncHash,
        ]);

        $egg = $this->parser->fillFromParsed($egg, $parsed, $importUpdateUrl);
        $egg->save();

        foreach ($parsed['variables'] ?? [] as $variable) {
            EggVariable::query()->forceCreate(array_merge(
                Arr::only($variable, self::VARIABLE_ATTRIBUTES),
                ['egg_id' => $egg->id]
            ));
        }

        return $egg;
    }

    /** @return array{egg: Egg, created: bool, conflict: bool} */
    private function existingResult(Egg $egg, string $syncHash): array
    {
        return [
            'egg' => $egg,
            'created' => false,
            'conflict' => !hash_equals((string) $egg->sync_hash, $syncHash),
        ];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $state === '23505'
            || $driverCode === 1062
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
