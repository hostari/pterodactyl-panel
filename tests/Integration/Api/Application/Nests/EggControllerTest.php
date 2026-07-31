<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Nests;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Illuminate\Http\Response;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Transformers\Api\Application\EggTransformer;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class EggControllerTest extends ApplicationApiIntegrationTestCase
{
    public function testIdempotentEggCreateReplayAndConflict()
    {
        $egg = Egg::query()->firstOrFail();
        $url = "/api/application/nests/{$egg->nest_id}/eggs";
        $document = $this->validEggDocument();

        $created = $this->withHeader('Idempotency-Key', 'egg-sync-test-1')
            ->postJson($url, $document)
            ->assertCreated();
        $eggId = $created->json('attributes.id');

        $this->withHeader('Idempotency-Key', 'egg-sync-test-1')
            ->postJson($url, $document)
            ->assertOk()
            ->assertJsonPath('attributes.id', $eggId);

        $conflicting = $document;
        $conflicting['name'] = 'Different payload';
        $this->withHeader('Idempotency-Key', 'egg-sync-test-1')
            ->postJson($url, $conflicting)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('errors.0.meta.egg_id', $eggId);

        $this->assertSame(1, Egg::query()->where('nest_id', $egg->nest_id)->where('sync_key', 'egg-sync-test-1')->count());
    }

    public function testEggCreateValidationAndUpdateUrlSuppression()
    {
        $egg = Egg::query()->firstOrFail();
        $url = "/api/application/nests/{$egg->nest_id}/eggs";
        $document = $this->validEggDocument();
        $document['docker_images'] = ['bad' => 'invalid image $$$'];

        $this->withHeader('Idempotency-Key', 'egg-sync-test-2')
            ->postJson($url, $document)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'InvalidEggDocument');

        $document = $this->validEggDocument();
        $document['meta']['update_url'] = 'https://attacker.invalid/egg.json';
        $created = $this->withHeader('Idempotency-Key', 'egg-sync-test-3')
            ->postJson($url, $document)
            ->assertCreated();

        $createdEgg = Egg::query()->findOrFail($created->json('attributes.id'));
        $this->assertNull($createdEgg->update_url);

        $createdEgg->forceFill(['update_url' => 'https://trusted.invalid/egg.json'])->save();
        $document['name'] = 'Updated via API';
        $document['variables'][0]['egg_id'] = $egg->id;
        $this->putJson("$url/{$createdEgg->id}", $document)->assertOk();

        $createdEgg->refresh();
        $this->assertSame('Updated via API', $createdEgg->name);
        $this->assertSame('https://trusted.invalid/egg.json', $createdEgg->update_url);
        $this->assertSame($createdEgg->id, $createdEgg->variables()->firstOrFail()->egg_id);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDockerImagesDataProvider')]
    public function testEggCreateRejectsMalformedDockerImages(string $image)
    {
        $egg = Egg::query()->firstOrFail();
        $document = $this->validEggDocument();
        $document['docker_images'] = ['Invalid image' => $image];

        $this->withHeader('Idempotency-Key', 'egg-image-' . md5($image))
            ->postJson("/api/application/nests/{$egg->nest_id}/eggs", $document)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'InvalidEggDocument');
    }

    public static function malformedDockerImagesDataProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'pipe only' => ['|'],
            'tilde only' => ['~'],
            'space in reference' => ['invalid image'],
            'trailing tag separator' => ['example/image:'],
            'trailing path separator' => ['example/image/'],
        ];
    }

    public function testEggPutRejectsMalformedDocumentAndWrongNest()
    {
        $egg = Egg::query()->firstOrFail();
        $document = $this->validEggDocument();
        $document['docker_images'] = [];

        $this->putJson("/api/application/nests/{$egg->nest_id}/eggs/{$egg->id}", $document)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'InvalidEggDocument');

        $otherNest = \Pterodactyl\Models\Nest::factory()->create();
        $this->putJson("/api/application/nests/{$otherNest->id}/eggs/{$egg->id}", $this->validEggDocument())
            ->assertNotFound();
    }

    /**
     * Test that all the eggs belonging to a given nest can be returned.
     */
    public function testListAllEggsInNest()
    {
        $eggs = Egg::query()->where('nest_id', 1)->get();

        $response = $this->getJson('/api/application/nests/' . $eggs->first()->nest_id . '/eggs');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonCount(count($eggs), 'data');
        $response->assertJsonStructure([
            'object',
            'data' => [
                [
                    'object',
                    'attributes' => [
                        'id', 'uuid', 'nest', 'author', 'description', 'docker_image', 'startup', 'created_at', 'updated_at',
                        'script' => ['privileged', 'install', 'entry', 'container', 'extends'],
                        'config' => [
                            'files' => [],
                            'startup' => ['done'],
                            'stop',
                            'logs' => [],
                            'extends',
                        ],
                    ],
                ],
            ],
        ]);

        foreach (array_get($response->json(), 'data') as $datum) {
            $egg = $eggs->where('id', '=', $datum['attributes']['id'])->first();

            $expected = json_encode(Arr::sortRecursive($datum['attributes']));
            $actual = json_encode(Arr::sortRecursive($this->getTransformer(EggTransformer::class)->transform($egg)));

            $this->assertSame(
                $expected,
                $actual,
                'Unable to find JSON fragment: ' . PHP_EOL . PHP_EOL . "[$expected]" . PHP_EOL . PHP_EOL . 'within' . PHP_EOL . PHP_EOL . "[$actual]."
            );
        }
    }

    /**
     * Test that a single egg can be returned.
     */
    public function testReturnSingleEgg()
    {
        $egg = Egg::query()->findOrFail(1);

        $response = $this->getJson('/api/application/nests/' . $egg->nest_id . '/eggs/' . $egg->id);
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'object',
            'attributes' => [
                'id', 'uuid', 'nest', 'author', 'description', 'docker_image', 'startup', 'script' => [], 'config' => [], 'created_at', 'updated_at',
            ],
        ]);

        $response->assertJson([
            'object' => 'egg',
            'attributes' => $this->getTransformer(EggTransformer::class)->transform($egg),
        ], true);
    }

    /**
     * Test that a single egg and all the defined relationships can be returned.
     */
    public function testReturnSingleEggWithRelationships()
    {
        $egg = Egg::query()->findOrFail(1);

        $response = $this->getJson('/api/application/nests/' . $egg->nest_id . '/eggs/' . $egg->id . '?include=servers,variables,nest');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'object',
            'attributes' => [
                'relationships' => [
                    'nest' => ['object', 'attributes'],
                    'servers' => ['object', 'data' => []],
                    'variables' => ['object', 'data' => []],
                ],
            ],
        ]);
    }

    /**
     * Test that a missing egg returns a 404 error.
     */
    public function testGetMissingEgg()
    {
        $egg = Egg::query()->findOrFail(1);

        $response = $this->getJson('/api/application/nests/' . $egg->nest_id . '/eggs/nil');
        $this->assertNotFoundJson($response);
    }

    /**
     * Test that an authentication error occurs if a key does not have permission
     * to access a resource.
     */
    public function testErrorReturnedIfNoPermission()
    {
        $egg = Egg::query()->findOrFail(1);
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => 0]);

        $response = $this->getJson('/api/application/nests/' . $egg->nest_id . '/eggs');
        $this->assertAccessDeniedJson($response);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eggWriteEndpointsDataProvider')]
    public function testApiKeyWithoutWritePermissionCannotMutateEggs(string $method, string $url)
    {
        $egg = Egg::query()->firstOrFail();
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => AdminAcl::READ]);

        $url = str_replace(['{nest}', '{egg}'], [$egg->nest_id, $egg->id], $url);
        $response = $this->$method($url, $this->validEggDocument());

        $this->assertAccessDeniedJson($response);
    }

    public static function eggWriteEndpointsDataProvider(): array
    {
        return [
            'POST' => ['postJson', '/api/application/nests/{nest}/eggs'],
            'PUT' => ['putJson', '/api/application/nests/{nest}/eggs/{egg}'],
        ];
    }

    private function validEggDocument(): array
    {
        return [
            'meta' => ['version' => 'PTDL_v2', 'update_url' => null],
            'name' => 'Hostari Sync Egg',
            'author' => 'support@hostari.com',
            'description' => 'An API synchronized egg.',
            'features' => [],
            'docker_images' => ['Java 17' => 'ghcr.io/example/java:17'],
            'file_denylist' => [],
            'startup' => 'java -jar server.jar',
            'config' => [
                'files' => '{}',
                'startup' => '{"done":"Done"}',
                'logs' => '{}',
                'stop' => 'stop',
            ],
            'scripts' => [
                'installation' => [
                    'script' => '#!/bin/bash',
                    'container' => 'alpine:3.20',
                    'entrypoint' => 'ash',
                ],
            ],
            'variables' => [[
                'name' => 'Version',
                'description' => 'Server version',
                'env_variable' => 'SERVER_VERSION',
                'default_value' => 'latest',
                'user_viewable' => true,
                'user_editable' => true,
                'rules' => 'required|string',
                'field_type' => 'text',
            ]],
        ];
    }
}
