<?php

namespace App\Http\Requests\Akademik;

use App\Services\Akademik\ReportCardService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /report-cards/{reportCard}/unfinalize: the required reason.
 * authorize() checks tenancy on the route-bound rapor first (404), then the
 * super_admin role (403 with ReportCardService's message) — both before the
 * body is validated. The service repeats the role check.
 */
class UnfinalizeReportCardRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(ReportCardService $reportCardService): bool
    {
        $reportCard = $this->route('reportCard');

        if ($reportCard) {
            $this->ensureBelongsToActiveSchool($reportCard);
        }

        $reportCardService->assertActorCanUnfinalize();

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.ReportCardRules::REASON_MAX_LENGTH],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.string' => 'Alasan pembatalan tidak valid.',
            'reason.max' => 'Alasan pembatalan maksimal '.ReportCardRules::REASON_MAX_LENGTH.' karakter.',
        ];
    }
}
