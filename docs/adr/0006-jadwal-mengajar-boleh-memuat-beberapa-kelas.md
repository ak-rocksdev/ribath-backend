---
status: accepted
date: 2026-09-19
---

# Jadwal Mengajar boleh memuat beberapa Kelas

Satu pertemuan nyata kadang diikuti lebih dari satu kelas: Takmilah ba'da Isya diajar untuk Ibtida 2 dan Tsanawiyah 1 sekaligus. Model lama menyimpan satu Kelas per baris Jadwal Mengajar, sehingga kelas kedua tidak punya jadwal, tidak masuk Cakupan Mengajar ustadznya, dan santrinya tidak bisa diabsen. Membuat jadwal kedua pada jam yang sama ditolak — benar, karena seorang ustadz memang tidak bisa berada di dua tempat sekaligus.

Kami memutuskan Kelas sebuah Jadwal Mengajar menjadi himpunan, disimpan di tabel penghubung jadwal–kelas, dan kolom kelas tunggal dihapus lewat expand–contract. Satu pertemuan tetap satu baris Pertemuan; absensi dicatat sekali dengan santri dikelompokkan per kelas; Nilai, Tugas, rekap, dan Rapor tetap per Kelas seperti sebelumnya, karena nilai melekat pada santri di kelasnya.

Alternatif yang ditolak: (a) kelas induk dan anak (rombongan belajar bertingkat) — menambah konsep kedua yang harus dirawat di nilai, rapor, dan cakupan, padahal kebutuhan pemecahan kelas 1A/1B sudah selesai dengan Kelas biasa; (b) jadwal kembar yang ditandai satu grup — membuat Pertemuan, rekap kehadiran, dan alert terhitung dua kali; (c) kelas gabungan sebagai Kelas tersendiri dengan santri terdaftar di dua kelas — mematahkan asumsi satu santri satu kelas yang dipakai Rapor dan Keuangan.

## Consequences

- Cakupan Mengajar menghasilkan satu pasangan Kelas × Kitab untuk setiap kelas pada jadwal; ADR 0004 dan ADR 0005 tidak berubah artinya.
- Indeks unik parsial "satu kelas satu slot" tidak bisa ikut pindah ke tabel penghubung; penjagaan bentrok pindah ke layer service di dalam transaksi, dengan test. Jaring pengaman basis data untuk aturan itu hilang.
- Baris absensi santri menyimpan Kelas saat pencatatan, dan menjadi dasar rekap kehadiran per kelas; snapshot Kelas pada Pertemuan tinggal untuk tampilan dan data lama.
- Satu Tugas tetap milik satu Kelas; tugas yang sama untuk dua kelas dibuat dua kali.
