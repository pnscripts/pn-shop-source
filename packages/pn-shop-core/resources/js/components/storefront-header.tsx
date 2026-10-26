import AppLogo from '@/components/app-logo';
import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Icon } from '@/components/icon';
import LanguageSwitcher from '@/components/language-switcher';
import { MenuLink } from '@/components/menu-link';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useTranslations } from '@/hooks/use-translations';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, LayoutDashboard, LogIn, Menu, ShoppingBag, Store, UserPlus, UserRound } from 'lucide-react';

export function StorefrontHeader() {
    const { auth, cartCount, menus } = usePage<SharedData>().props;
    const t = useTranslations();

    return (
        <header className="border-sidebar-border/80 bg-background/95 sticky top-0 z-40 border-b backdrop-blur">
            <div className="mx-auto flex h-16 items-center gap-2 px-4 sm:gap-4 md:max-w-7xl">
                <div className="lg:hidden">
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-[34px] w-[34px]" aria-label={t('Navigation Menu')}>
                                <Menu className="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" className="bg-sidebar w-64">
                            <SheetTitle className="sr-only">{t('Navigation Menu')}</SheetTitle>
                            <SheetHeader className="text-left">
                                <AppLogo />
                            </SheetHeader>
                            <div className="flex flex-col gap-3 p-4 text-sm font-medium">
                                {menus.header.length === 0 ? (
                                    <Link href={route('shop.index')} className="flex items-center gap-2">
                                        <Store className="h-4 w-4" />
                                        {t('Shop')}
                                    </Link>
                                ) : (
                                    menus.header.map((item) => (
                                        <div key={item.label} className="flex flex-col gap-2">
                                            <MenuLink item={item} />
                                            {item.children.map((child) => (
                                                <MenuLink key={child.label} item={child} className="text-muted-foreground pl-4" />
                                            ))}
                                        </div>
                                    ))
                                )}
                                <Link href={route('cart.index')} className="flex items-center gap-2">
                                    <ShoppingBag className="h-4 w-4" />
                                    {t('Cart (:count)', { count: cartCount })}
                                </Link>
                                {auth.user ? (
                                    <>
                                        <Link href={route('dashboard')} className="flex items-center gap-2">
                                            <LayoutDashboard className="h-4 w-4" />
                                            {t('Dashboard')}
                                        </Link>
                                    </>
                                ) : (
                                    <>
                                        <Link href={route('login')} className="flex items-center gap-2">
                                            <LogIn className="h-4 w-4" />
                                            {t('Log in')}
                                        </Link>
                                        <Link href={route('register')} className="flex items-center gap-2">
                                            <UserPlus className="h-4 w-4" />
                                            {t('Register')}
                                        </Link>
                                    </>
                                )}
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <Link href={route('home')} className="flex min-w-0 items-center space-x-2">
                    <AppLogo />
                </Link>

                <nav className="ml-6 hidden items-center gap-1 lg:flex">
                    {menus.header.length === 0 ? (
                        <Button variant="ghost" asChild>
                            <Link href={route('shop.index')}>
                                <Icon iconNode={Store} className="h-4 w-4" />
                                {t('Shop')}
                            </Link>
                        </Button>
                    ) : (
                        menus.header.map((item) =>
                            item.children.length === 0 ? (
                                <Button key={item.label} variant="ghost" asChild>
                                    <MenuLink item={item} />
                                </Button>
                            ) : (
                                <DropdownMenu key={item.label}>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost">
                                            {item.label}
                                            <ChevronDown className="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start">
                                        {item.url && (
                                            <DropdownMenuItem asChild>
                                                <MenuLink item={item} />
                                            </DropdownMenuItem>
                                        )}
                                        {item.children.map((child) => (
                                            <DropdownMenuItem key={child.label} asChild>
                                                <MenuLink item={child} />
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ),
                        )
                    )}
                </nav>

                <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                    <Button variant="ghost" asChild className="px-2 sm:px-4">
                        <Link href={route('cart.index')} className="relative" aria-label={t('Cart (:count)', { count: cartCount })}>
                            <ShoppingBag className="h-5 w-5" />
                            <span className="hidden sm:inline">{t('Cart')}</span>
                            {cartCount > 0 && (
                                <span className="bg-primary text-primary-foreground absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs">
                                    {cartCount}
                                </span>
                            )}
                        </Link>
                    </Button>
                    <LanguageSwitcher />
                    <AppearanceToggleDropdown />
                    {auth.user ? (
                        <Button variant="outline" asChild className="w-9 px-0 sm:w-auto sm:px-4">
                            <Link href={route('dashboard')} aria-label={t('Dashboard')}>
                                <LayoutDashboard className="h-4 w-4 sm:hidden" />
                                <span className="hidden sm:inline">{t('Dashboard')}</span>
                            </Link>
                        </Button>
                    ) : (
                        <>
                            {/* Phones: one account icon; Register is in the menu and on the sign-in page. */}
                            <Button variant="ghost" size="icon" asChild className="sm:hidden">
                                <Link href={route('login')} aria-label={t('Log in')}>
                                    <UserRound className="h-5 w-5" />
                                </Link>
                            </Button>
                            <Button variant="ghost" asChild className="hidden sm:inline-flex">
                                <Link href={route('login')}>{t('Log in')}</Link>
                            </Button>
                            <Button asChild className="hidden sm:inline-flex">
                                <Link href={route('register')}>{t('Register')}</Link>
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
