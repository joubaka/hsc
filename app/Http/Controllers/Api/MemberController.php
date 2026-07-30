<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\SportCode;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $members = Member::query()
            ->with(['household', 'sportCodes', 'accessCredential'])
            ->when(! $user->isCentralAdministrator(), fn ($query) => $query->whereHas(
                'sportCodes',
                fn ($sportQuery) => $sportQuery->whereIn('sport_codes.id', $user->sportCodes()->pluck('sport_codes.id'))
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('member_number', 'like', $search)
                        ->orWhere('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('mobile', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('sport_code'), fn ($query) => $query->whereHas(
                'sportCodes',
                fn ($sportQuery) => $sportQuery->where('sport_codes.id', $request->integer('sport_code'))
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($members);
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedCodeIds = $user->isCentralAdministrator() ? null : $user->sportCodes()->pluck('sport_codes.id');
        $memberScope = fn () => Member::query()->when($allowedCodeIds !== null, fn ($query) => $query->whereHas(
            'sportCodes',
            fn ($sportQuery) => $sportQuery->whereIn('sport_codes.id', $allowedCodeIds)
        ));

        return response()->json([
            'sport_codes' => SportCode::query()
                ->with(['categories' => fn ($query) => $query->where('active', true)->orderBy('name')])
                ->where('active', true)
                ->when($allowedCodeIds !== null, fn ($query) => $query->whereIn('id', $allowedCodeIds))
                ->orderBy('name')
                ->get(),
            'stats' => [
                'total' => $memberScope()->count(),
                'active' => $memberScope()->where('status', 'active')->count(),
                'juniors' => $memberScope()->whereNotNull('date_of_birth')
                    ->whereDate('date_of_birth', '>', now()->subYears(18))
                    ->count(),
                'access' => $user->isCentralAdministrator()
                    ? $memberScope()->whereHas('accessCredential', fn ($query) => $query->where('status', 'active'))->count()
                    : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $payload = $this->validated($request);
        $member = Member::create([
            ...$payload['member'],
            'member_number' => $this->nextMemberNumber(),
            'household_id' => $this->resolveHousehold($payload['household_name']),
        ]);

        $this->syncRelated($member, $payload);
        Audit::record('member.created', $member, [], $member->getAttributes());

        return response()->json($this->loadMember($member), 201);
    }

    public function show(Request $request, Member $member): JsonResponse
    {
        $this->authorizeMemberScope($request, $member);

        return response()->json($this->loadMember($member));
    }

    public function update(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $old = $member->getAttributes();
        $payload = $this->validated($request);
        $member->update([
            ...$payload['member'],
            'household_id' => $this->resolveHousehold($payload['household_name']),
        ]);
        $this->syncRelated($member, $payload);
        Audit::record('member.updated', $member, $old, $member->fresh()->getAttributes());

        return response()->json($this->loadMember($member));
    }

    public function destroy(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $old = $member->getAttributes();
        $member->update(['status' => 'archived']);
        $member->delete();
        Audit::record('member.archived', $member, $old, ['status' => 'archived']);

        return response()->json(['message' => 'Member archived.']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'preferred_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['prospective', 'active', 'suspended', 'resigned'])],
            'household_name' => ['nullable', 'string', 'max:150'],
            'household_role' => ['nullable', 'string', 'max:80'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_mobile' => ['nullable', 'string', 'max:30'],
            'email_consent' => ['boolean'],
            'whatsapp_consent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sport_codes' => ['array'],
            'sport_codes.*.sport_code_id' => ['required', 'exists:sport_codes,id'],
            'sport_codes.*.category_id' => ['nullable', 'exists:membership_categories,id'],
            'sport_codes.*.status' => ['required', Rule::in(['application', 'awaiting_payment', 'active', 'on_hold', 'lapsed', 'suspended'])],
            'sport_codes.*.role' => ['required', Rule::in(['member', 'player', 'social', 'coach', 'committee', 'administrator'])],
            'access.credential_type' => ['required', Rule::in(['pin', 'card', 'tag', 'mobile'])],
            'access.status' => ['required', Rule::in(['not_issued', 'pending', 'active', 'suspended', 'expired', 'revoked'])],
            'access.areas' => ['array'],
            'access.areas.*' => [Rule::in(['main_entrance', 'clubhouse', 'tennis_courts', 'padel_courts', 'squash_building', 'equipment_room'])],
            'access.valid_until' => ['nullable', 'date'],
        ]);

        return [
            'member' => [
                'first_name' => $validated['first_name'],
                'preferred_name' => $validated['preferred_name'] ?? null,
                'last_name' => $validated['last_name'],
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'email' => $validated['email'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'status' => $validated['status'],
                'household_role' => $validated['household_role'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_mobile' => $validated['emergency_contact_mobile'] ?? null,
                'email_consent' => $validated['email_consent'] ?? false,
                'whatsapp_consent' => $validated['whatsapp_consent'] ?? false,
                'notes' => $validated['notes'] ?? null,
            ],
            'household_name' => $validated['household_name'] ?? null,
            'sport_codes' => $validated['sport_codes'] ?? [],
            'access' => $validated['access'],
        ];
    }

    private function syncRelated(Member $member, array $payload): void
    {
        $sync = collect($payload['sport_codes'])->mapWithKeys(fn ($settings) => [
            $settings['sport_code_id'] => [
                'membership_category_id' => $settings['category_id'] ?? null,
                'status' => $settings['status'],
                'role' => $settings['role'],
                'joined_at' => now()->toDateString(),
            ],
        ])->all();

        $member->sportCodes()->sync($sync);
        $access = $payload['access'];
        $access['issued_at'] = $access['status'] === 'active' ? now() : null;
        $member->accessCredential()->updateOrCreate([], $access);
    }

    private function resolveHousehold(?string $name): ?int
    {
        return $name ? Household::firstOrCreate(['name' => trim($name)])->id : null;
    }

    private function nextMemberNumber(): string
    {
        $next = (Member::withTrashed()->max('id') ?? 0) + 1;

        return 'HSC-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function loadMember(Member $member): Member
    {
        $member->load(['household.members', 'sportCodes', 'accessCredential']);
        $categories = MembershipCategory::whereIn(
            'id',
            $member->sportCodes->pluck('pivot.membership_category_id')->filter()
        )->get()->keyBy('id');

        $member->sportCodes->each(function ($code) use ($categories) {
            $code->setAttribute('membership_category', $categories->get($code->pivot->membership_category_id));
        });

        return $member;
    }

    private function authorizeMemberScope(Request $request, Member $member): void
    {
        if ($request->user()->isCentralAdministrator()) {
            return;
        }

        abort_unless($member->sportCodes()
            ->whereIn('sport_codes.id', $request->user()->sportCodes()->pluck('sport_codes.id'))
            ->exists(), 403);
    }
}
