<?php
declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfExportService
{
    public static function buildDompdf(string $html, string $paper = 'A4', string $orientation = 'portrait'): Dompdf
    {
        $projectRoot = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
        $publicDir = $projectRoot . '/public';

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', array_filter([$projectRoot, $publicDir]));

        $html = self::inlineLocalImages($html, $projectRoot);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);

        if ($paper === 'thermal80') {
            $dompdf->setPaper([0, 0, 226.77, 850], 'portrait');
        } elseif ($paper === 'thermal58') {
            $dompdf->setPaper([0, 0, 164.4, 850], 'portrait');
        } else {
            $dompdf->setPaper($paper, $orientation);
        }

        $dompdf->render();
        return $dompdf;
    }

    public static function renderPdfBytes(string $html, string $paper = 'A4', string $orientation = 'portrait'): string
    {
        $dompdf = self::buildDompdf($html, $paper, $orientation);
        return $dompdf->output();
    }

    public static function streamPdf(string $html, string $filename, string $paper = 'A4', string $orientation = 'portrait'): void
    {
        $dompdf = self::buildDompdf($html, $paper, $orientation);
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    }

    /**
     * Converts relative/web image paths to Base64 data URIs so Dompdf never drops them.
     */
    public static function inlineLocalImages(string $html, string $projectRoot): string
    {
        return preg_replace_callback('/<img\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*?)>/i', function ($matches) use ($projectRoot) {
            $prefix = $matches[1];
            $quote = $matches[2];
            $src = $matches[3];
            $suffix = $matches[4];

            if (str_starts_with($src, 'data:') || str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                return $matches[0];
            }

            $clean = ltrim($src, '/');
            if (str_starts_with($clean, 'doodhbee/')) {
                $clean = substr($clean, 9);
            }

            $candidates = [
                $projectRoot . '/' . $clean,
                $projectRoot . '/public/' . $clean,
            ];

            foreach ($candidates as $cand) {
                if (file_exists($cand) && is_file($cand)) {
                    $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'png' => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'svg' => 'image/svg+xml',
                        'webp' => 'image/webp',
                        'gif' => 'image/gif',
                        default => 'application/octet-stream',
                    };
                    $data = base64_encode(file_get_contents($cand));
                    return "<img{$prefix}src={$quote}data:{$mime};base64,{$data}{$quote}{$suffix}>";
                }
            }

            return $matches[0];
        }, $html) ?? $html;
    }
}
