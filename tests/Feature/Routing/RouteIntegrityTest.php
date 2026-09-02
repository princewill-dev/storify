<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

test('every controller route resolves to a callable action', function () {
    $missingActions = collect(RouteFacade::getRoutes()->getRoutes())
        ->map(fn (Route $route): string => $route->getActionName())
        ->filter(fn (string $action): bool => str_contains($action, '@'))
        ->reject(function (string $action): bool {
            [$controller, $method] = explode('@', $action, 2);

            return class_exists($controller) && method_exists($controller, $method);
        })
        ->unique()
        ->values()
        ->all();

    expect($missingActions)->toBe([]);
});

test('registered route names are unique', function () {
    $duplicates = collect(RouteFacade::getRoutes()->getRoutes())
        ->map(fn (Route $route): ?string => $route->getName())
        ->filter()
        ->countBy()
        ->filter(fn (int $count): bool => $count > 1)
        ->keys()
        ->values()
        ->all();

    expect($duplicates)->toBe([]);
});

test('web ajax routes are not duplicated below the api v1 prefix', function () {
    $malformedUris = collect(RouteFacade::getRoutes()->getRoutes())
        ->map(fn (Route $route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/api/'))
        ->values()
        ->all();

    expect($malformedUris)->toBe([]);
});
