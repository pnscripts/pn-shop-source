<?php

namespace Tests\Feature\Credit;

use App\Models\User;
use Brick\Money\Money;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\StaffTokens;
use PnShop\Credit\BalanceReason;
use PnShop\Credit\Balances;
use PnShop\Credit\Exceptions\InsufficientBalance;
use PnShop\Credit\Filament\RelationManagers\StoreCreditRelationManager;
use PnShop\Credit\Filament\Resources\GiftCards\Pages\CreateGiftCard;
use PnShop\Credit\Filament\Resources\GiftCards\Pages\ListGiftCards;
use PnShop\Credit\Models\BalanceTransaction;
use PnShop\Credit\Models\GiftCard;
use PnShop\Credit\Notifications\GiftCardIssued;
use PnShop\Customer\Filament\Resources\Customers\Pages\EditCustomer;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Gift cards and store credit: issuing, finding by code, the balance ledger, and managing
 * them in the admin and the Admin API.
 */
class BalancesTest extends AdminTestCase
{
    public function test_gift_cards_keep_only_a_hash_of_their_code_and_a_ledger_of_changes(): void
    {
        $balances = app(Balances::class);
        [$card, $code] = $balances->issueGiftCard(Money::of(50, 'USD'), note: 'Raffle');

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{4}(-[2-9A-HJKMNP-Z]{4}){3}$/', $code);
        $this->assertSame(substr(str_replace('-', '', $code), -4), $card->last4);
        $this->assertSame(5000, $card->initial_amount);
        $this->assertDatabaseMissing('gift_cards', ['code_hash' => $code]);

        // Found however the customer types it.
        $this->assertTrue($card->is($balances->findGiftCard(strtolower(str_replace('-', ' ', $code)))));
        $this->assertNull($balances->findGiftCard('NOPE-NOPE'));

        $balances->change($card, Money::of(-20, 'USD'), BalanceReason::Spent);
        $this->assertSame('30.00', (string) $card->refresh()->balanceMoney()->getAmount());

        try {
            $balances->change($card, Money::of(-31, 'USD'), BalanceReason::Spent);
            $this->fail('A balance cannot go below zero.');
        } catch (InsufficientBalance) {
            $this->assertSame(3000, $card->refresh()->balance);
        }

        $this->assertSame([[5000, 5000, 'issued'], [-2000, 3000, 'spent']], BalanceTransaction::query()->orderBy('id')->get()
            ->map(fn (BalanceTransaction $row) => [$row->amount, $row->balance_after, $row->reason->value])->all());
    }

    public function test_staff_issue_gift_cards_and_the_recipient_gets_the_code(): void
    {
        Notification::fake();
        $this->actingAsAdministrator();

        Livewire::test(CreateGiftCard::class)
            ->fillForm(['amount' => '25', 'currency' => 'USD', 'recipient_email' => 'ana@example.test'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $card = GiftCard::query()->sole();
        $this->assertSame(2500, $card->balance);

        $sent = null;
        Notification::assertSentOnDemand(GiftCardIssued::class, function (GiftCardIssued $notification, array $channels, AnonymousNotifiable $notifiable) use (&$sent) {
            $sent = $notification->code;

            return $notifiable->routes['mail'] === 'ana@example.test';
        });
        $this->assertNotNull($sent);
        $this->assertTrue($card->is(app(Balances::class)->findGiftCard((string) $sent)));

        // Staff find it with the code or its last four characters, and adjust it.
        Livewire::test(ListGiftCards::class)
            ->searchTable((string) $sent)->assertCanSeeTableRecords([$card])
            ->searchTable('XXXX')->assertCanNotSeeTableRecords([$card])
            ->searchTable($card->last4)->assertCanSeeTableRecords([$card])
            ->callTableAction('adjustBalance', $card, data: ['amount' => '-5', 'note' => 'Goodwill correction'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2000, $card->refresh()->balance);
    }

    public function test_staff_give_customers_store_credit(): void
    {
        $this->actingAsAdministrator();
        $customer = User::factory()->create();

        Livewire::test(StoreCreditRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->callTableAction('addCredit', data: ['currency' => 'USD', 'amount' => '15', 'note' => 'Late delivery'])
            ->assertHasNoTableActionErrors();

        $account = app(Balances::class)->creditAccount($customer, 'USD');
        $this->assertSame(1500, $account->balance);
        $this->assertSame('Late delivery', $account->transactions()->sole()->note);
    }

    public function test_the_admin_api_issues_gift_cards_and_changes_store_credit(): void
    {
        $customer = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->withPermissions(['sales.credit.manage'])->create(), 'erp', ['sales.credit.manage'])->plainTextToken];

        $code = $this->withHeaders($headers)->postJson('/api/admin/v1/gift-cards', ['amount' => '40.00'])
            ->assertCreated()
            ->assertJsonPath('data.balance.amount', '40.00')
            ->json('data.code');

        $id = $this->withHeaders($headers)->postJson('/api/admin/v1/gift-cards/lookup', ['code' => $code])->assertOk()->json('data.id');
        $this->withHeaders($headers)->getJson('/api/admin/v1/gift-cards')->assertOk()->assertJsonMissingPath('data.0.code');
        $this->withHeaders($headers)->postJson("/api/admin/v1/gift-cards/{$id}/adjustments", ['amount' => '-50', 'note' => 'Too much'])->assertUnprocessable();

        $this->withHeaders($headers)->postJson("/api/admin/v1/customers/{$customer->id}/credit", ['amount' => '12.50', 'note' => 'Apology'])
            ->assertOk()
            ->assertJsonPath('data.0.balance.amount', '12.50');

        $other = ['Authorization' => 'Bearer '.app(StaffTokens::class)->issue(AdminUser::factory()->withPermissions(['sales.orders.view'])->create(), 'x', ['sales.orders.view'])->plainTextToken];
        $this->withHeaders($other)->getJson('/api/admin/v1/gift-cards')->assertForbidden();
    }
}
