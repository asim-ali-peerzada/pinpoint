<?php

namespace AsimAli\Pinpoint\Internal;

/**
 * @internal Resolves editor URI schemes (VS Code, PhpStorm, Cursor, etc.) for clickable terminal links.
 */
class EditorLink
{
    public static function scheme(string $file, int $line): string
    {
        $editor = config('pinpoint.editor', 'vscode');
        $absolutePath = self::absolutePath($file);

        return match ($editor) {
            'phpstorm' => sprintf('phpstorm://open?file=%s&line=%d', rawurlencode($absolutePath), $line),
            // VS Code-compatible scheme (also registered by Cursor,
            // Windsurf/Devin Desktop — the URI handler is the editor's).
            // Canonical form is EXACTLY one slash between "file" and the
            // path: vscode://file{path}:{line}. A double slash (vscode://file//mnt/...)
            // makes the URL parser see an empty path, handlers misbehave
            // (wrong/recent-focus file opens instead of the target).
            default => sprintf('%s://file/%s:%d', $editor, ltrim(str_replace('%2F', '/', rawurlencode($absolutePath)), '/'), $line),
        };
    }

    public static function absolutePath(string $file): string
    {
        // Caller paths from Caller::capture() are stored workspace-relative
        // (base_path()-stripped), but URI handlers need absolute paths —
        // a relative vscode://file path makes VS Code fall back to search.
        if (str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $file)) {
            return $file;
        }

        $base = base_path();

        return str_starts_with($file, $base) ? $file : $base.DIRECTORY_SEPARATOR.$file;
    }

    public static function relativeCaller(string $file): string
    {
        $base = base_path();

        if (str_starts_with($file, $base)) {
            return ltrim(substr($file, strlen($base)), '/\\');
        }

        return $file;
    }
}
