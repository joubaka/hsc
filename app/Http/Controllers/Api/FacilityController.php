<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityGroup;
use App\Models\FacilityTag;
use App\Models\SportCode;
use App\Models\Venue;
use App\Services\BookingAccessService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FacilityController extends Controller
{
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $sportIds = $user->isCentralAdministrator() ? SportCode::pluck('id') : $user->sportCodes()->pluck('sport_codes.id');

        return response()->json([
            'venues' => Venue::orderBy('display_order')->get(), 'groups' => FacilityGroup::whereIn('sport_code_id', $sportIds)->orWhereNull('sport_code_id')->get(),
            'tags' => FacilityTag::orderBy('name')->get(), 'sport_codes' => SportCode::whereIn('id', $sportIds)->get(),
            'members' => \App\Models\Member::where('status', 'active')->orderBy('last_name')->get(['id', 'member_number', 'first_name', 'last_name']),
            'can_manage_central' => $user->isCentralAdministrator(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $facilities = Facility::with(['venue', 'sportCode', 'group', 'tags', 'openingHours'])->orderBy('display_order')->get()
            ->filter(fn ($facility) => app(BookingAccessService::class)->canView($request->user(), $facility))->values();

        return response()->json($facilities);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateFacility($request);
        abort_unless($request->user()->isCentralAdministrator() || $request->user()->canAccessSportCode((int) $data['sport_code_id'], true), 403);
        $facility = Facility::create(collect($data)->except('tag_ids')->all());
        $facility->tags()->sync($data['tag_ids'] ?? []);
        Audit::record('facility.created', $facility, [], $facility->getAttributes());

        return response()->json($facility->load(['venue', 'sportCode', 'group', 'tags']), 201);
    }

    public function update(Request $request, Facility $facility): JsonResponse
    {
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        $data = $this->validateFacility($request, $facility);
        abort_unless($request->user()->isCentralAdministrator() || $request->user()->canAccessSportCode((int) $data['sport_code_id'], true), 403);
        $old = $facility->getAttributes();
        $facility->update(collect($data)->except('tag_ids')->all());
        $facility->tags()->sync($data['tag_ids'] ?? []);
        Audit::record('facility.updated', $facility, $old, $facility->fresh()->getAttributes());

        return response()->json($facility->fresh()->load(['venue', 'sportCode', 'group', 'tags']));
    }

    private function validateFacility(Request $request, ?Facility $facility = null): array
    {
        return $request->validate([
            'venue_id' => ['required', 'exists:venues,id'], 'sport_code_id' => ['nullable', 'exists:sport_codes,id'],
            'facility_group_id' => ['nullable', 'exists:facility_groups,id'], 'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:40', Rule::unique('facilities')->ignore($facility)],
            'facility_type' => ['required', Rule::in(['tennis_court', 'padel_court', 'squash_court', 'netball_court', 'field', 'hall', 'other'])],
            'surface_type' => ['nullable', 'string', 'max:100'], 'indoor' => ['required', 'boolean'], 'capacity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'], 'booking_instructions' => ['nullable', 'string'], 'access_zone' => ['nullable', 'string', 'max:100'],
            'lighting_available' => ['required', 'boolean'], 'publicly_visible' => ['required', 'boolean'], 'bookable' => ['required', 'boolean'],
            'active' => ['required', 'boolean'], 'display_order' => ['required', 'integer', 'min:0'],
            'default_duration_minutes' => ['required', 'integer', 'between:15,480'], 'start_interval_minutes' => ['required', 'integer', Rule::in([15, 30, 60])],
            'tag_ids' => ['array'], 'tag_ids.*' => ['integer', 'exists:facility_tags,id'],
        ]);
    }
}
