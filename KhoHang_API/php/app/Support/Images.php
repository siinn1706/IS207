<?php

namespace App\Support;

/** GD helpers replacing Pillow usage in user_routes.upload_avatar. */
class Images
{
    /** Convert to RGB on white (alpha flattened), fit in $max px, write WebP (quality 85). */
    public static function toWebpAvatar(string $bytes, string $dest, int $max = 800, int $quality = 85): void
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new \RuntimeException('cannot identify image file');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $nw = $w;
        $nh = $h;
        if ($w > $max || $h > $max) {
            $ratio = min($max / $w, $max / $h);
            $nw = (int) ($w * $ratio);
            $nh = (int) ($h * $ratio);
        }
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (! imagewebp($dst, $dest, $quality)) {
            throw new \RuntimeException('WebP encoding failed');
        }
    }
}
