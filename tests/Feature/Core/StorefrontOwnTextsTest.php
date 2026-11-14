<?php

namespace Tests\Feature\Core;

use PnShop\Foundation\PnShop;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The storefront speaks in PN Shop's own words: the account and sign-in sentences of the
 * starter kit the storefront began from are gone, and every text the storefront asks for
 * with t('...') has a Bulgarian translation.
 */
class StorefrontOwnTextsTest extends TestCase
{
    /** Sentences of the starter kit's account and sign-in pages that the storefront no longer uses. */
    private const STARTER_KIT_SENTENCES = [
        'Are you sure you want to delete your account?',
        'Delete your account and all of its resources',
        'Ensure your account is using a long, random password to stay secure',
        'Enter your details below to create your account',
        'Enter your email and password below to log in',
        'Enter your email to receive a password reset link',
        'Manage your profile and account settings',
        'Please enter your new password below',
        'Please proceed with caution, this cannot be undone.',
        'Please verify your email address by clicking on the link we just emailed to you.',
        'This is a secure area of the application. Please confirm your password before continuing.',
        "Update your account's appearance settings",
        'Update your name and email address',
    ];

    public function test_the_storefront_no_longer_uses_the_starter_kit_sentences(): void
    {
        $found = [];

        foreach ($this->storefrontFiles() as $path => $source) {
            foreach (self::STARTER_KIT_SENTENCES as $sentence) {
                if (str_contains($source, $sentence) || str_contains($source, str_replace("'", "\\'", $sentence))) {
                    $found[] = "{$sentence} ({$path})";
                }
            }
        }

        $translations = $this->bulgarian();
        foreach (self::STARTER_KIT_SENTENCES as $sentence) {
            if (array_key_exists($sentence, $translations)) {
                $found[] = "{$sentence} (lang/bg.json)";
            }
        }

        $this->assertSame([], $found);
    }

    public function test_every_account_and_sign_in_text_has_a_bulgarian_translation(): void
    {
        $translations = $this->bulgarian();
        $missing = [];

        foreach ($this->storefrontFiles() as $path => $source) {
            if (! preg_match('#/(pages/auth|pages/settings|layouts/settings|components/delete-user)#', $path)) {
                continue;
            }

            preg_match_all("/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
            preg_match_all('/\bt\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);

            foreach ([...$single[1], ...$double[1]] as $key) {
                $key = stripslashes($key);
                if (! array_key_exists($key, $translations)) {
                    $missing[] = "{$key} ({$path})";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    /** @return array<string, string> */
    private function bulgarian(): array
    {
        return json_decode((string) file_get_contents(PnShop::path('lang/bg.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> path => source */
    private function storefrontFiles(): array
    {
        $files = [];
        foreach (Finder::create()->files()->in(PnShop::path('resources/js'))->name(['*.tsx', '*.ts']) as $file) {
            $files[str_replace('\\', '/', $file->getPathname())] = $file->getContents();
        }

        return $files;
    }
}
