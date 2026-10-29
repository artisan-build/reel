<?php

declare(strict_types=1);

use App\Models\Application;
use Tests\Support\User;

it('places Reel navigation in the package sidebar and page content in main', function (string $page, string $currentRoute): void {
    $application = Application::factory()->create(['name' => 'Sidebar application '.fake()->uuid()]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $parameters = [];

    if (in_array($page, ['admin.applications.show', 'applications.sessions.index', 'sessions.show'], true)) {
        $parameters['application'] = $application;
    }

    if ($page === 'sessions.show') {
        $credential = activeReelCredential($application);
        $parameters['recordingSession'] = makeMcpRecordingSession($application, $credential);
    }

    $response = $this->get(route($page, $parameters))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $navigation = '//*[@data-testid="bfc-sidebar-slot"]//nav[@data-testid="reel-navigation"]';
    $links = $xpath->query($navigation.'/a');

    expect($xpath->query($navigation))->toHaveCount(1)
        ->and($xpath->query('//*[@data-testid="reel-navigation"]'))->toHaveCount(1)
        ->and($links)->toHaveCount(6);

    $destinations = [
        route('bfc.dashboard'),
        route('sessions.index'),
        route('admin.applications.index'),
        route('bfc.ui.home'),
        'https://github.com/laravel/livewire-starter-kit',
        'https://laravel.com/docs/starter-kits#livewire',
    ];

    foreach ($destinations as $index => $destination) {
        $link = $links->item($index);
        expect($link)->toBeInstanceOf(DOMElement::class);
        expect($link->getAttribute('href'))->toBe($destination);

        if ($index < 4) {
            expect($link->hasAttribute('wire:navigate'))->toBeTrue();
        } else {
            expect($link->getAttribute('target'))->toBe('_blank');
        }
    }

    $current = $xpath->query($navigation.'/a[@aria-current="page"]');
    expect($current)->toHaveCount(1)
        ->and($current->item(0)->attributes->getNamedItem('href')->nodeValue)->toBe(route($currentRoute));

    $main = $xpath->query('//main/*[@data-testid="bfc-main"]');
    expect($main)->toHaveCount(1)
        ->and($main->item(0)->childNodes->length)->toBeGreaterThan(0);

    if ($page === 'bfc.dashboard') {
        expect($xpath->query('//main//*[@data-test="retention-diagnostics"]'))->toHaveCount(1);
    } elseif ($page === 'admin.applications.create') {
        expect($xpath->query('//main//form//*[@*[name()="wire:model"]="form.name"]'))->toHaveCount(1);
    } else {
        expect($main->item(0)->textContent)->toContain($application->name);
    }

    expect($xpath->query('//head/link[@href="'.asset('build/assets/app.css').'"]'))->toHaveCount(1);
})->with([
    'dashboard' => ['bfc.dashboard', 'bfc.dashboard'],
    'sessions' => ['sessions.index', 'sessions.index'],
    'application sessions' => ['applications.sessions.index', 'sessions.index'],
    'replay detail' => ['sessions.show', 'sessions.index'],
    'applications' => ['admin.applications.index', 'admin.applications.index'],
    'create application' => ['admin.applications.create', 'admin.applications.index'],
    'application settings' => ['admin.applications.show', 'admin.applications.index'],
]);

it('contains the sessions table within the page on narrow screens', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('sessions.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $class = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")';
    $sessionList = '//*[@data-testid="session-list" and '.$class('min-w-0').']';

    expect($xpath->query($sessionList))->toHaveCount(1)
        ->and($xpath->query($sessionList.'/div['.$class('overflow-x-auto').']/table['.$class('min-w-full').']'))->toHaveCount(1);
});
