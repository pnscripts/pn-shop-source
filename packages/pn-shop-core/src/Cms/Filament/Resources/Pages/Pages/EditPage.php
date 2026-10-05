<?php

namespace PnShop\Cms\Filament\Resources\Pages\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use PnShop\Cms\Filament\Resources\Pages\PageResource;
use PnShop\Cms\Models\Page;
use PnShop\Cms\PageRevisions;
use PnShop\Localization\Filament\SavesTranslations;

class EditPage extends EditRecord
{
    use SavesTranslations {
        afterSave as saveTranslationsAfterSave;
    }

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [PageResource::designAction(), PageResource::previewAction(), DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        $this->saveTranslationsAfterSave();

        /** @var Page $page */
        $page = $this->getRecord();
        app(PageRevisions::class)->record($page, auth('admin')->user());
    }
}
