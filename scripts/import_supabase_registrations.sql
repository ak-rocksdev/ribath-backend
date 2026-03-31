-- =============================================================================
-- Import Supabase calon_santri_registrations → Laravel registrations
-- =============================================================================
-- Source: backups/supabase_data_backup.sql (18 records)
-- Target: local PostgreSQL ribath_app_local
--
-- Column mapping:
--   Supabase                → Laravel
--   id                      → id (keep original UUID)
--   psb_period_id           → registration_period_id (new UUID, mapped below)
--   registration_number     → registration_number
--   status (interest/contacted/scheduled_visit/deleted)
--                           → status (new/contacted/interview/cancelled)
--   registrant_type         → registrant_type (wali/santri — unchanged)
--   nama_lengkap            → full_name
--   tempat_lahir            → birth_place
--   tanggal_lahir           → birth_date
--   jenis_kelamin           → gender (Laki-laki→M, Perempuan→F)
--   program_minat           → preferred_program (regular/tahfidz — unchanged)
--   nama_ayah / nama_wali   → guardian_name
--   no_hp_ayah / no_hp_wali → guardian_phone
--   email_wali / email_ayah → guardian_email
--   sumber_info             → info_source
--   admin_notes             → admin_notes
--   contacted_at            → contacted_at
--   created_at              → created_at
--   updated_at              → updated_at
--   (deleted status)        → deleted_at (soft delete timestamp)
-- =============================================================================

BEGIN;

-- Step 1: Create the registration period (from Supabase psb_periods)
-- Supabase UUID: f9dff599-8a16-4095-a0f6-f354faa348a1
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
    0,
    'Pendaftaran gelombang pertama untuk tahun ajaran baru 2025/2026. Segera daftarkan putra Anda!',
    true,
    '2026-02-02 15:37:37',
    '2026-02-17 15:28:31',
    '019c95f1-9882-73bc-88ca-f32676db7ed8'
);

-- Step 2: Import all 18 registrations
-- Status mapping: interest→new, contacted→contacted, scheduled_visit→interview, visited→visited, deleted→cancelled (with deleted_at)
-- Gender mapping: Laki-laki→M, Perempuan→F

