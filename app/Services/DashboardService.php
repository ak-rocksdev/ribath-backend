<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Teacher;

class DashboardService
{
    public function getStats(): array
    {
        $startOfMonth = now()->startOfMonth();

        return [
            'total_active_students' => Student::where('status', 'active')->count(),
            'total_active_teachers' => Teacher::where('status', 'active')->count(),
            'new_students_this_month' => Student::where('created_at', '>=', $startOfMonth)->count(),
            'pending_invoices' => 0,
            'financial_overview' => [
                'payments_this_month' => 0,
                'outstanding' => 0,
                'target_this_month' => 0,
            ],
            'recent_activities' => [],
        ];
    }
}
