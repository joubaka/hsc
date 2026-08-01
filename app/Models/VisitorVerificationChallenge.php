<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorVerificationChallenge extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function visitor()
    {
        return $this->belongsTo(PublicVisitor::class, 'public_visitor_id');
    }
}
