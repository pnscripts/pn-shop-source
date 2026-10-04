<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Exceptions\InsufficientBalance;
use PnShop\Credit\Models\CreditAccount;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Customer\Models\User;
use PnShop\Localization\Localization;
use PnShop\Money\MoneyPresenter;

/**
 * Gift cards and customers' store credit. Needs sales.credit.manage. Gift card codes are
 * returned once, when a card is issued: only a hash is kept.
 */
class GiftCardController extends AdminController
{
    /**
     * List gift cards
     *
     * Newest first. Codes are not returned: `last4` identifies a card.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        Gate::authorize('viewAny', GiftCard::class);

        $cards = GiftCard::query()->orderByDesc('id')->cursorPaginate($this->perPage($request))->withQueryString();

        return $this->paginated($cards, fn (GiftCard $card) => $this->present($card));
    }

    /**
     * Issue a gift card
     *
     * `amount` in `currency` (default: the shop's). With `recipient_email`, the code is
     * emailed there. The response has the `code`: it is not shown again.
     */
    public function store(Request $request, Balances $balances): JsonResponse
    {
        Gate::authorize('create', GiftCard::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'recipient_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        [$card, $code] = $balances->issueGiftCard(
            Money::of((string) $data['amount'], $data['currency'] ?? app(Localization::class)->defaultCurrency()->code, roundingMode: RoundingMode::HalfUp),
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $data['recipient_email'] ?? null,
            $data['note'] ?? null,
            $this->admin($request),
        );

        if ($card->recipient_email !== null) {
            Notification::route('mail', $card->recipient_email)->notify(new GiftCardIssued($card, $code));
        }

        return response()->json(['data' => [...$this->present($card), 'code' => $code]], 201);
    }

    /**
     * Look up a gift card by code
     *
     * @return array<string, mixed>
     */
    public function lookup(Request $request, Balances $balances): array
    {
        Gate::authorize('viewAny', GiftCard::class);

        $card = $balances->findGiftCard((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        abort_if($card === null, 404);

        return ['data' => $this->present($card)];
    }

    /**
     * Update a gift card
     *
     * `is_active`, `expires_at`, `note`. Change the balance with POST /gift-cards/{id}/adjustments.
     *
     * @return array<string, mixed>
     */
    public function update(Request $request, GiftCard $giftCard): array
    {
        Gate::authorize('update', $giftCard);

        $giftCard->update($request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]));

        return ['data' => $this->present($giftCard->refresh())];
    }

    /**
     * Adjust a gift card's balance
     *
     * `amount` (positive adds, negative takes away) and a `note`.
     *
     * @return array<string, mixed>
     */
    public function adjust(Request $request, GiftCard $giftCard, Balances $balances): array
    {
        Gate::authorize('update', $giftCard);

        $data = $request->validate(['amount' => ['required', 'numeric', 'not_in:0', 'min:-1000000', 'max:1000000'], 'note' => ['required', 'string', 'max:500']]);

        $this->change($balances, $giftCard, $data, $request);

        return ['data' => $this->present($giftCard->refresh())];
    }

    /**
     * A customer's store credit
     *
     * One balance per currency.
     *
     * @return array<string, mixed>
     */
    public function credit(User $customer): array
    {
        Gate::authorize('viewAny', CreditAccount::class);

        return ['data' => CreditAccount::query()->where('user_id', $customer->id)->orderBy('currency')->get()
            ->map(fn (CreditAccount $account) => ['currency' => $account->currency, 'balance' => MoneyPresenter::present($account->balanceMoney())])
            ->all()];
    }

    /**
     * Change a customer's store credit
     *
     * `amount` (positive adds, negative takes away), `currency` (default: the shop's) and a `note`.
     *
     * @return array<string, mixed>
     */
    public function adjustCredit(Request $request, User $customer, Balances $balances): array
    {
        Gate::authorize('create', CreditAccount::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $this->change($balances, $balances->creditAccount($customer, $data['currency'] ?? app(Localization::class)->defaultCurrency()->code), $data, $request);

        return $this->credit($customer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function change(Balances $balances, GiftCard|CreditAccount $account, array $data, Request $request): void
    {
        try {
            $balances->change(
                $account,
                Money::of((string) $data['amount'], $account->balanceMoney()->getCurrency(), roundingMode: RoundingMode::HalfUp),
                BalanceReason::Adjustment,
                admin: $this->admin($request),
                note: (string) $data['note'],
            );
        } catch (InsufficientBalance $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GiftCard $card): array
    {
        return [
            'id' => $card->id,
            'last4' => $card->last4,
            'currency' => $card->currency,
            'balance' => MoneyPresenter::present($card->balanceMoney()),
            'initial_amount' => MoneyPresenter::present($card->initialMoney()),
            'expires_at' => $card->expires_at?->toIso8601String(),
            'is_active' => $card->is_active,
            'recipient_email' => $card->recipient_email,
            'customer_id' => $card->user_id,
            'note' => $card->note,
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }
}
