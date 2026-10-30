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
        ->and($links)->toHaveCount(2);

    $destinations = [
        route('admin.applications.index'),
        route('sessions.index'),
    ];

    foreach ($destinations as $index => $destination) {
        $link = $links->item($index);
        expect($link)->toBeInstanceOf(DOMElement::class);
        expect($link->getAttribute('href'))->toBe($destination)
            ->and($link->hasAttribute('wire:navigate'))->toBeTrue();
    }

    $current = $xpath->query($navigation.'/a[@aria-current="page"]');
    expect($current)->toHaveCount(1)
        ->and($current->item(0)->attributes->getNamedItem('href')->nodeValue)->toBe(route($currentRoute));

    $main = $xpath->query('//main/*[@data-testid="bfc-main"]');
    expect($main)->toHaveCount(1)
        ->and($main->item(0)->childNodes->length)->toBeGreaterThan(0);

    if ($page === 'bfc.dashboard') {
        expect($xpath->query('//main//*[@data-test="operational-health-button"]'))->toHaveCount(1)
            ->and($main->item(0)->textContent)->toContain($application->name);
    } elseif ($page === 'admin.applications.create') {
        expect($xpath->query('//main//form//*[@*[name()="wire:model"]="form.name"]'))->toHaveCount(1);
    } else {
        expect($main->item(0)->textContent)->toContain($application->name);
    }

    expect($xpath->query('//head/link[@href="'.asset('build/assets/app.css').'"]'))->toHaveCount(1);
})->with([
    'dashboard' => ['bfc.dashboard', 'admin.applications.index'],
    'sessions' => ['sessions.index', 'sessions.index'],
    'application sessions' => ['applications.sessions.index', 'sessions.index'],
    'replay detail' => ['sessions.show', 'sessions.index'],
    'applications' => ['admin.applications.index', 'admin.applications.index'],
    'create application' => ['admin.applications.create', 'admin.applications.index'],
    'application settings' => ['admin.applications.show', 'admin.applications.index'],
]);

it('keeps the sessions table legible on narrow screens', function (): void {
    $this->actingAs(User::factory()->create());

    $emptyResponse = $this->get(route('sessions.index'))->assertOk();
    $emptyDocument = new DOMDocument;
    @$emptyDocument->loadHTML($emptyResponse->getContent());
    $emptyXPath = new DOMXPath($emptyDocument);

    expect($emptyXPath->query('//*[@data-testid="session-list"]//tbody/tr/td[@colspan="6"]'))->toHaveCount(1);

    $application = Application::factory()->create();
    makeMcpRecordingSession($application, activeReelCredential($application));

    $response = $this->get(route('sessions.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $class = static fn (string $name): string => 'contains(concat(" ", normalize-space(@class), " "), " '.$name.' ")';
    $sessionList = '//*[@data-testid="session-list"]';
    $responsiveTable = $sessionList.'/div['.$class('overflow-x-auto').']/table['
        .$class('w-full').' and '.$class('table-fixed').' and '
        .$class('sm:w-auto').' and '.$class('sm:min-w-full').' and '.$class('sm:table-auto').']';
    $secondaryPositions = 'position() >= 2 and position() <= 4';
    $secondaryHeaders = $responsiveTable.'/thead/tr/th['.$secondaryPositions.' and '.$class('hidden').' and '.$class('sm:table-cell').']';
    $sessionRow = $responsiveTable.'/tbody/tr[td][1]';
    $secondaryData = $sessionRow.'/td['.$secondaryPositions.' and '.$class('hidden').' and '.$class('sm:table-cell').']';
    $primaryPositions = 'position() = 1 or position() = 5 or position() = 6';
    $primaryHeaders = $responsiveTable.'/thead/tr/th[('.$primaryPositions.') and '.$class('px-2').' and '.$class('sm:px-4').']';
    $primaryData = $sessionRow.'/td[('.$primaryPositions.') and '.$class('px-2').' and '.$class('sm:px-4').']';
    $startedData = $sessionRow.'/td[1]['.$class('sm:whitespace-nowrap').']';

    expect($xpath->query($sessionList))->toHaveCount(1)
        ->and($xpath->query($responsiveTable))->toHaveCount(1)
        ->and($xpath->query($secondaryHeaders))->toHaveCount(3)
        ->and($xpath->query($secondaryData))->toHaveCount(3)
        ->and($xpath->query($primaryHeaders))->toHaveCount(3)
        ->and($xpath->query($primaryData))->toHaveCount(3)
        ->and($xpath->query($startedData))->toHaveCount(1);
});
