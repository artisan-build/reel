<?php

namespace App\Http\Requests;

use App\Models\ApplicationCredential;
use App\Rules\RsaPublicKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollment_code' => ['required', 'string', 'max:128'],
            'algorithm' => ['required', 'string', Rule::in([ApplicationCredential::ALGORITHM])],
            'public_key' => ['required', 'string', 'max:10000', new RsaPublicKey],
        ];
    }
}
