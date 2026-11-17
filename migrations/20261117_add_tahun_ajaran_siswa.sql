ALTER TABLE siswa
    ADD COLUMN tahun_ajaran VARCHAR(9) NULL AFTER id_kelas;

UPDATE siswa
LEFT JOIN kelas ON siswa.id_kelas = kelas.id_kelas
SET siswa.tahun_ajaran = kelas.tahun_ajaran
WHERE siswa.tahun_ajaran IS NULL;
