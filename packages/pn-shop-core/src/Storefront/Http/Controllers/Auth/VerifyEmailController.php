<?php

namespace PnShop\Storefront\Http\Controllers\Auth;

use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PnShop\Customer\Models\User;
use PnShop\Storefront\Http\Controllers\Controller;

class VerifyEmailController extends Controller
{
    /**
     * Mark a customer's email address as verified from the signed link in the email.
     *
     * The link works without being signed in (customers of a headless storefront using the
     * Store API have no session here): the signature and the hash of the address prove it
     * came from the email. Signed in as somebody else, the link is refused.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $current = $request->user();

        if ($current !== null && (string) $current->getKey() !== $id) {
            abort(403);
        }

        $user = $current ?? User::modelClass()::query()->find($id);

        if (! $user instanceof User || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if ($user->email_verified_at === null && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $current !== null
            ? redirect()->intended(route('dashboard', absolute: false).'?verified=1')
            : redirect()->route('login')->with('status', __('Your email address is verified. You can sign in now.'));
    }
}
