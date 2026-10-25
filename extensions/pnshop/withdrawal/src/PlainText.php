<?php

namespace PnShop\Plugins\Withdrawal;

/**
 * Customer-typed text for emails. Mail lines are rendered as Markdown, so typed text could
 * otherwise become links or formatting in an email the shop sends: this keeps it literal,
 * on one line.
 */
final class PlainText
{
    public static function of(?string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return (string) preg_replace('/([\\\\`*_\[\]()<>])/', '\\\\$1', $text);
    }
}
