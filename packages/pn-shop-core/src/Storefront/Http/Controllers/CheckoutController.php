<?php

namespace PnShop\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PnShop\Cart\ShoppingCartService;
use PnShop\Customer\Models\CustomerAddress;
use PnShop\Customer\PostalAddress;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentOutcome;
use PnShop\Payment\PaymentService;
use PnShop\Sales\Checkout\CheckoutService;
use PnShop\Sales\Checkout\DeliveryQuote;
use PnShop\Sales\Exceptions\CheckoutException;
use PnShop\Security\BotTrap;
use PnShop\Shipping\ShippingService;
use PnShop\Storefront\Http\Controllers\Account\AddressesController;
use PnShop\Storefront\Http\Requests\Checkout\StoreCheckoutRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CheckoutController extends Controller
{
    public function __construct(
        private ShoppingCartService $cart,
        private CheckoutService $checkout,
        private PaymentService $payments,
        private ShippingService $shipping,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->cart->getCartItems()->isEmpty()) {
            return redirect()->route('cart.index')->with('error', __('Your cart is empty.'));
        }

        $user = $this->customer($request);

        return Inertia::render('checkout/index', [
            'cart' => $this->cart->toArray(),
            'paymentMethods' => $this->payments
                ->availableMethods(new PaymentContext($this->cart->getFinalPrice(), customer: $user))
                ->map(fn (PaymentMethod $method) => ['id' => $method->id, 'name' => $method->name, 'description' => $method->description])
                ->values(),
            'countries' => AddressesController::countryOptions(),
            'shippingRequired' => $this->shipping->isRequired($this->cart->getCartItems()),
            'botTrap' => BotTrap::fields(),
            'savedAddresses' => $user?->addresses->map(fn (CustomerAddress $address) => [
                'id' => $address->id,
                ...$address->only(PostalAddress::FIELDS),
                'lines' => $address->toPostalAddress()->lines(),
                'is_default_shipping' => $address->is_default_shipping,
                'is_default_billing' => $address->is_default_billing,
            ])->values() ?? [],
            'defaults' => [
                'email' => $user->email ?? '',
                // Guests and customers without an address book start from their account name.
                'first_name' => Str::before((string) ($user->name ?? ''), ' '),
                'last_name' => Str::contains((string) ($user->name ?? ''), ' ') ? Str::after((string) $user?->name, ' ') : '',
                'phone' => $user->phone ?? '',
            ],
        ]);
    }

    /**
     * Delivery options and totals for the address being entered, as the customer types.
     * A POST keeps the postcode out of URLs and logs.
     */
    public function quote(Request $request, DeliveryQuote $delivery): JsonResponse
    {
        return response()->json($delivery->for($request->validate(DeliveryQuote::RULES), $this->customer($request)));
    }

    public function store(StoreCheckoutRequest $request): RedirectResponse|SymfonyResponse
    {
        try {
            $order = $this->checkout->place($request->validated(), $this->customer($request));
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $recent = self::recentOrderIds($request)
            ->push($order->id)
            ->unique()
            ->values()
            ->all();

        $request->session()->put('recent_order_ids', $recent);

        $payment = $this->payments->start($order);

        if ($payment->outcome === PaymentOutcome::Redirect && $payment->redirectUrl !== null) {
            return Inertia::location($payment->redirectUrl);
        }

        $redirect = redirect()->route('orders.show', $order)->with('success', __('Thank you! Your order has been placed.'));

        return $payment->outcome === PaymentOutcome::Failed ? $redirect->with('error', $payment->message) : $redirect;
    }
}
