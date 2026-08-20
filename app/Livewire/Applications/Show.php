<?php

namespace App\Livewire\Applications;

use App\Enums\CredentialStatus;
use App\Livewire\Applications\Concerns\EnsuresAdministrator;
use App\Models\Application;
use App\Services\EnrollmentCodeIssuer;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Application settings')]
class Show extends Component
{
    use EnsuresAdministrator;

    public ApplicationForm $form;

    #[Locked]
    public string $applicationId;

    public function mount(Application $application): void
    {
        $this->ensureAdministrator();
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
            ->with('credentials')
            ->firstOrFail();
    }

    public function updateApplication(): void
    {
        $this->ensureAdministrator();
        $this->application()->update($this->form->validatedData());

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Application settings updated.'));
    }

    public function toggleIngest(): void
    {
        $this->ensureAdministrator();
        $application = $this->application();
        $application->update([
            'ingest_enabled' => ! $application->ingest_enabled,
        ]);

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Ingest status updated.'));
    }

    public function rotateCredential(EnrollmentCodeIssuer $issuer): void
    {
        $this->ensureAdministrator();
        $application = $this->application();
        $enrollment = $issuer->issue($application);

        session()->flash('enrollment', [
            'application_id' => $application->public_id,
            'code' => $enrollment->code,
            'expires_at' => $enrollment->expiresAt,
        ]);
        $this->redirectRoute('admin.applications.show', ['application' => $application]);
    }

    public function revokeCredential(int $credentialId): void
    {
        $this->ensureAdministrator();

        $credential = $this->application()->credentials()->findOrFail($credentialId);
        $credential->update([
            'status' => CredentialStatus::Revoked,
            'revoked_at' => now(),
        ]);

        unset($this->application);
        Flux::toast(variant: 'success', text: __('Credential revoked.'));
    }
}
