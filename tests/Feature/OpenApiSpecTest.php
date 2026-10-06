<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `public/openapi.yaml` is what third parties generate clients from, so a route
 * added without a line in it is an API nobody can discover, and a line left behind
 * after a route goes is a client that calls something that is not there.
 *
 * This checks operations (method and path) only. It does not check schemas.
 */
class OpenApiSpecTest extends TestCase
{
    #[Test]
    public function every_public_api_route_is_in_the_spec_and_every_operation_has_a_route(): void
    {
        $routes = $this->publicRoutes();
        $spec = $this->specOperations();

        $this->assertNotEmpty($routes, 'No public API routes found: the filter has stopped matching.');
        $this->assertNotEmpty($spec, 'No operations parsed from public/openapi.yaml.');

        $this->assertSame(
            [],
            array_values(array_diff($routes, $spec)),
            'Routes missing from public/openapi.yaml.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($spec, $routes)),
            'Operations in public/openapi.yaml with no route.',
        );
    }

    /** @return array<int, string> e.g. "GET /v1/issues/{}" */
    private function publicRoutes(): array
    {
        $operations = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') && ! str_starts_with($uri, 'api/ingest/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = $method.' '.$this->normalise(substr($uri, strlen('api')));
            }
        }

        $operations = array_unique($operations);
        sort($operations);

        return $operations;
    }

    /**
     * A line reader rather than a YAML parser, because symfony/yaml is not installed.
     * It assumes the file's layout: `paths:` at the top level, path keys indented two
     * spaces and unquoted, methods four. Reformat the spec and this finds fewer
     * operations, and the assertions above fail rather than pass.
     *
     * @return array<int, string>
     */
    private function specOperations(): array
    {
        $operations = [];
        $path = null;
        $inPaths = false;

        foreach (file(public_path('openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === 'paths:') {
                $inPaths = true;

                continue;
            }

            // The next top-level key ends the paths block.
            if ($inPaths && preg_match('/^\S/', $line)) {
                break;
            }

            if (! $inPaths) {
                continue;
            }

            if (preg_match('/^  (\/\S+):$/', $line, $m)) {
                $path = $this->normalise($m[1]);
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):$/', $line, $m)) {
                $operations[] = strtoupper($m[1]).' '.$path;
            }
        }

        $operations = array_unique($operations);
        sort($operations);

        return $operations;
    }

    /** Parameter names differ between the router and the spec; only their position matters. */
    private function normalise(string $path): string
    {
        return '/'.trim(preg_replace('/\{[^}]+\}/', '{}', $path), '/');
    }
}
