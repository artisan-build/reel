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
use ArtisanBuild\BuiltForCloud\Exceptions\RotationRefused;
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

    private ?string $enrollmentCode = null;

    public function mount(Application $application): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);
        $this->applicationId = $application->public_id;
        $this->form->fillFrom($application);
    }

    public function render(): View
    {
        return view('livewire.applications.show', [
            'enrollmentCode' => $this->enrollmentCode,
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
            resolve(ListCredentials::class)($scope->subject),
            static fn (CredentialSummary $credential): bool => $credential->kind === CredentialKind::Asymmetric
                && $credential->purpose === CredentialPurpose::Signing
                && $credential->subjectType === $scope->subject->type
                && $credential->subjectRef === $scope->subject->ref,
        ));
    }

    public function updateApplication(): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);
        $this->application()->update($this->form->validatedData());

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Application settings updated.'));
    }

    public function toggleIngest(): void
    {
        abort_unless(resolve(IdentityContext::class)->canUseProduct(), 403);
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

        $this->revealEnrollment($enrollment->secret?->reveal());
        unset($this->credentials);
    }

    public function rotateCredential(string $credentialId, RotateCredential $rotate, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);
        $credential = $this->credential($credentialId);
        abort_unless($credential->status === 'active' && $credential->rotatedAt === null, 409);
        $result = $rotate(
            $credentialId,
            new RotateOptions(codeTtlSeconds: 900),
            AuditActor::boundUser($identity->actorId()),
        );

        abort_unless($result !== null, 404);
        $this->revealEnrollment($result->mint->secret?->reveal());
        unset($this->credentials);
    }

    public function reissuePendingCredential(string $predecessorId, RotateCredential $rotate, IdentityContext $identity): void
    {
        abort_unless($identity->canUseProduct(), 403);
        $credential = $this->credential($predecessorId);
        abort_unless($credential->status === 'active' && $credential->rotatedAt !== null, 409);
        try {
            $result = $rotate(
                $predecessorId,
                new RotateOptions(codeTtlSeconds: 900, reissuePendingDelivery: true),
                AuditActor::boundUser($identity->actorId()),
            );
        } catch (RotationRefused) {
            abort(409);
        }

        abort_unless($result !== null, 404);
        $this->revealEnrollment($result->mint->secret?->reveal());
        unset($this->credentials);
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

    private function credential(string $credentialId): CredentialSummary
    {
        $credential = collect($this->credentials())->first(
            static fn (CredentialSummary $credential): bool => $credential->id === $credentialId,
        );
        abort_unless($credential instanceof CredentialSummary, 404);

        return $credential;
    }

    private function revealEnrollment(?string $code): void
    {
        abort_unless($code !== null, 409);
        $this->enrollmentCode = $code;
    }
}
