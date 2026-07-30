<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipCategory;
use App\Models\SportCode;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipSetupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(SportCode::query()
            ->withCount('members')
            ->with(['categories' => fn ($query) => $query->orderBy('name')])
            ->when(! $request->user()->isCentralAdministrator(), fn ($query) => $query->whereIn(
                'id',
                $request->user()->sportCodes()->pluck('sport_codes.id')
            ))
            ->orderBy('name')
            ->get());
    }

    public function storeSportCode(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdministrator(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:sport_codes,name'],
            'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $sportCode = SportCode::create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
            'active' => true,
        ]);
        Audit::record('sport_code.created', $sportCode, [], $sportCode->getAttributes());

        return response()->json($sportCode, 201);
    }

    public function updateSportCode(Request $request, SportCode $sportCode): JsonResponse
    {
        abort_unless($request->user()->isSuperAdministrator(), 403);
        $old = $sportCode->getAttributes();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sport_codes', 'name')->ignore($sportCode)],
            'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['required', 'boolean'],
        ]);

        $sportCode->update([
            ...$validated,
            'slug' => Str::slug($validated['name']),
        ]);
        Audit::record('sport_code.updated', $sportCode, $old, $sportCode->fresh()->getAttributes());

        return response()->json($sportCode);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessSportCode($request->integer('sport_code_id'), true), 403);
        $payload = $this->categoryPayload($request);
        abort_if(! $request->user()->isCentralAdministrator() && $payload['hsc_levy_cents'] !== 0, 403);
        $category = MembershipCategory::create($payload);
        Audit::record('membership_category.created', $category, [], $category->getAttributes());

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, MembershipCategory $category): JsonResponse
    {
        abort_unless($request->user()->canAccessSportCode($category->sport_code_id, true), 403);
        $old = $category->getAttributes();
        $payload = $this->categoryPayload($request, $category);
        abort_if(
            ! $request->user()->isCentralAdministrator()
            && $payload['hsc_levy_cents'] !== $category->hsc_levy_cents,
            403
        );
        $category->update($payload);
        Audit::record('membership_category.updated', $category, $old, $category->fresh()->getAttributes());

        return response()->json($category->fresh());
    }

    private function categoryPayload(Request $request, ?MembershipCategory $category = null): array
    {
        $validated = $request->validate([
            'sport_code_id' => ['required', 'exists:sport_codes,id'],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('membership_categories', 'name')
                    ->where('sport_code_id', $request->integer('sport_code_id'))
                    ->ignore($category),
            ],
            'billing_period' => ['required', Rule::in(['monthly', 'quarterly', 'annual', 'once_off'])],
            'code_fee' => ['required', 'numeric', 'min:0', 'max:999999'],
            'hsc_levy' => ['required', 'numeric', 'min:0', 'max:999999'],
            'minimum_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'maximum_age' => ['nullable', 'integer', 'min:0', 'max:120', 'gte:minimum_age'],
            'active' => ['required', 'boolean'],
        ]);

        return [
            'sport_code_id' => $validated['sport_code_id'],
            'name' => $validated['name'],
            'billing_period' => $validated['billing_period'],
            'code_fee_cents' => (int) round($validated['code_fee'] * 100),
            'hsc_levy_cents' => (int) round($validated['hsc_levy'] * 100),
            'minimum_age' => $validated['minimum_age'] ?? null,
            'maximum_age' => $validated['maximum_age'] ?? null,
            'active' => $validated['active'],
        ];
    }
}
