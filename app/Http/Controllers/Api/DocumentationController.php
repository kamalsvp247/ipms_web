<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentationController extends Controller
{
    protected function docsPath(): string
    {
        return base_path('ipms_java');
    }

    /**
     * Resolve safe absolute path for a doc file (must be .md files in ipms_java).
     */
    protected function resolveDocFile(string $filename): ?string
    {
        // Only allow markdown files
        if (! str_ends_with($filename, '.md')) {
            return null;
        }

        $docsDir = $this->docsPath();
        if (! is_dir($docsDir)) {
            return null;
        }

        $base = realpath($docsDir);
        if ($base === false) {
            return null;
        }

        $name = basename($filename);
        if ($name === '' || $name !== $filename) {
            return null;
        }

        $path = $base.DIRECTORY_SEPARATOR.$name;
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $base)) {
            return null;
        }

        return $real;
    }

    /**
     * GET /api/documentation — list available documentation files.
     */
    public function index(): JsonResponse
    {
        $docsDir = $this->docsPath();
        if (! is_dir($docsDir)) {
            return response()->json(['files' => []]);
        }

        $files = [];
        foreach (File::files($docsDir) as $file) {
            if ($file->getExtension() === 'md') {
                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified_at' => date('c', $file->getMTime()),
                ];
            }
        }
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json(['files' => $files]);
    }

    /**
     * GET /api/documentation/{filename} — get doc file content.
     */
    public function show(string $filename): JsonResponse
    {
        $path = $this->resolveDocFile($filename);
        if ($path === null || ! is_file($path)) {
            return response()->json(['error' => 'Documentation file not found.'], 404);
        }

        return response()->json([
            'name' => basename($path),
            'content' => File::get($path),
        ]);
    }

    /**
     * GET /api/documentation/{filename}/download — download doc file.
     */
    public function download(string $filename): StreamedResponse|JsonResponse
    {
        $path = $this->resolveDocFile($filename);
        if ($path === null || ! is_file($path)) {
            return response()->json(['error' => 'Documentation file not found.'], 404);
        }

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'r');
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, basename($path), [
            'Content-Type' => 'text/markdown; charset=utf-8',
        ]);
    }
}
