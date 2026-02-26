<?php

namespace App\Listeners;

use App\Events\NewNotificationEvent;
use App\Events\RegistrationCreated;
use App\Models\Notification;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SendRegistrationNotification
{
    public function handle(RegistrationCreated $event): void
    {
        $registration = $event->registration;

        $adminUsers = collect();

        $permissionExists = Permission::where('name', 'manage-registrations')->exists();
        if ($permissionExists) {
            $adminUsers = User::permission('manage-registrations')->get();
        }

        $superAdminRoleExists = Role::where('name', 'super_admin')->exists();
        if ($superAdminRoleExists) {
            $superAdminUsers = User::role('super_admin')->get();
            $adminUsers = $adminUsers->merge($superAdminUsers)->unique('id');
        }

        foreach ($adminUsers as $adminUser) {
            $notification = Notification::create([
                'user_id' => $adminUser->id,
                'school_id' => $registration->school_id,
                'type' => Notification::TYPE_INFO,
                'title' => "Pendaftaran Baru: {$registration->full_name}",
                'message' => "Pendaftaran {$registration->registration_number} telah masuk. Program: {$registration->preferred_program}, Tipe: {$registration->registrant_type}.",
                'priority' => Notification::PRIORITY_MEDIUM,
                'category' => Notification::CATEGORY_PSB,
                'metadata' => [
                    'registration_id' => $registration->id,
                    'registration_number' => $registration->registration_number,
                    'full_name' => $registration->full_name,
                    'preferred_program' => $registration->preferred_program,
                    'registrant_type' => $registration->registrant_type,
                    'guardian_phone' => $registration->guardian_phone,
                ],
                'action_url' => '/admin/pendaftaran-masuk',
                'action_label' => 'Lihat Pendaftaran',
            ]);

            NewNotificationEvent::dispatch($notification);
        }
    }
}
