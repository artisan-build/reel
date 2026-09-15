<?php

use Illuminate\Support\Str;

test('renders the configured Reel identity on the package landing page', function (): void {
    $slug = 'reel-test-'.Str::lower(Str::random(8));
    $name = 'Reel '.Str::uuid()->toString();
    $description = "Session replay for {$slug}.";

    config()->set('built-for-cloud.manifest', [
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'icon' => "https://assets.example.test/{$slug}.svg",
        'product_url' => "https://scalpels.app/products/{$slug}",
    ]);

    $response = $this->get(route('bfc.landing'));

    $response->assertOk()
        ->assertSeeHtml('data-testid="landing"')
        ->assertSeeHtml('data-app-slug="'.$slug.'"')
        ->assertSee($name)
        ->assertSee($description);
});
