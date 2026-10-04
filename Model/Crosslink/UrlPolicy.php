<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\Crosslink;

class UrlPolicy
{
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function isAllowed(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return false;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $matches)) {
            return in_array(strtolower($matches[1]), self::ALLOWED_SCHEMES, true);
        }

        return true;
    }
}
