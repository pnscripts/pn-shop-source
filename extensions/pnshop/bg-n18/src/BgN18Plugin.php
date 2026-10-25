<?php

namespace PnShop\Plugins\BgN18;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PnShop\Extension\Plugin;
use PnShop\Plugins\BgN18\Console\AuditFileCommand;
use PnShop\Plugins\BgN18\Listeners\IssueDocuments;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Plugins\BgN18\Policies\SalesDocumentPolicy;
use PnShop\Sales\Events\OrderStateChanged;
use PnShop\Shipping\Events\ShipmentCreated;

class BgN18Plugin extends Plugin
{
    protected function bootPlugin(): void
    {
        Gate::policy(SalesDocument::class, SalesDocumentPolicy::class);

        Event::listen(OrderStateChanged::class, [IssueDocuments::class, 'paid']);
        Event::listen(ShipmentCreated::class, [IssueDocuments::class, 'shipped']);

        if ($this->app->runningInConsole()) {
            $this->commands([AuditFileCommand::class]);
        }
    }
}
