<?php

namespace App\Livewire\Applications;

use App\Livewire\Applications\Concerns\EnsuresAdministrator;
use App\Models\Application;
use App\Services\EnrollmentCodeIssuer;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Create application')]
class Create extends Component
{
    use EnsuresAdministrator;

    public ApplicationForm $form;

    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    public function save(EnrollmentCodeIssuer $issuer): void
    {
        $this->ensureAdministrator();

        [$application, $enrollment] = DB::transaction(function () use ($issuer): array {
            $application = Application::query()->create($this->form->validatedData());

            return [$application, $issuer->issue($application)];
        });

        session()->flash('enrollment', [
            'application_id' => $application->public_id,
            'code' => $enrollment->code,
            'expires_at' => $enrollment->expiresAt,
        ]);
        $this->redirectRoute('admin.applications.show', ['application' => $application]);
    }
}
