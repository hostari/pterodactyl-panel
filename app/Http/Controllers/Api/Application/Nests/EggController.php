<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Nests;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\Eggs\Sharing\EggImporterService;
use Pterodactyl\Transformers\Api\Application\EggTransformer;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService;
use Pterodactyl\Http\Requests\Api\Application\Nests\Eggs\GetEggRequest;
use Pterodactyl\Http\Requests\Api\Application\Nests\Eggs\GetEggsRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Nests\Eggs\ImportEggRequest;

class EggController extends ApplicationApiController
{
    public function __construct(
        private EggImporterService $importerService,
        private EggUpdateImporterService $updateImporterService,
    ) {
        parent::__construct();
    }

    /**
     * Return all eggs that exist for a given nest.
     */
    public function index(GetEggsRequest $request, Nest $nest): array
    {
        return $this->fractal->collection($nest->eggs)
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->toArray();
    }

    /**
     * Return a single egg that exists on the specified nest.
     */
    public function view(GetEggRequest $request, Nest $nest, Egg $egg): array
    {
        $this->ensureEggBelongsToNest($nest, $egg);

        return $this->fractal->item($egg)
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->toArray();
    }

    /**
     * Import an egg document into the specified nest.
     * A new import returns 201; an identical idempotent retry returns 200.
     *
     * @throws \Throwable
     */
    public function store(ImportEggRequest $request, Nest $nest): JsonResponse
    {
        try {
            $result = $this->importerService->handleIdempotentDocument(
                $request->document(),
                $nest->id,
                $request->idempotencyKey(),
                $request->documentHash()
            );
        } catch (InvalidFileUploadException $exception) {
            return $this->invalidDocumentResponse($exception);
        }

        if ($result['conflict']) {
            return new JsonResponse([
                'errors' => [[
                    'code' => 'EggSyncConflict',
                    'status' => (string) Response::HTTP_CONFLICT,
                    'detail' => sprintf(
                        'This idempotency key is already assigned to egg %d with a different payload. Update that egg by ID using PUT.',
                        $result['egg']->id
                    ),
                    'meta' => ['egg_id' => $result['egg']->id],
                ]],
            ], Response::HTTP_CONFLICT);
        }

        return $this->fractal->item($result['egg'])
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->respond($result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Replace an egg's imported configuration while retaining its identity.
     *
     * @throws \Throwable
     */
    public function update(ImportEggRequest $request, Nest $nest, Egg $egg): JsonResponse
    {
        $this->ensureEggBelongsToNest($nest, $egg);
        try {
            $egg = $this->updateImporterService->handleDocument($egg, $request->document(), $request->documentHash());
        } catch (InvalidFileUploadException $exception) {
            return $this->invalidDocumentResponse($exception);
        }

        return $this->fractal->item($egg)
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->respond(Response::HTTP_OK);
    }

    private function ensureEggBelongsToNest(Nest $nest, Egg $egg): void
    {
        abort_unless($egg->nest_id === $nest->id, Response::HTTP_NOT_FOUND);
    }

    private function invalidDocumentResponse(InvalidFileUploadException $exception): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'code' => 'InvalidEggDocument',
                'status' => (string) Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => $exception->getMessage(),
            ]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
