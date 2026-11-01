<?php

namespace PnShop\Api\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PnShop\Acl\Models\AdminUser;
use PnShop\Api\Http\Controllers\ApiController;
use PnShop\Localization\Localization;
use PnShop\Localization\Models\Language;

/**
 * Base of the Admin API controllers: the staff member behind the token, translation input
 * and the "changed since" filter used by sync jobs.
 */
abstract class AdminController extends ApiController
{
    /**
     * Setting stock, or switching stock control off (track_inventory, allow_backorder),
     * through a product or variant field needs the same permission as the stock endpoint,
     * not only the right to edit the product.
     */
    protected function authorizeStock(Request $request): void
    {
        if ($request->hasAny(['stock', 'track_inventory', 'allow_backorder'])) {
            Gate::authorize('catalog.inventory.manage');
        }
    }

    protected function admin(Request $request): AdminUser
    {
        $admin = $request->user();

        return $admin instanceof AdminUser ? $admin : abort(401);
    }

    /**
     * Rules for "translations": {"<locale>": {"<field>": "..."}} for the store's other languages.
     *
     * @param  array<string, list<mixed>>  $fields  field => rules
     * @return array<string, mixed>
     */
    protected function translationRules(array $fields): array
    {
        $locales = app(Localization::class)->languages()
            ->reject(fn (Language $language) => $language->is_default)
            ->map(fn (Language $language) => $language->code)
            ->values()
            ->all();

        $rules = ['translations' => ['sometimes', 'array:'.implode(',', $locales)]];

        foreach ($fields as $field => $fieldRules) {
            $rules["translations.*.{$field}"] = ['nullable', ...$fieldRules];
        }

        return $rules;
    }

    /**
     * Fill a translatable model from validated input; "translations" are stored when it is
     * saved (empty values remove a language's text, falling back to the source language).
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    protected function fillTranslatable(Model $model, array $data): Model
    {
        $model->fill(Arr::except($data, ['translations']));

        if (array_key_exists('translations', $data)) {
            $model->setAttribute('translations_input', $data['translations']);
        }

        return $model;
    }

    /**
     * ?filter[updated_since]=<ISO 8601>: only records changed at or after that moment.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function updatedSince(Builder $query, Request $request): Builder
    {
        $since = $request->input('filter.updated_since');

        if ($since === null) {
            return $query;
        }

        $invalid = ValidationException::withMessages(['filter.updated_since' => __('Use an ISO 8601 date and time.')]);

        if (! is_string($since) || trim($since) === '') {
            throw $invalid;
        }

        try {
            $moment = Carbon::parse($since);
        } catch (\Throwable) {
            throw $invalid;
        }

        return $query->where($query->getModel()->qualifyColumn('updated_at'), '>=', $moment);
    }
}
