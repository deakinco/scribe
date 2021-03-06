<?php

namespace Knuckles\Scribe\Writing;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Knuckles\Pastel\Pastel;
use Knuckles\Scribe\Tools\ConsoleOutputUtils;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Knuckles\Scribe\Tools\Utils;

class Writer
{
    /**
     * @var DocumentationConfig
     */
    private $config;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var bool
     */
    private $forceIt;

    /**
     * @var bool
     */
    private $shouldGeneratePostmanCollection = true;

    /**
     * @var Pastel
     */
    private $pastel;

    /**
     * @var bool
     */
    private $isStatic;

    /**
     * @var string
     */
    private $sourceOutputPath;

    /**
     * @var string
     */
    private $outputPath;

    /**
     * @var string
     */
    private $fileModificationTimesFile;

    /**
     * @var array
     */
    private $lastTimesWeModifiedTheseFiles;

    public function __construct(DocumentationConfig $config = null, bool $forceIt = false)
    {
        // If no config is injected, pull from global
        $this->config = $config ?: new DocumentationConfig(config('scribe'));
        $this->baseUrl = $this->config->get('base_url') ?? config('app.url');
        $this->forceIt = $forceIt;
        $this->shouldGeneratePostmanCollection = $this->config->get('postman.enabled', false);
        $this->pastel = new Pastel();
        $this->isStatic = $this->config->get('type') === 'static';
        $this->sourceOutputPath = 'resources/docs';
        $this->outputPath = $this->isStatic ? 'public/docs' : 'resources/views/scribe';
        $this->fileModificationTimesFile = $this->sourceOutputPath . '/.filemtimes';
        $this->lastTimesWeModifiedTheseFiles = [];
    }

    public function writeDocs(Collection $routes)
    {
        // The source Markdown files always go in resources/docs.
        // The static assets (js/, css/, and images/) always go in public/docs/.
        // For 'static' docs, the output files (index.html, collection.json) go in public/docs/.
        // For 'laravel' docs, the output files (index.blade.php, collection.json)
        // go in resources/views/scribe/ and storage/app/scribe/ respectively.

        $this->writeMarkdownAndSourceFiles($routes);

        $this->writeHtmlDocs();

        $this->writePostmanCollection($routes);
    }

    /**
     * @param Collection $parsedRoutes
     *
     * @return void
     */
    public function writeMarkdownAndSourceFiles(Collection $parsedRoutes)
    {
        $settings = [
            'languages' => $this->config->get('example_languages'),
            'logo' => $this->config->get('logo'),
            'title' => config('app.name', '') . ' API Documentation',
        ];

        ConsoleOutputUtils::info('Writing source Markdown files to: ' . $this->sourceOutputPath);

        if (!is_dir($this->sourceOutputPath)) {
            mkdir($this->sourceOutputPath, 0777, true);
        }

        $this->writeIndexMarkdownFile($settings);
        $this->writeAuthMarkdownFile();
        $this->writeRoutesMarkdownFile($parsedRoutes, $settings);

        ConsoleOutputUtils::info('Wrote source Markdown files to: ' . $this->sourceOutputPath);
    }

    public function generateMarkdownOutputForEachRoute(Collection $parsedRoutes, array $settings): Collection
    {
        $routesWithOutput = $parsedRoutes->map(function (Collection $routeGroup) use ($settings) {
            return $routeGroup->map(function (array $route) use ($settings) {
                if (count($route['cleanBodyParameters']) && !isset($route['headers']['Content-Type'])) {
                    // Set content type if the user forgot to set it
                    $route['headers']['Content-Type'] = 'application/json';
                }

                $hasRequestOptions = !empty($route['headers'])
                    || !empty($route['cleanQueryParameters'])
                    || !empty($route['cleanBodyParameters']);
                $route['output'] = (string)view('scribe::partials.route')
                    ->with('hasRequestOptions', $hasRequestOptions)
                    ->with('route', $route)
                    ->with('settings', $settings)
                    ->with('baseUrl', $this->baseUrl)
                    ->render();

                return $route;
            });
        });

        return $routesWithOutput;
    }

