---
status: accepted
date: 2026-09-15
---

# Izin berpasangan "semua" dan "milik sendiri" sebagai pengganti Policy

Akun Ustadz hanya boleh menyentuh data penilaian di dalam Cakupan Mengajar-nya, sedangkan pengurus (termasuk pengurus yang juga mengajar) melihat semua. Codebase ini menetapkan otorisasi hanya lewat Spatie Permission, tanpa Laravel Policy. Kami memutuskan setiap izin penilaian punya pasangan: `view-grades`/`manage-grades` (semua) dan `view-own-grades`/`manage-own-grades` (milik sendiri), begitu pula untuk absensi dan hafalan. Route menerima salah satu dari pasangan itu; service memeriksa: izin "semua" berarti tanpa pembatasan, hanya izin "milik sendiri" berarti data dibatasi Cakupan Mengajar, selain itu 403. Alternatif yang ditolak: (a) Policy per model — melanggar konvensi codebase dan menduplikasi Spatie; (b) membatasi berdasarkan nama role `ustadz` — salah untuk user multi-role dan menanam logika otorisasi di kode, bukan di data.

## Consequences

- Menambah peran baru (mis. `pengurus_pendidikan`, wali kelas) cukup lewat pemberian izin di seeder.
- Pembatasan cakupan dijalankan di satu tempat (resolver Cakupan Mengajar) yang dipanggil semua service penilaian; route middleware memakai `permission:a|b`.
- Frontend menentukan tampilan dari daftar permission, bukan dari nama role.
