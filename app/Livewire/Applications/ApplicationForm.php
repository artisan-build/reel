<?php

namespace App\Livewire\Applications;

use App\Enums\CaptureSeverity;
use App\Models\Application;
use App\Rules\Origin;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ApplicationForm extends Form
{
    public string $name = '';

    public string $allowedOrigins = '';

    public string $severity = CaptureSeverity::Inputs->value;

    public string $maskSelectors = '';

    public string $blockSelectors = '';

    public string $excludedPaths = '';

    public int $samplingPercent = 100;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'allowedOrigins' => ['required', 'string', 'max:4000'],
            'severity' => ['required', Rule::enum(CaptureSeverity::class)],
            'maskSelectors' => ['nullable', 'string', 'max:10000'],
            'blockSelectors' => ['nullable', 'string', 'max:10000'],
            'excludedPaths' => ['nullable', 'string', 'max:10000'],
            'samplingPercent' => ['required', 'integer', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedData(): array
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'allowed_origins' => $this->lines($this->allowedOrigins),
            'severity' => $this->severity,
            'mask_selectors' => $this->lines($this->maskSelectors),
            'block_selectors' => $this->lines($this->blockSelectors),
            'excluded_paths' => $this->lines($this->excludedPaths),
            'sampling_percent' => $this->samplingPercent,
        ];

        Validator::make($data, [
            'allowed_origins' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_origins.*' => ['distinct', new Origin],
            'mask_selectors' => ['array', 'max:100'],
            'mask_selectors.*' => ['string', 'max:500'],
            'block_selectors' => ['array', 'max:100'],
            'block_selectors.*' => ['string', 'max:500'],
            'excluded_paths' => ['array', 'max:100'],
            'excluded_paths.*' => ['string', 'max:500', 'starts_with:/'],
        ])->validate();

        return $data;
    }

    public function fillFrom(Application $application): void
    {
        $this->name = $application->name;
        $this->allowedOrigins = implode("\n", $application->allowed_origins);
        $this->severity = $application->severity->value;
        $this->maskSelectors = implode("\n", $application->mask_selectors);
        $this->blockSelectors = implode("\n", $application->block_selectors);
        $this->excludedPaths = implode("\n", $application->excluded_paths);
        $this->samplingPercent = $application->sampling_percent;
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $lines = array_map(trim(...), $lines);

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
