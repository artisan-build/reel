<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Rules\Origin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class ReplaceApplicationAllowedOriginsController extends Controller
{
    public function __invoke(Request $request, string $application): JsonResponse
    {
        $application = Application::query()->where('public_id', $application)->first();

        if (! $application instanceof Application) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $input = $request->all();
        $validator = Validator::make($input, [
            'allowed_origins' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'allowed_origins.*' => ['distinct:strict', new Origin],
        ]);

        $validator->after(function ($validator) use ($input): void {
            foreach (array_diff(array_keys($input), ['allowed_origins']) as $attribute) {
                $validator->errors()->add((string) $attribute, 'The request may only contain allowed_origins.');
            }
        });

        /** @var array{allowed_origins: list<string>} $validated */
        $validated = $validator->validate();

        if ($application->allowed_origins !== $validated['allowed_origins']) {
            $application->update(['allowed_origins' => $validated['allowed_origins']]);
        }

        return response()->json([
            'application_id' => $application->public_id,
            'allowed_origins' => $application->allowed_origins,
        ]);
    }
}
