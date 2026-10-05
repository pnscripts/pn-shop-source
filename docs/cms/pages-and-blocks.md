# Pages and content blocks

The CMS lives in `PnShop\Cms`. Staff manage pages in Admin → Content → Pages.

## Pages

- **Address:** a page lives at `/{slug}` in each language: `/about-us`, `/bg/za-nas`. The shop's own addresses always win. Slugs they use (`shop`, `cart`, `checkout`, `admin`, language codes, …) are refused.
- **Publishing:**
  - a page is shown when its status is *Published*, its *Publish from* date has come and its *Unpublish at* date (if any) has not;
  - a future publish date schedules the page; no job is needed;
  - drafts and scheduled pages can be checked with *Preview*, a signed link valid for an hour.
- **Homepage:** *Use as the homepage* makes a page the homepage (only one can be). Without one, the default home with the newest products is shown.
- **Translations:** title, slug, excerpt and meta fields are translated in the *Translations* section.
- **Revisions:**
  - every save keeps a full copy (fields, translations, blocks) on the *Revisions* tab;
  - *Restore* brings one back as a new save;
  - the number kept per page is set in Admin → Settings → Content.

## Content blocks

A page's body is a list of blocks, edited per language. A language without blocks shows the default language's blocks.

| Block | Content |
|---|---|
| Hero banner | heading, text, background image, button |
| Text | rich text: headings, lists, links, quotes, tables. Cleaned on the server, so scripts and unsafe links never reach the storefront. |
| Image | image with alternative text, caption and optional link |
| Products | newest products, products in a category (with subcategories), or chosen products, with a "View all" link |
| Categories | chosen categories linking to the shop |
| Call to action | heading, text and a button |
| Video | a YouTube or Vimeo link, embedded from `youtube-nocookie.com` / `player.vimeo.com` with tracking off |
| Custom HTML | shown as entered, scripts included. Only staff with the **cms.html_block** permission (administrators by default) can add or change these blocks; others can edit the page around them. |

- **Links:** a link like `/shop` gets the visitor's language prefix (`/bg/shop`). Full `https://` links open in a new tab.
- **Images:** uploaded images go into the media library and get responsive WebP versions.

## Visual editor

*Design* (on the pages list and the edit page) opens a page's blocks next to a preview of the page in the storefront's own layout, theme and block components.

- **Live preview:** typing, adding, moving and removing blocks shows at once in the preview. Nothing is published until *Save changes*; saving works like the edit page (same block rules, a revision is kept).
- **Click to edit:** clicking a block in the preview opens it in the editor, and the block being edited is outlined in the preview. Links in the preview do not navigate.
- **Sizes:** *Desktop*, *Tablet* and *Mobile* resize the preview.
- **Languages:** one language at a time; the language buttons switch to another. The preview uses that language's address (`/bg/…`).
- **New blocks:** a block without content yet shows a placeholder until it is filled in. Images uploaded but not saved yet show after saving.
- **Who:** staff who may edit the page. The preview (`/preview/pages/{id}/design`) is refused to everyone else and is never indexed.
- **Data:** the same blocks as the edit page's content tabs; nothing is stored differently.

A block type needs nothing extra for the visual editor: the preview uses its `props()` and its registered React component.

## Adding a block type

A block type is a PHP class plus a React component with the same key:

```php
final class TestimonialBlock extends BlockType
{
    public function key(): string { return 'testimonial'; }
    public function label(): string { return 'Testimonial'; }
    public function fields(): array { return [Textarea::make('quote')->required(), TextInput::make('author')]; }

    // Optional: data for the storefront (load models, present media). Return null to skip.
    public function props(array $data): ?array { return ['quote' => $data['quote'], 'author' => $data['author'] ?? null]; }
}

app(BlockRegistry::class)->register(TestimonialBlock::class);
```

```tsx
import { registerBlock } from '@/components/blocks';

registerBlock('testimonial', ({ quote, author }) => <blockquote>{quote} — {author}</blockquote>);
```

- **Hooks on a block type:**
  - `store()` prepares data before it is saved (the image blocks register uploads there);
  - `permission()` restricts who may add or change the block.
- **Rendering:** blocks of an unknown type, or that fail to render, are skipped.
- **Other models:** any model can have block areas with `PnShop\Cms\Concerns\HasContentBlocks`. Render them with `PnShop\Cms\ContentRenderer` and edit them with `PnShop\Cms\Filament\ContentEditor::make('area')`.
