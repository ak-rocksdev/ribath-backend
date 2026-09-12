---
status: accepted
date: 2026-09-12
---

# Rapor final adalah snapshot, bukan hasil hitung ulang

Nilai akhir per kitab dihitung dari sumber yang terus berubah: bobot per semester, log setoran, pertemuan yang dicatat belakangan, dan nilai manual yang dikoreksi. Kami memutuskan bahwa saat sebuah Rapor difinalkan, sistem menyimpan snapshot nilai tiap faktor, bobot ternormalisasi, dan nilai akhir per kitab ke tabel rapor, dan setelah itu rapor dibaca dari snapshot, bukan dihitung ulang. Alternatifnya (rapor selalu live, "final" hanya flag) lebih sederhana tetapi membuat rapor yang sudah dibagikan ke wali bisa berubah diam-diam.

## Consequences

- Semua kalkulator nilai (normalisasi bobot, absensi, hafalan, nilai akhir) harus berupa fungsi murni yang mengembalikan rincian lengkap, bukan hanya angka akhir, agar rinciannya bisa dibekukan.
- Sebelum final, rekap selalu dihitung live; perubahan bobot setelah nilai masuk diizinkan dengan peringatan.
- Pembatalan finalisasi hanya oleh super_admin dengan alasan wajib yang dicatat.
