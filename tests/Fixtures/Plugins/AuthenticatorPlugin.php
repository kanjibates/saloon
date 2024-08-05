<?php

declare(strict_types=1);

namespace Saloon\Tests\Fixtures\Plugins;

use Saloon\Http\PendingRequest;
use Saloon\Http\Auth\TokenAuthenticator;

trait AuthenticatorPlugin
{
    public function bootAuthenticatorPlugin(PendingRequest $pendingRequest): void
    {
        $pendingRequest->authenticate(new TokenAuthenticator('plugin-auth'));
    }
}
