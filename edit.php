<?php
require_once 'config/koneksi.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fail_database_request(string $message): void
{
    http_response_code(500);
    exit($message);
}

$id = max(0, (int) ($_GET['id'] ?? 0));
if ($id === 0) {
    http_response_code(400);
    exit('ID siswa tidak valid.');
}

$kelas_result = mysqli_query(
    $conn,
    "SELECT kelas.id_kelas, kelas.nama_kelas, kelas.tingkat, kelas.tahun_ajaran,
            jurusan.nama_jurusan
     FROM kelas
     LEFT JOIN jurusan ON kelas.id_jurusan = jurusan.id_jurusan
     ORDER BY kelas.tahun_ajaran DESC, kelas.tingkat, kelas.nama_kelas"
);
if ($kelas_result === false) {
    fail_database_request('Daftar kelas gagal dimuat.');
}
$daftar_kelas = mysqli_fetch_all($kelas_result, MYSQLI_ASSOC);

$stmt = mysqli_prepare($conn, 'SELECT * FROM siswa WHERE id_siswa = ?');
if (!$stmt) {
    fail_database_request('Data siswa gagal disiapkan untuk diedit.');
}
mysqli_stmt_bind_param($stmt, 'i', $id);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    fail_database_request('Data siswa gagal dimuat.');
}
$siswa = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$siswa) {
    http_response_code(404);
    exit('Data siswa tidak ditemukan.');
}

$academic_years = [];
for ($start_year = 2000; $start_year <= 2027; $start_year++) {
    $academic_years[] = sprintf('%d/%d', $start_year, $start_year + 1);
}
$tahun_ajaran_kelas = '';
foreach ($daftar_kelas as $kelas) {
    if ((int) $kelas['id_kelas'] === (int) $siswa['id_kelas']) {
        $tahun_ajaran_kelas = $kelas['tahun_ajaran'];
        break;
    }
}
$tahun_ajaran_default = $siswa['tahun_ajaran'] ?: $tahun_ajaran_kelas;
if (!in_array($tahun_ajaran_default, $academic_years, true)) {
    $tahun_ajaran_default = end($academic_years);
}

