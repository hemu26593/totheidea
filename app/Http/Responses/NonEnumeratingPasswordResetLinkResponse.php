<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Makes a failed reset-link request indistinguishable from a successful one.
 *
 * Laravel's default response reports "We can't find a user with that email
 * address", which turns the public, unauthenticated reset endpoint into an
 * account-enumeration oracle: anyone can test whether an address has an account
 * here. For an internal platform that also discloses who works at the company.
 *
 * The genuine outcome is still recorded server-side — only the response is
 * uniform.
 */
class NonEnumeratingPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        $status = trans(Password::RESET_LINK_SENT);

        return $request instanceof Request && $request->wantsJson()
            ? new JsonResponse(['message' => $status], 200)
            : back()->with('status', $status);
    }
}
