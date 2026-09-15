---
status: accepted
date: 2026-09-15
---

# Akses pengajar lama bertahan sampai rapor final, lewat riwayat pengajar

Jadwal Mengajar menyimpan satu ustadz per baris dan pergantian ustadz (edit jadwal, "ganti ustadz" massal) menimpa baris itu, sehingga jejak pengajar lama hilang. Pengurus ingin pengajar lama tetap bisa membantu pengajar baru karena referensi awalnya menjadi sumber kebenaran. Kami memutuskan mencatat riwayat pengajar secara otomatis setiap kali ustadz, kelas, atau kitab sebuah jadwal berubah, dan Cakupan Mengajar = jadwal saat ini (aktif maupun nonaktif) + riwayat pada Semester Akademik yang sama. Akses tidak berakhir pada tanggal tertentu; ia berakhir alami ketika Rapor santri difinalkan (penguncian ADR 0001). Alternatif yang ditolak: menurunkan jejak dari Pertemuan yang pernah dicatat (hilang bila pengajar lama belum mencatat absensi), dan akses tambahan manual per kelas (bergantung pada ingatan pengurus).

## Consequences

- Satu tabel riwayat baru ditulis oleh service Jadwal Mengajar; tidak ada perubahan pada tabel jadwal.
- Pengajar lama dan baru sama-sama bisa mengubah nilai pasangan itu; jejak siapa mengubah tetap di `created_by`/`updated_by`.
- Alert Pertemuan Bolong tetap hanya untuk pengajar jadwal saat ini.