$values = [
    'nisn' => $siswa['nisn'],
    'nama_lengkap' => $siswa['nama_lengkap'],
    'tempat_lahir' => $siswa['tempat_lahir'],
    'tanggal_lahir' => $siswa['tanggal_lahir'],
    'jenis_kelamin' => $siswa['jenis_kelamin'],
    'tahun_ajaran' => $tahun_ajaran_default,
    'id_kelas' => (string) ($siswa['id_kelas'] ?? ''),
    'no_telepon' => $siswa['no_telepon'] ?? '',
    'email_siswa' => $siswa['email_siswa'] ?? '',
    'alamat' => $siswa['alamat'] ?? '',
];
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($values as $field => $default) {
        $values[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    $id_kelas = $values['id_kelas'] === '' ? null : filter_var($values['id_kelas'], FILTER_VALIDATE_INT);
    $birth_date = DateTime::createFromFormat('!Y-m-d', $values['tanggal_lahir']);
    $valid_birth_date = $birth_date && $birth_date->format('Y-m-d') === $values['tanggal_lahir'];

    if ($values['nisn'] === '' || $values['nama_lengkap'] === '' || $values['tempat_lahir'] === ''
        || !$valid_birth_date || !in_array($values['jenis_kelamin'], ['L', 'P'], true)
        || !in_array($values['tahun_ajaran'], $academic_years, true)
        || ($values['id_kelas'] !== '' && !$id_kelas) || $values['no_telepon'] === ''
        || !filter_var($values['email_siswa'], FILTER_VALIDATE_EMAIL) || $values['alamat'] === '') {
        $error = 'Lengkapi semua kolom wajib dengan data yang valid.';
    } else {
        $kelas_valid = true;
        if ($id_kelas !== null) {
            $kelas_stmt = mysqli_prepare($conn, 'SELECT id_kelas FROM kelas WHERE id_kelas = ? AND tahun_ajaran = ?');
            if (!$kelas_stmt) {
                fail_database_request('Validasi kelas gagal disiapkan.');
            }
            mysqli_stmt_bind_param($kelas_stmt, 'is', $id_kelas, $values['tahun_ajaran']);
            if (!mysqli_stmt_execute($kelas_stmt)) {
                mysqli_stmt_close($kelas_stmt);
                fail_database_request('Validasi kelas gagal dijalankan.');
            }
            $kelas_valid = mysqli_fetch_assoc(mysqli_stmt_get_result($kelas_stmt)) !== null;
            mysqli_stmt_close($kelas_stmt);
        }

        if (!$kelas_valid) {
            $error = 'Kelas yang dipilih tidak sesuai dengan tahun ajaran.';
        } else {
            $sql = $id_kelas === null
                ? "UPDATE siswa
                   SET nisn = ?, nama_lengkap = ?, jenis_kelamin = ?, tempat_lahir = ?,
                       tanggal_lahir = ?, id_kelas = NULL, tahun_ajaran = ?, alamat = ?,
                       no_telepon = ?, email_siswa = ?
                   WHERE id_siswa = ?"
                : "UPDATE siswa
                   SET nisn = ?, nama_lengkap = ?, jenis_kelamin = ?, tempat_lahir = ?,
                       tanggal_lahir = ?, id_kelas = ?, tahun_ajaran = ?, alamat = ?,
                       no_telepon = ?, email_siswa = ?
                   WHERE id_siswa = ?";
        $update_stmt = mysqli_prepare($conn, $sql);
        if (!$update_stmt) {
            fail_database_request('Perubahan data siswa gagal disiapkan.');
        }
        if ($id_kelas === null) {
            mysqli_stmt_bind_param(
                $update_stmt,
                'sssssssssi',
                $values['nisn'],
                $values['nama_lengkap'],
                $values['jenis_kelamin'],
                $values['tempat_lahir'],
                $values['tanggal_lahir'],
                $values['tahun_ajaran'],
                $values['alamat'],
                $values['no_telepon'],
                $values['email_siswa'],
                $id
            );
        } else {
            mysqli_stmt_bind_param(
                $update_stmt,
                'sssssissssi',
                $values['nisn'],
                $values['nama_lengkap'],
                $values['jenis_kelamin'],
                $values['tempat_lahir'],
                $values['tanggal_lahir'],
                $id_kelas,
                $values['tahun_ajaran'],
                $values['alamat'],
                $values['no_telepon'],
                $values['email_siswa'],
                $id
            );
        }

        if (mysqli_stmt_execute($update_stmt)) {
            mysqli_stmt_close($update_stmt);
            header('Location: index.php?pesan=edit_sukses');
            exit;
        }

        $error = mysqli_stmt_errno($update_stmt) === 1062
            ? 'NISN tersebut sudah digunakan siswa lain.'
            : 'Perubahan data gagal disimpan. Periksa kembali data dan coba lagi.';
        mysqli_stmt_close($update_stmt);
            }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Data Siswa - SiswaKita</title>
  <link rel="stylesheet" href="asssets/css/style.css">
</head>
<body class="registration-page">
  <aside class="sidebar">
    <div>
      <a class="brand-box" href="index.php">
        <span class="sidebar-brand-icon" aria-hidden="true">&#10022;</span>
        <span><span class="brand-title">SiswaKita</span><span class="brand-subtitle">Portal Akademik</span></span>
      </a>
      <nav class="menu-section" aria-label="Menu utama">
        <p class="menu-heading">Menu Utama</p>
        <ul class="nav-list">
          <li><a href="index.php" class="nav-link-custom active"><span>Direktori Siswa</span></a></li>
          <li><a href="registrasi.php" class="nav-link-custom"><span>Registrasi Baru</span></a></li>
        </ul>
      </nav>
    </div>
    <div class="sidebar-footer">
      <div class="help-box">
        <span class="help-badge" aria-hidden="true">i</span>
        <div><div class="help-title">Butuh bantuan?</div><div class="help-subtitle">Hubungi tim akademik</div></div>
      </div>
      <div class="admin-box">
        <div class="admin-avatar">AR</div>
        <div><div class="admin-name">Admin Registrasi</div><div class="admin-role">Administrator</div></div>
      </div>
    </div>
  </aside>

  <div class="main-wrapper">
    <div>
      <header class="topbar">
        <nav class="breadcrumb-custom" aria-label="Breadcrumb">
          <a href="index.php">Portal Akademik</a><span aria-hidden="true">&rsaquo;</span>
          <span class="breadcrumb-active">Edit Data Siswa</span>
        </nav>
        <div class="tahun-box"><div class="tahun-label">Tahun Ajaran</div><div class="tahun-value"><?= h($values['tahun_ajaran']) ?></div></div>
      </header>

      <main class="content-container registration-content">
        <header class="registration-heading">
          <div class="page-eyebrow">Data Siswa</div>
          <h1 class="page-title">Edit Data Siswa</h1>
          <p class="page-subtitle">Perbarui informasi siswa dan simpan perubahan ke direktori.</p>
        </header>
        <?php if ($error !== ''): ?>
          <div class="registration-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form class="registration-form" method="post" action="edit.php?id=<?= $id ?>">
          <div class="registration-columns">
            <section class="form-panel personal-panel">
              <header class="panel-heading">
                <span class="section-number">01</span>
                <div><h2>Informasi pribadi</h2><p>Perbarui data dasar siswa.</p></div>
              </header>
              <div class="form-fields">
                <label class="form-field"><span>Nama lengkap <b>*</b></span><input type="text" name="nama_lengkap" value="<?= h($values['nama_lengkap']) ?>" maxlength="150" required></label>
                <label class="form-field"><span>NIS / Kode siswa</span><input type="text" value="<?= h($siswa['nis']) ?>" readonly></label>
                <label class="form-field"><span>NISN <b>*</b></span><input type="text" name="nisn" value="<?= h($values['nisn']) ?>" maxlength="20" required></label>
                <label class="form-field"><span>Tempat lahir <b>*</b></span><input type="text" name="tempat_lahir" value="<?= h($values['tempat_lahir']) ?>" maxlength="50" required></label>
                <label class="form-field"><span>Tanggal lahir <b>*</b></span><input type="date" name="tanggal_lahir" value="<?= h($values['tanggal_lahir']) ?>" required></label>
                <label class="form-field">
                  <span>Jenis kelamin <b>*</b></span>
                  <select name="jenis_kelamin" required>
                    <option value="L" <?= $values['jenis_kelamin'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                    <option value="P" <?= $values['jenis_kelamin'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                  </select>
                </label>
                <label class="form-field"><span>Nomor telepon <b>*</b></span><input type="tel" name="no_telepon" value="<?= h($values['no_telepon']) ?>" maxlength="20" required></label>
                <label class="form-field"><span>Email aktif <b>*</b></span><input type="email" name="email_siswa" value="<?= h($values['email_siswa']) ?>" maxlength="100" required></label>
                <label class="form-field form-field-wide"><span>Alamat lengkap <b>*</b></span><textarea name="alamat" rows="3" required><?= h($values['alamat']) ?></textarea></label>
              </div>
            </section>

            <div class="registration-side">
              <section class="form-panel academic-panel">
                <header class="panel-heading">
                  <span class="section-number">02</span>
                  <div><h2>Informasi akademik</h2><p>Penempatan kelas siswa.</p></div>
                </header>
                <div class="form-fields form-fields-single">
                  <label class="form-field">
                    <span>Tahun ajaran <b>*</b></span>
                    <select id="academicYear" name="tahun_ajaran" required>
                      <?php foreach ($academic_years as $academic_year): ?>
                        <option value="<?= h($academic_year) ?>" <?= $values['tahun_ajaran'] === $academic_year ? 'selected' : '' ?>><?= h($academic_year) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="form-field">
                    <span>Kelas / Program keahlian</span>
                    <select id="academicClass" name="id_kelas">
                      <option value="">Tanpa kelas (opsional)</option>
                      <?php foreach ($daftar_kelas as $kelas): ?>
                        <?php
                          $kelas_label = $kelas['tahun_ajaran'] . ' - ' . $kelas['nama_kelas'];
                          if (!empty($kelas['nama_jurusan'])) {
                              $kelas_label .= ' (' . $kelas['nama_jurusan'] . ')';
                          }
                        ?>
                        <option value="<?= (int) $kelas['id_kelas'] ?>" data-tahun="<?= h($kelas['tahun_ajaran']) ?>" <?= $values['id_kelas'] === (string) $kelas['id_kelas'] ? 'selected' : '' ?>><?= h($kelas_label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>Kelas yang tersedia mengikuti tahun ajaran dan dapat dikosongkan jika belum tersedia.</small>
                  </label>
                </div>
              </section>
            </div>
          </div>
          <div class="form-actions">
            <span><b>*</b> Wajib diisi</span>
            <div>
              <a class="btn-secondary-custom" href="index.php">Batal</a>
              <button class="btn-primary-custom" type="submit"><span aria-hidden="true">&#10003;</span> Simpan perubahan</button>
            </div>
          </div>
        </form>
      </main>
    </div>
    <footer class="footer-custom">
      <span>&copy; <?= date('Y') ?> SiswaKita</span>
      <span>Sistem Informasi Akademik &bull; v1.0</span>
    </footer>
  </div>
  <script>
    const academicYear = document.getElementById('academicYear');
    const academicClass = document.getElementById('academicClass');

    function filterClassesByYear() {
      Array.from(academicClass.options).forEach(function (option) {
        const available = !option.value || option.dataset.tahun === academicYear.value;
        option.hidden = !available;
        option.disabled = !available;
      });

      const selectedClass = academicClass.options[academicClass.selectedIndex];
      if (selectedClass && selectedClass.dataset.tahun !== academicYear.value) {
        academicClass.value = '';
      }
    }

    academicYear.addEventListener('change', filterClassesByYear);
    filterClassesByYear();
  </script>
</body>
</html>
