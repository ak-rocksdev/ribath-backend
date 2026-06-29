<?php

namespace App\Http\Resources\Keuangan;

use Illuminate\Http\Resources\Json\JsonResource;

class FeeActivityLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'action' => $this->action,
            'actor_kind' => $this->actor_kind,
            // Display label resolves user name for `user` rows, localized
            // "Sistem (Penjadwal)" etc. for system actors — never a blank cell.
            'actor_label' => $this->actorDisplayLabel(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'changes' => $this->changes,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