    protected function writePostmanCollection(Collection $parsedRoutes): void
    {
        if ($this->shouldGeneratePostmanCollection) {
            ConsoleOutputUtils::info('Generating Postman collection');

            $collection = $this->generatePostmanCollection($parsedRoutes);
            if ($this->isStatic) {
                $collectionPath = "{$this->outputPath}/collection.json";
                file_put_contents($collectionPath, $collection);
            } else {
                Storage::disk('local')->put('scribe/collection.json', $collection);
                $collectionPath = 'storage/app/scribe/collection.json';
            }

            ConsoleOutputUtils::success("Wrote Postman collection to: {$collectionPath}");
        }
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    public function generatePostmanCollection(Collection $routes)
    {
        /** @var PostmanCollectionWriter $writer */
        $writer = app()->makeWith(
            PostmanCollectionWriter::class,
            ['routeGroups' => $routes, 'baseUrl' => $this->baseUrl]
        );

        return $writer->getCollection();
    }

    protected function performFinalTasksForLaravelType(): void
    {
        // Make output a Blade view
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath);
        }

        rename("public/docs/index.html", "$this->outputPath/index.blade.php");
        // Move assets from public/docs to public/vendor/scribe
        Utils::deleteDirectoryAndContents("public/vendor/scribe/", getcwd());
        rename("public/docs/", "public/vendor/scribe/");

        $contents = file_get_contents("$this->outputPath/index.blade.php");

        // Rewrite links to go through Laravel
        $contents = preg_replace('#href="css/(.+?)"#', 'href="{{ asset("vendor/scribe/css/$1") }}"', $contents);
        $contents = preg_replace('#src="(js|images)/(.+?)"#', 'src="{{ asset("vendor/scribe/$1/$2") }}"', $contents);
        $contents = str_replace('href="./collection.json"', 'href="{{ route("scribe.json") }}"', $contents);

