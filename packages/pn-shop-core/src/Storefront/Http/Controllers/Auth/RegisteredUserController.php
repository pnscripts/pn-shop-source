<?php

namespace PnShop\Storefront\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Customer\CustomerAccounts;
use PnShop\Customer\Registration;
use PnShop\Security\BotTrap;
use PnShop\Storefront\Http\Controllers\Controller;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register', ['botTrap' => BotTrap::fields()]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', app(CustomerAccounts::class)->uniqueEmail()],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = app(Registration::class)->register((string) $request->input('name'), (string) $request->input('email'), (string) $request->input('password'));

        Auth::login($user);

        return to_route('dashboard');
    }
}
