---
status: accepted
date: 2026-09-12
---

# Semester Akademik direpresentasikan sebagai pasangan (academic_year_id, semester), bukan FK ke academic_semesters

`teaching_schedules` sudah menyimpan semester sebagai pasangan `academic_year_id` + `semester` (1/2) tanpa entitas semester. Fitur penilaian membutuhkan atribut per semester (tanggal mulai/selesai, tanggal UTS, flag UTS), sehingga tabel `academic_semesters` dibuat dengan unique `(academic_year_id, semester)`. Kami memutuskan tabel penilaian, absensi, dan hafalan **tetap membawa pasangan yang sama** seperti jadwal, dan `academic_semesters` dicari lewat pasangan itu, bukan dijadikan target FK. Alternatifnya (FK `academic_semester_id` di semua tabel baru) memberi integritas referensial lebih ketat tetapi menciptakan dua cara menunjuk semester dalam satu basis data dan memaksa join tambahan ke jadwal.

## Consequences

- Dua baris `academic_semesters` dibuat otomatis untuk setiap tahun ajaran (dan di-backfill untuk yang sudah ada), sehingga pencarian lewat pasangan selalu menemukan baris.
- Jika kelak jadwal dimigrasi ke FK semester, semua tabel bisa dimigrasi serentak dengan satu backfill dari pasangan.
