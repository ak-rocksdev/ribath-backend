-- Import Supabase notification data for user akhabsy110@gmail.com (Laravel user_id = 424)
-- Other Supabase users don't exist in the Laravel DB, so their notifications are skipped.
--
-- Supabase user 33cbbb8f-3f8f-4b56-9224-a3a170239019 = akhabsy110@gmail.com = Laravel user 424
-- School ID: 019c95f1-9882-73bc-88ca-f32676db7ed8
--
-- Metadata key mapping (Indonesian → English):
--   nama_lengkap → full_name
--   program_minat → preferred_program
--   no_hp_wali → guardian_phone
--   status "interest" → "new"
--
-- Category mapping: administrative → psb (these are all PSB registration notifications)

INSERT INTO notifications (id, user_id, school_id, type, title, message, priority, category, metadata, is_read, read_at, action_url, action_label, created_at, updated_at)
VALUES
-- PSB-2026-00082 (Abdul Kadir Syahab) — read
(
    gen_random_uuid(), 424,
    '019c95f1-9882-73bc-88ca-f32676db7ed8',
    'info',
    'Pendaftaran Baru: Abdul Kadir Syahab',
    'Pendaftaran PSB-2026-00082 telah masuk. Program: regular, Tipe: wali.',
    'high', 'psb',
    '{"full_name": "Abdul Kadir Syahab", "preferred_program": "regular", "registrant_type": "wali", "guardian_phone": "081291720267", "registration_number": "PSB-2026-00082"}'::jsonb,
    true,
    '2026-02-07 01:00:20.256118+00',
    '/admin/pendaftaran-masuk', 'Lihat Pendaftaran',
    '2026-02-07 00:47:14.036312+00', '2026-02-07 01:00:20.256118+00'
),
-- PSB-2026-00083 (Ahmad Alwi) — read
(
    gen_random_uuid(), 424,
    '019c95f1-9882-73bc-88ca-f32676db7ed8',
    'info',
    'Pendaftaran Baru: Ahmad Alwi',
    'Pendaftaran PSB-2026-00083 telah masuk. Program: regular, Tipe: wali.',
    'high', 'psb',
    '{"full_name": "Ahmad Alwi", "preferred_program": "regular", "registrant_type": "wali", "guardian_phone": "085740555553", "registration_number": "PSB-2026-00083"}'::jsonb,
    true,
    '2026-02-17 14:12:53.538024+00',
    '/admin/pendaftaran-masuk', 'Lihat Pendaftaran',
    '2026-02-09 05:38:10.202604+00', '2026-02-17 14:12:53.538024+00'
),
-- PSB-2026-00084 (Muhammad Abdurrahman Gesit) — read
(
    gen_random_uuid(), 424,
    '019c95f1-9882-73bc-88ca-f32676db7ed8',
    'info',
    'Pendaftaran Baru: Muhammad Abdurrahman Gesit',
    'Pendaftaran PSB-2026-00084 telah masuk. Program: regular, Tipe: wali.',
    'high', 'psb',
    '{"full_name": "Muhammad Abdurrahman Gesit", "preferred_program": "regular", "registrant_type": "wali", "guardian_phone": "087880731179", "registration_number": "PSB-2026-00084"}'::jsonb,
    true,
    '2026-02-17 14:12:53.538024+00',
    '/admin/pendaftaran-masuk', 'Lihat Pendaftaran',
    '2026-02-15 09:26:16.638405+00', '2026-02-17 14:12:53.538024+00'
),
-- PSB-2026-00085 (MUHAMMAD ASHLIH DINA) — read
(
    gen_random_uuid(), 424,
    '019c95f1-9882-73bc-88ca-f32676db7ed8',
    'info',
    'Pendaftaran Baru: MUHAMMAD ASHLIH DINA',
    'Pendaftaran PSB-2026-00085 telah masuk. Program: regular, Tipe: santri.',
    'high', 'psb',
    '{"full_name": "MUHAMMAD ASHLIH DINA", "preferred_program": "regular", "registrant_type": "santri", "guardian_phone": "088227316300", "registration_number": "PSB-2026-00085"}'::jsonb,
    true,
    '2026-02-18 16:15:25.101815+00',
    '/admin/pendaftaran-masuk', 'Lihat Pendaftaran',
    '2026-02-17 15:28:31.721932+00', '2026-02-18 16:15:25.101815+00'
);
