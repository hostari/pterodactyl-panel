<?php

namespace Pterodactyl\Tests\Integration\Notifications;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Events\Server\Installed;
use Pterodactyl\Notifications\ServerInstalled as ServerInstalledNotification;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ServerInstalledTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testNotificationIsSuppressedForNonHostariUser()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'user@example.com']);
        $server = $this->createServerModel(['user_id' => $user->id]);

        (new ServerInstalledNotification())->handle(new Installed($server));

        Notification::assertNothingSent();
    }

    public function testNotificationIsSentForHostariUserCaseInsensitively()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'Person@HOSTARI.COM']);
        $server = $this->createServerModel(['user_id' => $user->id]);

        (new ServerInstalledNotification())->handle(new Installed($server));

        Notification::assertSentTo($user, ServerInstalledNotification::class);
    }
}
