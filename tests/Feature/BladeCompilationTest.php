<?php

use Illuminate\Support\Facades\Blade;

/**
 * Every Blade file shipped by the application (vendor excluded).
 *
 * @return list<string>
 */
function projectBladeFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

test('no view uses the removed inline @php() directive', function () {
    $offenders = [];

    foreach (projectBladeFiles() as $path) {
        $contents = (string) file_get_contents($path);

        // Laravel no longer supports `@php($x = 1)`. It compiles to a bare `<?php(`
        // and silently halts compilation of everything after it, so the rest of the
        // template is emitted as raw text. Always use the @php ... @endphp block form.
        if (preg_match('/@php\s*\(/', $contents) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], 'Inline @php() found; use @php ... @endphp instead.');
});

test('every view compiles to syntactically valid php', function () {
    $files = projectBladeFiles();

    expect($files)->not->toBeEmpty();

    $failures = [];

    foreach ($files as $path) {
        $compiled = Blade::compileString((string) file_get_contents($path));

        $temp = tempnam(sys_get_temp_dir(), 'blade_lint_').'.php';
        file_put_contents($temp, $compiled);

        exec('php -l '.escapeshellarg($temp).' 2>&1', $output, $status);
        unlink($temp);

        if ($status !== 0) {
            $relative = str_replace(base_path().'/', '', $path);
            $failures[] = $relative.': '.implode(' ', $output);
        }
    }

    expect($failures)->toBe([]);
});