-- #1 PSB-2026-00078 — Abdul Kadir Hasan (DELETED — test by admin)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('735ba165-a0e4-4568-9e4e-0496d2665196', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00078', 'cancelled', 'wali', 'Abdul Kadir Hasan', 'Surakarta', '2003-02-17', 'M', 'regular', 'Hasan Anis', '083121220110', 'kadirhabsy110@gmail.com', 'alumni', NULL, '2026-02-03 07:44:46', NULL, NULL, NULL, NULL, NULL, '2026-02-03 07:42:25', '2026-02-09 07:21:05', '2026-02-09 07:21:05', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #2 PSB-2026-00083 — Ahmad Alwi (REAL — contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('f04e3a41-a951-4f62-b604-de23a81ce71b', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00083', 'contacted', 'wali', 'Ahmad Alwi', 'Tengaran, KAB. SEMARANG', '2007-12-15', 'M', 'regular', 'Achmad Hilal', '085740555553', NULL, 'sosial_media', NULL, '2026-02-18 10:49:03', NULL, NULL, NULL, NULL, NULL, '2026-02-09 05:38:10', '2026-02-18 10:49:04', NULL, NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #3 PSB-2026-00079 — Muhammad Ibrahim Ar-Razi Apriyanto (REAL — contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('11237ff3-eb1c-4afa-9410-fe6bffe6d7b0', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00079', 'contacted', 'wali', 'Muhammad ibrahim ar-razi apriyanto', 'Karanganyar', '2014-01-25', 'M', 'tahfidz', 'Apri agus wiyanto', '083865607887', 'murtini.i2389@gmail.com', 'website', NULL, '2026-02-07 09:08:32', NULL, NULL, NULL, NULL, NULL, '2026-02-05 03:13:47', '2026-02-07 09:08:32', NULL, NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #4 PSB-2026-00084 — Muhammad Abdurrahman Gesit (REAL — contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('39d8c447-3306-4f4c-9ff7-ad2fc3de6b12', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00084', 'contacted', 'wali', 'Muhammad Abdurrahman Gesit', 'Tegal', '2008-10-14', 'M', 'regular', 'MUHAMAD GALIH SUBEHAN', '087880731179', 'galihsubehan@gmail.com', 'sosial_media', NULL, '2026-02-18 10:50:11', NULL, NULL, NULL, NULL, NULL, '2026-02-15 09:26:16', '2026-02-18 10:50:12', NULL, NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #5 PSB-2026-00080 — Muhammad Haikal Sulaiman (REAL — contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('ffadb165-7d0d-4f43-96c8-fa36320cd775', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00080', 'contacted', 'santri', 'Muhammad Haikal Sulaiman', 'Rembang', '2008-08-17', 'M', 'regular', 'Sumiyati', '087812888189', 'ysumi1082@gmail.com', 'sosial_media', NULL, '2026-02-07 09:10:06', NULL, NULL, NULL, NULL, NULL, '2026-02-05 09:59:25', '2026-02-07 09:10:06', NULL, NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #6 PSB-2026-00085 — MUHAMMAD ASHLIH DINA (REAL — contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('68b1996b-fc00-4888-8935-4c0c7e0757cc', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00085', 'contacted', 'santri', 'MUHAMMAD ASHLIH DINA', 'Kudus Jawa Tengah', '2006-01-02', 'M', 'regular', 'Sukari', '088227316300', 'muhammadaslihdina@gmail.com', 'lainnya', NULL, '2026-02-18 10:50:56', NULL, NULL, NULL, NULL, NULL, '2026-02-17 15:28:31', '2026-02-18 10:50:57', NULL, NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #7 PSB-2026-00081 — Abdul Kadir Syahab (DELETED — test by admin)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('1e5c765f-df1d-4fa2-a1c5-910a5b8c7e9b', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00081', 'cancelled', 'wali', 'Abdul Kadir Syahab', 'Surakarta', '2004-02-05', 'M', 'regular', 'Nama orang tua wali', '081291720267', 'frenchfriespeople@gmail.com', 'masjid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-07 00:33:39', '2026-02-07 00:44:50', '2026-02-07 00:44:50', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #8 PSB-2026-00071 — Fatimah binti Ahmad (DELETED — seed/test data)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('dc213011-12cc-4a3e-a7f3-68adec15ec3d', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00071', 'cancelled', 'wali', 'Fatimah binti Ahmad', '-', '2011-07-15', 'F', 'regular', 'Ahmad bin Umar', '081234567002', 'ahmad.umar@email.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:07:11', '2026-02-06 23:40:00', '2026-02-06 23:40:00', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #9 PSB-2026-00070 — Ahmad Fadhil bin Abdullah (DELETED — seed/test data)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('c1ce23ab-cc2d-4efb-aed1-a7331612efda', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00070', 'cancelled', 'wali', 'Ahmad Fadhil bin Abdullah', '-', '2012-03-20', 'M', 'tahfidz', 'Abdullah bin Muhammad', '081234567001', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:07:10', '2026-02-06 23:44:37', '2026-02-06 23:44:37', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #10 PSB-2026-00073 — Ahmad Fadhil bin Abdullah (DELETED — duplicate seed)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('32af6d74-f4d7-4a19-88a0-f9658cb9183c', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00073', 'cancelled', 'wali', 'Ahmad Fadhil bin Abdullah', '-', '2012-03-20', 'M', 'tahfidz', 'Abdullah bin Muhammad', '081234567001', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:11:10', '2026-02-06 23:44:49', '2026-02-06 23:44:49', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #11 PSB-2026-00072 — Ali bin Abi Thalib (DELETED — seed/test data)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('58e955d2-434b-485a-acd1-7f4af7ddc784', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00072', 'cancelled', 'wali', 'Ali bin Abi Thalib', '-', '2014-02-28', 'M', 'tahfidz', 'Abu Thalib', '081234567008', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:07:16', '2026-02-06 23:44:49', '2026-02-06 23:44:49', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #12 PSB-2026-00069 — Muhammad Ridwan (DELETED — seed/test data)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('7eb992d9-a691-425b-86b4-b0abb67499ad', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00069', 'cancelled', 'santri', 'Muhammad Ridwan', '-', '2007-01-10', 'M', 'tahfidz', 'Pak Ridwan Ayah', '081234567003', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:07:10', '2026-02-06 23:44:49', '2026-02-06 23:44:49', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #13 PSB-2026-00082 — Abdul Kadir Syahab (DELETED — test by admin, guardian="tes")
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('0919b320-4425-42c5-b09d-9d95c349893b', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00082', 'cancelled', 'wali', 'Abdul Kadir Syahab', 'Surakarta', '2012-02-02', 'M', 'regular', 'tes', '081291720267', 'cunojejyry@mailinator.com', 'google', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-07 00:47:14', '2026-02-07 01:00:40', '2026-02-07 01:00:40', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #14 PSB-2026-00075 — Muhammad Ridwan (DELETED — seed, was contacted)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('403b5e60-080a-4045-9b65-bd2b71bd06e3', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00075', 'cancelled', 'santri', 'Muhammad Ridwan', '-', '2007-01-10', 'M', 'tahfidz', 'Pak Ridwan Ayah', '081234567003', NULL, NULL, NULL, '2026-02-02 15:44:24', NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:11:21', '2026-02-07 01:19:16', '2026-02-07 01:19:16', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #15 PSB-2026-00077 — Ali bin Abi Thalib (DELETED — seed, was contacted + had visit scheduled)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('2fd454cf-bb82-4e45-bb36-57dd1c370618', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00077', 'cancelled', 'wali', 'Ali bin Abi Thalib', '-', '2014-02-28', 'M', 'tahfidz', 'Abu Thalib', '081234567008', NULL, NULL, NULL, '2026-02-02 15:58:41', NULL, '2026-02-04 09:00:00', NULL, NULL, NULL, '2026-02-02 14:11:35', '2026-02-07 01:19:16', '2026-02-07 01:19:16', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #16 PSB-2026-00076 — Umar bin Khattab (DELETED — seed/test data)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('9e6ee544-ae83-4545-b301-60ae9429fbd7', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00076', 'cancelled', 'wali', 'Umar bin Khattab', '-', '2013-09-05', 'M', 'regular', 'Khattab bin Nufail', '081234567004', NULL, 'google', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:11:21', '2026-02-07 01:19:16', '2026-02-07 01:19:16', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- #17 PSB-2026-00074 — Fatimah binti Ahmad (DELETED — duplicate seed)
INSERT INTO registrations (id, registration_period_id, registration_number, status, registrant_type, full_name, birth_place, birth_date, gender, preferred_program, guardian_name, guardian_phone, guardian_email, info_source, admin_notes, contacted_at, contacted_by, interviewed_at, reviewed_at, reviewed_by, rejection_reason, created_at, updated_at, deleted_at, visited_at, is_archived, school_id)
VALUES ('955215c4-80f5-4aa0-a885-5efeb872b0df', 'f9dff599-8a16-4095-a0f6-f354faa348a1', 'PSB-2026-00074', 'cancelled', 'wali', 'Fatimah binti Ahmad', '-', '2011-07-15', 'F', 'regular', 'Ahmad bin Umar', '081234567002', 'ahmad.umar@email.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-02 14:11:13', '2026-02-07 01:19:16', '2026-02-07 01:19:16', NULL, false, '019c95f1-9882-73bc-88ca-f32676db7ed8');

-- Update enrolled_count on registration period
UPDATE registration_periods
SET enrolled_count = (SELECT count(*) FROM registrations WHERE registration_period_id = 'f9dff599-8a16-4095-a0f6-f354faa348a1' AND deleted_at IS NULL)
WHERE id = 'f9dff599-8a16-4095-a0f6-f354faa348a1';

COMMIT;
