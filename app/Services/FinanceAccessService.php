<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;

class FinanceAccessService
{
    public function canWrite(User $user, int $sportCodeId): bool
    {
        return $user->canAccessSportCode($sportCodeId, true);
    }

    public function canViewInvoice(User $user, Invoice $invoice): bool
    {
        if ($user->isCentralAdministrator()) {
            return true;
        }

        $sportIds = $invoice->lines()->whereNotNull('sport_code_id')->pluck('sport_code_id')->unique();

        return $sportIds->isNotEmpty() && $sportIds->every(fn ($id) => $user->canAccessSportCode((int) $id));
    }

    public function canWriteInvoice(User $user, Invoice $invoice): bool
    {
        if ($user->isCentralAdministrator()) {
            return true;
        }

        $sportIds = $invoice->lines()->whereNotNull('sport_code_id')->pluck('sport_code_id')->unique();

        return $sportIds->isNotEmpty() && $sportIds->every(fn ($id) => $this->canWrite($user, (int) $id));
    }
}
