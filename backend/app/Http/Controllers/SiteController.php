<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Serves the static website (index.html, dashboard.html, css/, js/, games/ …)
// from the folder above backend/, so one Laravel app runs the whole site.
class SiteController extends Controller
{
    private const TYPES = [
        'html' => 'text/html; charset=UTF-8', 'css' => 'text/css', 'js' => 'application/javascript',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'ico' => 'image/x-icon', 'xml' => 'application/xml', 'txt' => 'text/plain', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2',
    ];

    // The member pages are static HTML, so the policy can be strict: no inline scripts.
    private const CSP = "default-src 'self'; script-src 'self' https://www.youtube.com https://s.ytimg.com https://open.spotify.com; ".
        "style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data: https:; ".
        "frame-src 'self' https:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    public function __invoke(Request $request, string $path = '')
    {
        $path = trim($path, '/') ?: 'index.html';
        // Never expose the backend, hidden files or archives
        if (preg_match('#(^|/)\.|^(backend|backend-node|node_modules|vendor)(/|$)|\.(php|zip|env|md|log|lock|json)$#i', $path) && $path !== 'site.webmanifest') {
            abort(404);
        }
        $root = realpath(config('jovia.site_path'));
        $file = realpath($root.DIRECTORY_SEPARATOR.$path);
        if (! $file && ! str_contains(basename($path), '.')) {
            $file = realpath($root.DIRECTORY_SEPARATOR.$path.'.html'); // /signup → signup.html
        }
        if ($file && is_dir($file)) {
            $file = realpath($file.DIRECTORY_SEPARATOR.'index.html'); // /blog/ → blog/index.html
        }
        if (! $file || ! str_starts_with($file, $root) || ! is_file($file)) {
            abort(404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$ext])) {
            abort(404);
        }

        $response = new BinaryFileResponse($file, 200, ['Content-Type' => self::TYPES[$ext]]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($ext === 'html') {
            $response->headers->set('Content-Security-Policy', self::CSP);
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Cache-Control', 'no-cache');
        } else {
            $response->headers->set('Cache-Control', 'public, max-age=3600');
        }

        return $response;
    }
}
