-- Import 5 real registrations from Supabase into production
-- Only active (non-deleted) records

BEGIN;

-- Step 1: Create the registration period
INSERT INTO registration_periods (
    id, name, year, wave, registration_open, registration_close,
    entry_date, registration_fee, monthly_tuition_fee, student_quota,
    enrolled_count, description, is_active, created_at, updated_at, school_id
) VALUES (
    'f9dff599-8a16-4095-a0f6-f354faa348a1',
    'Gelombang 1',
    '2025/2026',
    1,
    '2026-02-05 00:00:00',
    '2026-03-05 00:00:00',
    '2026-06-17',
    6000000.00,
    1000000.00,
    30,
    5,
    'Pendaftaran gelombang pertama untuk tahun ajaran baru 2025/2026. Segera daftarkan putra Anda!',
    true,
    '2026-02-02 15:37:37',
    '2026-02-17 15:28:31',
    '019c9a83-4155-71c4-b45c-b5ea135a7046'
);

-- Step 2: Insert 5 active registrations

-- PSB-2026-00079 — Muhammad Ibrahim Ar-Razi Apriyanto (contacted, wali, tahfidz, website)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('11237ff3-eb1c-4afa-9410-fe6bffe6d7b0', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00079', 'contacted', 'wali', 'Muhammad ibrahim ar-razi apriyanto', 'Karanganyar', '2014-01-25', 'M', 'tahfidz', 'Apri agus wiyanto', '083865607887', 'murtini.i2389@gmail.com', 'website', NULL, '2026-02-07 09:08:32', NULL, NULL, NULL, NULL, NULL, '2026-02-05 03:13:47', '2026-02-07 09:08:32', NULL, NULL, false, '019c9a83-4155-71c4-b45c-b5ea135a7046');

-- PSB-2026-00080 — Muhammad Haikal Sulaiman (contacted, santri, regular, sosial_media)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('ffadb165-7d0d-4f43-96c8-fa36320cd775', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00080', 'contacted', 'santri', 'Muhammad Haikal Sulaiman', 'Rembang', '2008-08-17', 'M', 'regular', 'Sumiyati', '087812888189', 'ysumi1082@gmail.com', 'sosial_media', NULL, '2026-02-07 09:10:06', NULL, NULL, NULL, NULL, NULL, '2026-02-05 09:59:25', '2026-02-07 09:10:06', NULL, NULL, false, '019c9a83-4155-71c4-b45c-b5ea135a7046');

-- PSB-2026-00083 — Ahmad Alwi (contacted, wali, regular, sosial_media)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('f04e3a41-a951-4f62-b604-de23a81ce71b', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00083', 'contacted', 'wali', 'Ahmad Alwi', 'Tengaran, KAB. SEMARANG', '2007-12-15', 'M', 'regular', 'Achmad Hilal', '085740555553', NULL, 'sosial_media', NULL, '2026-02-18 10:49:03', NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:38:10', '2026-02-18 10:49:04', NULL, NULL, false, '019c9a83-4155-71c4-b45c-b5ea135a7046');

-- PSB-2026-00084 — Muhammad Abdurrahman Gesit (contacted, wali, regular, sosial_media)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('39d8c447-3306-4f4c-9ff7-ad2fc3de6b12', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00084', 'contacted', 'wali', 'Muhammad Abdurrahman Gesit', 'Tegal', '2008-10-14', 'M', 'regular', 'MUHAMAD GALIH SUBEHAN', '087880731179', 'galihsubehan@gmail.com', 'sosial_media', NULL, '2026-02-18 10:50:11', NULL, NULL, NULL, NULL, NULL, '2026-02-15 09:26:16', '2026-02-18 10:50:12', NULL, NULL, false, '019c9a83-4155-71c4-b45c-b5ea135a7046');

-- PSB-2026-00085 — MUHAMMAD ASHLIH DINA (contacted, santri, regular, lainnya)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('68b1996b-fc00-4888-8935-4c0c7e0757cc', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00085', 'contacted', 'santri', 'MUHAMMAD ASHLIH DINA', 'Kudus Jawa Tengah', '2006-01-02', 'M', 'regular', 'Sukari', '088227316300', 'muhammadaslihdina@gmail.com', 'lainnya', NULL, '2026-02-18 10:50:56', NULL, NULL, NULL, NULL, NULL, '2026-02-17 15:28:31', '2026-02-18 10:50:57', NULL, NULL, false, '019c9a83-4155-71c4-b45c-b5ea135a7046');

COMMIT;
