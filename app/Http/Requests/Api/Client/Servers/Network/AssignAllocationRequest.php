<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Network;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class AssignAllocationRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_ALLOCATION_CREATE;
    }

    public function rules(): array
    {
        return [
            'allocation_id' => 'required|numeric',
        ];
    }
}
