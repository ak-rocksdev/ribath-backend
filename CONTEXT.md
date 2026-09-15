# Ribath Backend — Penilaian

Konteks penilaian akademik pesantren: nilai per kitab, absensi pertemuan, dan hafalan (tahfizh) yang bermuara ke rapor per semester. Kode memakai istilah Inggris; istilah kanonik di bawah adalah bahasa domain yang dipakai di UI, dokumen, dan diskusi.

## Language

### Waktu

**Tahun Ajaran** (`AcademicYear`):
Satu tahun akademik pesantren, berisi dua semester.
_Avoid_: tahun pelajaran, TA (dalam tulisan formal)

**Semester Akademik** (`AcademicSemester`):
Satu dari dua semester dalam sebuah Tahun Ajaran; satuan waktu tempat bobot penilaian, nilai, absensi, dan target hafalan hidup.
_Avoid_: periode, academic period (kata "periode" sudah berarti gelombang PSB)

### Kurikulum

**Fann** (`SubjectCategory`):
Rumpun ilmu yang mengelompokkan Kitab (Nahwu, Fiqh, Tafsir, …).
_Avoid_: kategori mapel, bidang studi

**Kitab** (`SubjectBook`):
Satuan pelajaran yang diajarkan dan dinilai; setiap Kitab mengikuti satu Template Penilaian.
_Avoid_: mapel, mata pelajaran, subject

**Jadwal Mengajar** (`TeachingSchedule`):
Penugasan tetap satu Ustadz mengajar satu Kitab ke satu Kelas pada hari dan jam tertentu dalam satu Semester Akademik. Satu-satunya sumber kebenaran tentang siapa mengajar apa ke kelas mana.

**Riwayat Pengajar** (`TeachingScheduleTeacherHistory`):
Catatan otomatis Ustadz, Kelas, dan Kitab yang dipegang sebuah Jadwal Mengajar sebelum salah satunya diubah (edit jadwal atau "ganti ustadz" massal), pada Semester Akademik jadwal itu. Membuat pasangan Kelas × Kitab tetap masuk Cakupan Mengajar Ustadz sebelumnya sampai Rapor difinalkan (ADR 0005).

**Kelas** (`ClassLevel`):
Tingkat tempat Santri belajar (Tamhidi, Ibtida 1, Tahfidz 1, …).
_Avoid_: tingkat, level, rombel

### Penilaian

**Template Penilaian** (`GradingTemplate`):
Rubrik yang menetapkan Faktor Penilaian apa saja yang berlaku untuk sebuah Kitab (Teori/Kitab, Tahfizh).
_Avoid_: rubrik, skema nilai

**Faktor Penilaian** (`GradingFactor`):
Satu unsur pembentuk nilai akhir (UTS, UAS, Tugas, Keaktifan, Adab, Absensi, …) dengan cara input tertentu dan bobot yang ditetapkan per Semester Akademik.
_Avoid_: komponen nilai, aspek

**Bobot** (`weight`):
Persentase kontribusi sebuah Faktor Penilaian dalam Template pada satu Semester Akademik. Bobot faktor yang aktif dinormalisasi agar berjumlah 100 %.

**Nilai** (`StudentGrade`):
Angka 0–100 hasil input manual untuk satu Santri, satu Kitab, satu Faktor, satu Semester Akademik. Kosong (NULL) berarti belum diinput, dan berbeda dari nol.
_Avoid_: skor (untuk nilai manual)

**Tugas** (`ClassTask`):
Pekerjaan yang diberikan ke satu Kelas untuk satu Kitab dan dinilai per Santri; Faktor Tugas adalah rata-rata seluruh Tugas dalam Semester Akademik.
_Avoid_: assignment, PR

**Belum Lengkap**:
Keadaan rekap ketika ada Faktor aktif yang nilainya masih kosong; menghalangi finalisasi Rapor.

**Rapor** (`ReportCard`):
Hasil penilaian seorang Santri untuk satu Semester Akademik yang, saat difinalkan, dibekukan sebagai snapshot dan tidak lagi dihitung ulang.
_Avoid_: laporan nilai, transkrip

### Absensi

**Pertemuan** (`ClassSession`):
Satu kejadian nyata sebuah Jadwal Mengajar pada satu tanggal; hanya Pertemuan yang tercatat yang masuk penyebut nilai absensi.
_Avoid_: sesi, kelas (dalam arti tatap muka)

**Pertemuan Dibatalkan** (`cancelled`):
Pertemuan yang dicatat sebagai tidak berlangsung (libur, ustadz berhalangan); tidak masuk penyebut dan tidak dianggap bolong.

**Pertemuan Bolong**:
Pertemuan terjadwal yang tanggalnya sudah lewat tetapi tidak pernah dicatat, baik hadir maupun dibatalkan. Memicu alert.

**Absensi** (`StudentAttendance`):
Status kehadiran satu Santri pada satu Pertemuan: hadir, sakit, izin, atau alpa. Sakit dan izin bersifat netral (tidak masuk penyebut).
_Avoid_: presensi, kehadiran (sebagai nama entitas)

### Tahfizh

**Target Hafalan** (`MemorizationTarget`):
Jumlah halaman yang harus disetor seorang Santri dalam satu Semester Akademik, ditetapkan per santri. Adanya Target Hafalan berarti santri itu ikut penilaian Tahfizh pada semester tersebut.

**Setoran** (`MemorizationLog`, `new`):
Penyetoran hafalan baru oleh Santri pada satu tanggal, diukur dalam halaman dan diberi nilai kualitas.
_Avoid_: setoran baru (cukup "Setoran"), ziyadah

**Murajaah** (`MemorizationLog`, `review`):
Penyetoran ulang hafalan lama untuk menjaga retensi, diberi nilai kualitas.
_Avoid_: review, pengulangan

**Halaman**:
Satuan dasar hafalan. Satu juz dihitung 20 halaman untuk konversi input; boleh setengah halaman.

### Orang

**Santri** (`Student`):
Murid pesantren.
_Avoid_: murid, siswa, student (di UI)

**Ustadz** (`Teacher`):
Pengajar yang tercantum di Jadwal Mengajar.
_Avoid_: guru (di UI), pengajar

**Akun Ustadz**:
User yang tertaut ke data Ustadz dan memegang role `ustadz`; dengannya Ustadz login dan bekerja di dalam Cakupan Mengajar-nya.
_Avoid_: akun guru, user pengajar

**Cakupan Mengajar**:
Semua pasangan Kelas × Kitab yang pernah dipegang seorang Ustadz di Jadwal Mengajar pada satu Semester Akademik (termasuk yang sudah dialihkan ke Ustadz lain), ditambah santri bimbingannya sebagai Pembimbing Tahfizh. Akun Ustadz hanya bisa melihat dan mengubah data penilaian di dalam cakupan ini.
_Avoid_: scope, kelas saya, hak akses guru

**Pembimbing Tahfizh**:
Ustadz yang ditetapkan pada Target Hafalan seorang Santri; ia menyimak Setoran dan Murajaah serta menginput UAS Tahfizh santri tersebut.
_Avoid_: musyrif, penyimak (sebagai nama peran)