        file_put_contents("$this->outputPath/index.blade.php", $contents);
    }

    public function writeHtmlDocs(): void
    {
        ConsoleOutputUtils::info('Generating API HTML code');

        $this->pastel->generate($this->sourceOutputPath . '/index.md', 'public/docs');

        if (!$this->isStatic) {
            $this->performFinalTasksForLaravelType();
        }

        ConsoleOutputUtils::success("Wrote HTML documentation to: {$this->outputPath}");
    }

    protected function writeIndexMarkdownFile(array $settings): void
    {
        $frontmatter = view('scribe::partials.frontmatter')
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection)
            // This path is wrong for laravel type but will be replaced in post
            ->with('postmanCollectionLink', './collection.json')
            ->with('outputPath', 'docs')
            ->with('settings', $settings);
        $indexFile = $this->sourceOutputPath . '/index.md';

        $introText = $this->config->get('intro_text', '');
        $introMarkdown = view('scribe::index')
            ->with('frontmatter', $frontmatter)
            ->with('text', $introText);
        $this->writeFile($indexFile, $introMarkdown);
    }

    protected function writeAuthMarkdownFile(): void
    {
        $isAuthed = $this->config->get('auth.enabled', false);
        $text = '';

        if ($isAuthed) {
            $strategy = $this->config->get('auth.in');
            $parameterName = $this->config->get('auth.name');
            $text = Arr::random([
                "This API is authenticated by sending ",
                "To authenticate requests, include ",
                "Authenticate requests to this API's endpoints by sending ",
            ]);
            switch ($strategy) {
                case 'query':
                    $text .= "a query parameter **`$parameterName`** in the request.";
                    break;
                case 'body':
                    $text .= "a parameter **`$parameterName`** in the body of the request.";
                    break;
                case 'query_or_body':
                    $text .= "a parameter **`$parameterName`** either in the query string or in the request body.";
                    break;
                case 'bearer':
                    $text .= "an **`Authorization`** header with the value **`\"Bearer {your-token}\"`**.";
                    break;
                case 'basic':
                    $text .= "an **`Authorization`** header in the form **`\"Basic {credentials}\"`**. The value of `{credentials}` should be your username/id and your password, joined with a colon (:), and then base64-encoded.";
                    break;
                case 'header':
                    $text .= "a **`$parameterName`** header with the value **`\"{your-token}\"`**.";
                    break;
            }
            $extraInfo = $this->config->get('auth.extra_info', '');
            $text .= " $extraInfo";
        }

        $authMarkdown = view('scribe::authentication', ['isAuthed' => $isAuthed, 'text' => $text]);
        $this->writeFile($this->sourceOutputPath . '/authentication.md', $authMarkdown);
    }

    protected function writeRoutesMarkdownFile(Collection $parsedRoutes, array $settings): void
    {
        if (!is_dir($this->sourceOutputPath . '/groups')) {
            mkdir($this->sourceOutputPath . '/groups', 0777, true);
        }

        if (file_exists($this->fileModificationTimesFile)) {
            $this->lastTimesWeModifiedTheseFiles = explode("\n", file_get_contents($this->fileModificationTimesFile));
            array_shift($this->lastTimesWeModifiedTheseFiles);
            array_shift($this->lastTimesWeModifiedTheseFiles);
            $this->lastTimesWeModifiedTheseFiles = collect($this->lastTimesWeModifiedTheseFiles)
                ->mapWithKeys(function ($line) {
                    [$filePath, $mtime] = explode("=", $line);
                    return [$filePath => $mtime];
                })->toArray();
        }

        // Generate Markdown for each route. Not using a Blade component bc of some complex logic
        $parsedRoutesWithOutput = $this->generateMarkdownOutputForEachRoute($parsedRoutes, $settings);
        $parsedRoutesWithOutput->each(function ($routesInGroup, $groupName) {
            $groupId = Str::slug($groupName);
            $routeGroupMarkdownFile = $this->sourceOutputPath . "/groups/$groupId.md";

            if ($this->hasFileBeenModified($routeGroupMarkdownFile)) {
                if ($this->forceIt) {
                    ConsoleOutputUtils::warn("Discarded manual changes for file $routeGroupMarkdownFile");
                } else {
                    ConsoleOutputUtils::warn("Skipping modified file $routeGroupMarkdownFile");
                    return;
                }
            }

            $groupDescription = Arr::first($routesInGroup, function ($route) {
                    return $route['metadata']['groupDescription'] !== '';
                })['metadata']['groupDescription'] ?? '';
            $groupMarkdown = view('scribe::partials.group')
                ->with('groupName', $groupName)
                ->with('groupDescription', $groupDescription)
                ->with('routes', $routesInGroup);

            $this->writeFile($routeGroupMarkdownFile, $groupMarkdown);
        });

        $this->writeModificationTimesFile();

    }

    /**
     */
    protected function writeFile(string $filePath, $markdown): void
    {
        file_put_contents($filePath, $markdown);
        $this->lastTimesWeModifiedTheseFiles[$filePath] = time();
    }

    /**
     */
    protected function writeModificationTimesFile(): void
    {
        $content = "# GENERATED. YOU SHOULDN'T MODIFY OR DELETE THIS FILE.\n";
        $content .= "# Scribe uses this file to know when you change something manually in your docs.\n";
        $content .= collect($this->lastTimesWeModifiedTheseFiles)
            ->map(function ($mtime, $filePath) {
                return "$filePath=$mtime";
            })->implode("\n");
        file_put_contents($this->fileModificationTimesFile, $content);
    }

    /**
     */
    protected function hasFileBeenModified(string $filePath): bool
    {
        $oldFileModificationTime = $this->lastTimesWeModifiedTheseFiles[$filePath] ?? null;

        if ($oldFileModificationTime) {
            $latestFileModifiedTime = filemtime($filePath);
            $wasFileModifiedManually = $latestFileModifiedTime > (int)$oldFileModificationTime;

            return $wasFileModifiedManually;
        }

        return false;
    }

}
