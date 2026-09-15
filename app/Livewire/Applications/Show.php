<?php

namespace App\Livewire\Applications;

use App\Models\Application;
use App\Services\ReelCredentialScope;
use ArtisanBuild\BuiltForCloud\Actions\ListCredentials;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\Actions\RotateCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\Contracts\IdentityContext;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialSummary;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\RotateOptions;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Application settings')]
class Show extends Component
{
    public ApplicationForm $form;

    #[Locked]
    public string $applicationId;

    public function mount(Application $application): void
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);
        $this->applicationId = $application->public_id;
        $this->form->fillFrom($application);
    }

    public function render(): View
    {
        $enrollment = session('enrollment');
        $enrollmentCode = null;
        $enrollmentExpired = false;

        if (is_array($enrollment)
            && ($enrollment['application_id'] ?? null) === $this->applicationId
            && is_string($enrollment['code'] ?? null)
            && is_int($enrollment['expires_at'] ?? null)
        ) {
            session()->forget('enrollment');

            if ($enrollment['expires_at'] > now()->getTimestamp()) {
                $enrollmentCode = $enrollment['code'];
            } else {
                $enrollmentExpired = true;
            }
        }

        return view('livewire.applications.show', [
            'enrollmentCode' => $enrollmentCode,
            'enrollmentExpired' => $enrollmentExpired,
        ]);
    }

    #[Computed]
    public function application(): Application
    {
        return Application::query()
            ->where('public_id', $this->applicationId)
            ->firstOrFail();
    }

    /** @return list<CredentialSummary> */
    #[Computed]
    public function credentials(): array
    {
        $scope = ReelCredentialScope::for($this->application());

        return array_values(array_filter(
            app(ListCredentials::class)($scope->subject),
            static fn (CredentialSummary $credential): bool => $credential->kind === CredentialKind::Asymmetric
                && $credential->purpose === CredentialPurpose::Signing
                && $credential->subjectType === $scope->subject->type
                && $credential->subjectRef === $scope->subject->ref,
        ));
    }

    public function updateApplication(): void
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);
        $this->application()->update($this->form->validatedData());

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Application settings updated.'));
    }

    public function toggleIngest(): void
    {
        abort_unless(app(IdentityContext::class)->canUseProduct(), 403);
        $application = $this->application();
        $application->update([
            'ingest_enabled' => ! $application->ingest_enabled,
        ]);

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Ingest status updated.'));
    }

    public function issueCredential(MintCredential $mint, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);
        $application = $this->application();
        $scope = ReelCredentialScope::for($application);
        $enrollment = $mint($scope->subject, new MintOptions(
            kind: CredentialKind::Asymmetric,
            purpose: CredentialPurpose::Signing,
            codeTtlSeconds: 900,
            boundScope: $scope,
        ), AuditActor::boundUser($identity->actorId()));

        $this->flashEnrollment($application, $enrollment->secret?->reveal());
    }

    public function rotateCredential(string $credentialId, RotateCredential $rotate, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);
        $application = $this->application();
        abort_unless(collect($this->credentials())->contains(
            static fn (CredentialSummary $credential): bool => $credential->id === $credentialId,
        ), 404);
        $result = $rotate(
            $credentialId,
            new RotateOptions(codeTtlSeconds: 900),
            AuditActor::boundUser($identity->actorId()),
        );

        abort_unless($result !== null, 404);
        $this->flashEnrollment($application, $result->mint->secret?->reveal());
    }

    private function flashEnrollment(Application $application, ?string $code): void
    {
        abort_unless($code !== null, 409);

        session()->flash('enrollment', [
            'application_id' => $application->public_id,
            'code' => $code,
            'expires_at' => now()->addMinutes(15)->getTimestamp(),
        ]);
        $this->redirectRoute('admin.applications.show', ['application' => $application]);
    }

    public function revokeCredential(string $credentialId, RevokeCredential $revoke, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);
        abort_unless(collect($this->credentials())->contains(
            static fn (CredentialSummary $credential): bool => $credential->id === $credentialId,
        ), 404);
        $scope = ReelCredentialScope::for($this->application());
        $outcome = $revoke(
            $credentialId,
            AuditActor::boundUser($identity->actorId()),
            $scope->subject,
        );
        abort_if($outcome === RevokeOutcome::NotFound, 404);

        unset($this->application, $this->credentials);
        Flux::toast(variant: 'success', text: __('Credential revoked.'));
    }
}
