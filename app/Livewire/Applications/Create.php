<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\MintOptions;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create application')]
class Create extends Component
{
    public ApplicationForm $form;

    public function mount(): void
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);
    }

    public function save(MintCredential $mint, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);

        [$application, $enrollment] = DB::transaction(function () use ($mint, $identity): array {
            $application = Application::query()->create($this->form->validatedData());
            $scope = ReelCredentialScope::for($application);
            $result = $mint($scope->subject, new MintOptions(
                kind: CredentialKind::Asymmetric,
                purpose: CredentialPurpose::Signing,
                codeTtlSeconds: 900,
                boundScope: $scope,
            ), AuditActor::boundUser($identity->actorId()));

            return [$application, $result];
        });
        abort_unless($enrollment->secret !== null, 500);

        session()->flash('enrollment', [
            'application_id' => $application->public_id,
            'code' => $enrollment->secret->reveal(),
            'expires_at' => now()->addMinutes(15)->getTimestamp(),
        ]);
        $this->redirectRoute('admin.applications.show', ['application' => $application]);
    }
}
