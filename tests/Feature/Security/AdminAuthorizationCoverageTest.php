<?php

namespace Tests\Feature\Security;

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The admin runs in Filament's strict authorization mode: every screen must be covered by
 * a policy (or, for a custom page, its own canAccess()), so nothing is open to every staff
 * account by accident. This walks every registered screen.
 */
class AdminAuthorizationCoverageTest extends AdminTestCase
{
    public function test_the_panel_uses_strict_authorization(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->isAuthorizationStrict());
    }

    public function test_every_resource_and_relation_manager_has_a_policy(): void
    {
        $panel = Filament::getPanel('admin');
        $missing = [];

        foreach ($panel->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            $model = $resource::getModel();
            $policy = Gate::getPolicyFor($model);

            if ($policy === null) {
                $missing[] = "{$resource} ({$model})";

                continue;
            }

            foreach (['viewAny', 'view', 'update'] as $ability) {
                if (! method_exists($policy, $ability)) {
                    $missing[] = $policy::class."::{$ability}() for {$resource}";
                }
            }

            foreach ($resource::getRelations() as $relation) {
                $managers = $relation instanceof RelationGroup ? $relation->getManagers() : [$relation];

                foreach ($managers as $manager) {
                    $manager = is_string($manager) ? $manager : $manager->relationManager;
                    /** @var class-string<RelationManager> $manager */
                    $related = (new $model)->{$manager::getRelationshipName()}()->getRelated();

                    if (Gate::getPolicyFor($related) === null) {
                        $missing[] = "{$manager} (".$related::class.')';
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Admin screens without a policy (they would be open to all staff).');
    }

    public function test_administrators_open_every_list_and_create_screen_in_strict_mode(): void
    {
        $this->actingAsAdministrator();

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            foreach (['index', 'create'] as $page) {
                if ($resource::hasPage($page)) {
                    $this->get($resource::getUrl($page))->assertOk();
                }
            }
        }
    }

    public function test_every_custom_page_checks_access(): void
    {
        $open = [];

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            if (is_a($page, ResourcePage::class, true) || is_a($page, Dashboard::class, true)) {
                continue;
            }

            if ((new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName() === Page::class) {
                $open[] = $page;
            }
        }

        $this->assertSame([], $open, 'Custom admin pages without canAccess().');
    }
}
