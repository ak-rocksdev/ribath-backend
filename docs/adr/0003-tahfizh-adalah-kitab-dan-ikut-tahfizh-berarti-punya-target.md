---
status: accepted
date: 2026-09-12
---

# Tahfizh adalah sebuah Kitab; "ikut tahfizh" berarti punya Target Hafalan

Master kurikulum tidak punya kitab atau fann Tahfizh, kelas tahfidz di jadwal hanya diajar kitab teori, dan kolom `students.program` tidak konsisten dengan kelas. Agar mesin penilaian tetap seragam (setiap nilai milik satu Kitab dengan satu Template), kami memutuskan membuat fann `tahfizh` dan kitab "Tahfizh Al-Qur'an" bertemplate Tahfizh, dan mendefinisikan seorang santri ikut penilaian Tahfizh pada satu semester **jika dan hanya jika** ia punya Target Hafalan di semester itu. Alternatif yang ditolak: menurunkan keikutsertaan dari `students.program` atau dari kategori kelas, karena keduanya terbukti tidak mencerminkan kenyataan di data produksi.

## Consequences

- Kitab Tahfizh tidak perlu ada di `teaching_schedules`; ia masuk daftar kitab yang bisa dinilai lewat keberadaan target, bukan jadwal.
- Santri di kelas akademik pun bisa ikut tahfizh dengan cukup diberi target.
- Log setoran tanpa target menghasilkan faktor "Pencapaian target" kosong (NULL), bukan nol.
