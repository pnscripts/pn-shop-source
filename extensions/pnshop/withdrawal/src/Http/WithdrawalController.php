<?php

namespace PnShop\Plugins\Withdrawal\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Plugins\Withdrawal\WithdrawalStatements;
use PnShop\Security\BotTrap;

/**
 * The storefront withdrawal function: details (name, order, email), then a separate
 * confirmation step, then the received page. Works for guests and signed-in customers.
 */
class WithdrawalController extends Controller
{
    private const DRAFT = 'pnshop_withdrawal.draft';

    private const SENT = 'pnshop_withdrawal.sent';

    /** Web addresses have no place in a statement and would travel in the shop's emails. */
    private const NO_LINKS = '/(:\/\/|www\.)/i';

    public function create(Request $request): Response
    {
        $customer = $request->user();
        $draft = (array) $request->session()->get(self::DRAFT, []);

        return $this->page('details', [
            'values' => [
                'name' => $draft['name'] ?? ($customer !== null ? trim((string) $customer->name) : ''),
                'email' => $draft['email'] ?? ($customer !== null ? (string) $customer->email : ''),
                'order_number' => $draft['order_number'] ?? mb_substr((string) $request->query('order', ''), 0, 64),
                'items' => $draft['items'] ?? '',
            ],
            'bot' => BotTrap::fields(),
        ]);
    }

    public function review(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/', 'not_regex:'.self::NO_LINKS],
            'email' => ['required', 'email', 'max:255'],
            'order_number' => ['required', 'string', 'max:64', 'not_regex:/[\r\n]/', 'not_regex:'.self::NO_LINKS],
            'items' => ['nullable', 'string', 'max:2000', 'not_regex:'.self::NO_LINKS],
        ], [], [
            'name' => __('name'),
            'email' => __('email'),
            'order_number' => __('order number'),
            'items' => __('products'),
        ]);

        $request->session()->put(self::DRAFT, $data);

        return redirect()->route('withdrawal.confirm');
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        $draft = $request->session()->get(self::DRAFT);

        if (! is_array($draft)) {
            return redirect()->route('withdrawal.create');
        }

        return $this->page('confirm', ['statement' => $draft]);
    }

    public function store(Request $request, WithdrawalStatements $statements): RedirectResponse
    {
        $draft = $request->session()->pull(self::DRAFT);

        if (! is_array($draft)) {
            return redirect()->route('withdrawal.create');
        }

        $withdrawal = $statements->submit([
            'name' => (string) ($draft['name'] ?? ''),
            'email' => (string) ($draft['email'] ?? ''),
            'order_number' => (string) ($draft['order_number'] ?? ''),
            'items' => isset($draft['items']) ? (string) $draft['items'] : null,
        ]);
        $request->session()->put(self::SENT, ['reference' => $withdrawal->reference, 'email' => $withdrawal->email]);

        return redirect()->route('withdrawal.sent');
    }

    public function sent(Request $request): Response|RedirectResponse
    {
        $sent = $request->session()->get(self::SENT);

        if (! is_array($sent)) {
            return redirect()->route('withdrawal.create');
        }

        return $this->page('sent', ['sent' => $sent]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $step, array $data): Response
    {
        return Inertia::render('extension', [
            'screen' => 'withdrawal',
            'title' => __('Withdraw from contract'),
            'data' => [
                'step' => $step,
                'urls' => [
                    'review' => route('withdrawal.review'),
                    'confirm' => route('withdrawal.store'),
                    'edit' => route('withdrawal.create'),
                ],
                ...$data,
            ],
        ]);
    }
}
