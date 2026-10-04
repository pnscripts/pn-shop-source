<?php

namespace PnShop\Returns\Filament\Resources\Returns\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Credit\Exchanges;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\Refund;
use PnShop\Returns\Filament\Resources\Returns\ReturnRequestResource;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Returns\Models\ReturnRequestLine;
use PnShop\Returns\ReturnService;
use PnShop\Returns\ReturnStatus;
use PnShop\Sales\Exceptions\OrderException;

/**
 * A return with the next steps as actions: approve or reject, receive (optionally back
 * into stock), refund what arrived, close.
 */
class ViewReturnRequest extends ViewRecord
{
    protected static string $resource = ReturnRequestResource::class;

    public function getTitle(): string
    {
        return 'Return '.$this->return()->number;
    }

    protected function getHeaderActions(): array
    {
        $note = fn (string $label = 'Note to the customer') => Textarea::make('note')->label($label)->rows(3)->maxLength(2000);

        return [
            Action::make('approve')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn () => $this->can(ReturnStatus::Approved))
                ->schema([$note()->placeholder('E.g. where to send the parcel.')])
                ->action(fn (array $data) => $this->run(fn (ReturnService $returns) => $returns->approve($this->return(), $data['note'] ?? null, auth('admin')->user()), 'Return approved.')),
            Action::make('reject')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn () => $this->can(ReturnStatus::Rejected))
                ->schema([$note('Reason (sent to the customer)')->required()])
                ->action(fn (array $data) => $this->run(fn (ReturnService $returns) => $returns->reject($this->return(), $data['note'], auth('admin')->user()), 'Return rejected.')),
            Action::make('returnLabel')
                ->label('Create return label')
                ->icon(Heroicon::OutlinedPrinter)
                ->visible(fn () => app(ReturnService::class)->canCreateLabel($this->return()))
                ->requiresConfirmation()
                ->modalDescription('Asks the order\'s carrier for a return label and emails it to the customer.')
                ->action(fn () => $this->run(fn (ReturnService $returns) => $returns->createLabel($this->return(), auth('admin')->user()), 'Return label sent to the customer.')),
            Action::make('receive')
                ->label('Mark received')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->visible(fn () => $this->can(ReturnStatus::Received))
                ->fillForm(fn () => ['received' => $this->return()->lines->mapWithKeys(fn (ReturnRequestLine $line) => [$line->id => $line->quantity])->all(), 'restock' => true])
                ->schema(fn () => [
                    ...$this->return()->lines->map(fn (ReturnRequestLine $line) => TextInput::make("received.{$line->id}")
                        ->label(($line->orderItem->product_title ?? 'Product').' — received')
                        ->integer()->minValue(0)->maxValue($line->quantity)->required())->all(),
                    Toggle::make('restock')->label('Put the received items back into stock'),
                ])
                ->action(fn (array $data) => $this->run(fn (ReturnService $returns) => $returns->receive($this->return(), array_map('intval', $data['received'] ?? []), (bool) $data['restock'], auth('admin')->user()), 'Return received.')),
            Action::make('refund')
                ->label('Refund received items')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->visible(fn () => $this->can(ReturnStatus::Refunded))
                ->modalDescription('Refunds the price paid for the received items.')
                ->schema([
                    Select::make('to')
                        ->label('Refund to')
                        ->options([Refund::TO_ORIGINAL => 'The order\'s payment', Refund::TO_STORE_CREDIT => 'Store credit (a gift card for guests)'])
                        ->default(Refund::TO_ORIGINAL)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->run(fn (ReturnService $returns) => $returns->refund($this->return(), auth('admin')->user(), (string) $data['to']), 'Return refunded.')),
            Action::make('exchange')
                ->label('Exchange')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->visible(fn () => $this->can(ReturnStatus::Exchanged))
                ->modalDescription('Places a new order for other items, paid with the value of the received ones. A difference in the customer\'s favour stays as store credit.')
                ->schema([
                    Repeater::make('items')
                        ->label('New items')
                        ->minItems(1)
                        ->schema([
                            Select::make('variant_id')
                                ->label('Product')
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => ProductVariant::query()->with('product')
                                    ->where(fn ($query) => $query->whereLike('sku', '%'.$search.'%')->orWhereHas('product', fn ($product) => $product->whereLike('title', '%'.$search.'%')))
                                    ->limit(30)->get()->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => trim(($variant->product->title ?? '').' '.$variant->label().($variant->sku ? " ({$variant->sku})" : ''))])->all())
                                ->getOptionLabelUsing(fn (mixed $value) => ($variant = ProductVariant::query()->with('product')->whereKey($value)->first()) !== null ? trim(($variant->product->title ?? '').' '.$variant->label()) : null),
                            TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
                        ])
                        ->columns(2),
                    Select::make('payment_method_id')
                        ->label('Payment for any difference')
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->orderBy('position')->pluck('name', 'id')->all())
                        ->helperText('Used only when the new items cost more than the returned ones.'),
                ])
                ->action(function (array $data): void {
                    $variants = [];

                    foreach ($data['items'] ?? [] as $item) {
                        $variants[(int) $item['variant_id']] = ($variants[(int) $item['variant_id']] ?? 0) + (int) $item['quantity'];
                    }

                    $this->run(fn () => app(Exchanges::class)->exchange($this->return(), $variants, isset($data['payment_method_id']) ? (int) $data['payment_method_id'] : null, actor: auth('admin')->user()), 'Exchange order placed.');
                }),
            Action::make('close')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->visible(fn () => $this->can(ReturnStatus::Closed))
                ->schema([$note()])
                ->action(fn (array $data) => $this->run(fn (ReturnService $returns) => $returns->close($this->return(), $data['note'] ?? null, auth('admin')->user()), 'Return closed.')),
        ];
    }

    private function return(): ReturnRequest
    {
        /** @var ReturnRequest $return */
        $return = $this->getRecord();

        return $return->loadMissing('lines.orderItem');
    }

    private function can(ReturnStatus $to): bool
    {
        return $this->return()->status->canTransitionTo($to) && (auth('admin')->user()?->can('update', $this->return()) ?? false);
    }

    private function run(Closure $operation, string $success): void
    {
        try {
            $operation(app(ReturnService::class));
        } catch (OrderException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->getRecord()->refresh();
        Notification::make()->success()->title($success)->send();
    }
}
