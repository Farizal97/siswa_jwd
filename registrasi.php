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

$tahun_result = mysqli_query($conn, "SELECT MAX(tahun_ajaran) AS tahun_ajaran FROM kelas");
if ($tahun_result === false) {
    fail_database_request('Informasi tahun ajaran gagal dimuat.');
}
$tahun_ajaran = mysqli_fetch_assoc($tahun_result)['tahun_ajaran'] ?: '-';
$academic_years = [];
for ($start_year = 2000; $start_year <= 2027; $start_year++) {
    $academic_years[] = sprintf('%d/%d', $start_year, $start_year + 1);
}
$default_tahun_ajaran = in_array($tahun_ajaran, $academic_years, true)
    ? $tahun_ajaran
    : end($academic_years);

$nis_result = mysqli_query(
    $conn,
    "SELECT MAX(CAST(nis AS UNSIGNED)) AS nis_terakhir
     FROM siswa
     WHERE nis REGEXP '^[0-9]+$'"
);
if ($nis_result === false) {
    fail_database_request('Nomor registrasi siswa gagal disiapkan.');
}
$nis_terakhir = (int) mysqli_fetch_assoc($nis_result)['nis_terakhir'];
$nis = (string) max($nis_terakhir + 1, (int) (date('Y') . '001'));

$error = '';
$values = [
    'nisn' => '',
    'nama_lengkap' => '',
    'tempat_lahir' => '',
    'tanggal_lahir' => '',
    'jenis_kelamin' => '',
    'tahun_ajaran' => $default_tahun_ajaran,
    'id_kelas' => '',
    'no_telepon' => '',
    'email_siswa' => '',
    'alamat' => '',
];

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
                ? "INSERT INTO siswa
                       (nis, nisn, nama_lengkap, jenis_kelamin, tempat_lahir, tanggal_lahir,
                        id_kelas, tahun_ajaran, alamat, no_telepon, email_siswa)
                   VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)"
                : "INSERT INTO siswa
                       (nis, nisn, nama_lengkap, jenis_kelamin, tempat_lahir, tanggal_lahir,
                        id_kelas, tahun_ajaran, alamat, no_telepon, email_siswa)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) {
                fail_database_request('Formulir siswa gagal disiapkan untuk disimpan.');
            }

            if ($id_kelas === null) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssssssssss',
                    $nis,
                    $values['nisn'],
                    $values['nama_lengkap'],
                    $values['jenis_kelamin'],
                    $values['tempat_lahir'],
                    $values['tanggal_lahir'],
                    $values['tahun_ajaran'],
                    $values['alamat'],
                    $values['no_telepon'],
                    $values['email_siswa']
                );
            } else {
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssssssissss',
                    $nis,
                    $values['nisn'],
                    $values['nama_lengkap'],
                    $values['jenis_kelamin'],
                    $values['tempat_lahir'],
                    $values['tanggal_lahir'],
                    $id_kelas,
                    $values['tahun_ajaran'],
                    $values['alamat'],
                    $values['no_telepon'],
                    $values['email_siswa']
                );
            }

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header('Location: index.php?pesan=tambah_sukses');
                exit;
            }

            if (mysqli_stmt_errno($stmt) === 1062) {
                $error = 'NISN tersebut sudah terdaftar. Periksa kembali NISN yang dimasukkan.';
            } else {
                $error = 'Data siswa gagal disimpan. Periksa kembali data dan coba lagi.';
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registrasi Siswa Baru - SiswaKita</title>
  <link rel="stylesheet" href="asssets/css/style.css">
</head>
<body class="registration-page">
  <aside class="sidebar">
    <div>
      <a class="brand-box" href="index.php">
        <span class="sidebar-brand-icon" aria-hidden="true">&#10022;</span>
        <span>
          <span class="brand-title">SiswaKita</span>
          <span class="brand-subtitle">Portal Akademik</span>
        </span>
      </a>

      <nav class="menu-section" aria-label="Menu utama">
        <p class="menu-heading">Menu Utama</p>
        <ul class="nav-list">
          <li><a href="index.php" class="nav-link-custom"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.25"></circle><path d="M5.5 19v-1.6a6.5 6.5 0 0 1 13 0V19h-13Z"></path></svg><span>Direktori Siswa</span></a></li>
          <li><a href="registrasi.php" class="nav-link-custom active" aria-current="page"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14"></path></svg><span>Registrasi Baru</span></a></li>
        </ul>
      </nav>
    </div>

    <div class="sidebar-footer">
      <div class="help-box">
        <span class="help-badge" aria-hidden="true">i</span>
        <div>
          <div class="help-title">Butuh bantuan?</div>
          <div class="help-subtitle">Hubungi tim akademik</div>
        </div>
      </div>
      <div class="admin-box">
        <div class="admin-avatar">AR</div>
        <div>
          <div class="admin-name">Admin Registrasi</div>
          <div class="admin-role">Administrator</div>
        </div>
      </div>
    </div>
  </aside>

  <div class="main-wrapper">
    <div>
      <header class="topbar">
        <nav class="breadcrumb-custom" aria-label="Breadcrumb">
          <a href="index.php">Portal Akademik</a>
          <span aria-hidden="true">&rsaquo;</span>
          <span class="breadcrumb-active">Registrasi Baru</span>
        </nav>
        <div class="tahun-box">
          <div class="tahun-label">Tahun Ajaran</div>
          <div class="tahun-value"><?= h($values['tahun_ajaran']) ?></div>
        </div>
      </header>

      <main class="content-container registration-content">
        <header class="registration-heading">
          <div class="page-eyebrow">Penerimaan Siswa</div>
          <h1 class="page-title">Registrasi Siswa Baru</h1>
          <p class="page-subtitle">Lengkapi formulir berikut untuk menambahkan data siswa ke sistem akademik.</p>
        </header>

        <?php if ($error !== ''): ?>
          <div class="registration-error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="registration-notice" aria-label="Informasi formulir">
          <span class="notice-icon" aria-hidden="true">&#43;</span>
          <div class="notice-copy">
            <h2>Data siswa baru</h2>
            <p>Pastikan informasi yang dimasukkan sesuai dokumen resmi.</p>
          </div>
          <span class="notice-tag"><span></span> Formulir baru</span>
        </section>

        <form class="registration-form" method="post" action="registrasi.php">
          <div class="registration-columns">
            <section class="form-panel personal-panel">
              <header class="panel-heading">
                <span class="section-number">01</span>
                <div>
                  <h2>Informasi pribadi</h2>
                  <p>Identitas dasar siswa sesuai dokumen kependudukan.</p>
                </div>
              </header>

              <div class="form-fields">
                <label class="form-field">
                  <span>Nama lengkap <b>*</b></span>
                  <input type="text" name="nama_lengkap" value="<?= h($values['nama_lengkap']) ?>" placeholder="Masukkan nama lengkap siswa" autocomplete="name" maxlength="150" required>
                </label>
                <label class="form-field">
                  <span>NISN <b>*</b></span>
                  <input type="text" name="nisn" value="<?= h($values['nisn']) ?>" placeholder="Nomor induk siswa nasional" maxlength="20" required>
                </label>
                <label class="form-field">
                  <span>NIS / Kode siswa</span>
                  <input type="text" value="<?= h($nis) ?>" readonly aria-label="NIS siswa yang akan dibuat">
                </label>
                <label class="form-field">
                  <span>Tempat lahir <b>*</b></span>
                  <input type="text" name="tempat_lahir" value="<?= h($values['tempat_lahir']) ?>" placeholder="Kota kelahiran" maxlength="50" required>
                </label>
                <label class="form-field">
                  <span>Tanggal lahir <b>*</b></span>
                  <input type="date" name="tanggal_lahir" value="<?= h($values['tanggal_lahir']) ?>" required>
                </label>
                <label class="form-field">
                  <span>Jenis kelamin <b>*</b></span>
                  <select name="jenis_kelamin" required>
                    <option value="" disabled <?= $values['jenis_kelamin'] === '' ? 'selected' : '' ?>>Pilih jenis kelamin</option>
                    <option value="L" <?= $values['jenis_kelamin'] === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                    <option value="P" <?= $values['jenis_kelamin'] === 'P' ? 'selected' : '' ?>>Perempuan</option>
                  </select>
                </label>
                <label class="form-field">
                  <span>Nomor telepon <b>*</b></span>
                  <input type="tel" name="no_telepon" value="<?= h($values['no_telepon']) ?>" placeholder="08xxxxxxxxxx" autocomplete="tel" maxlength="20" required>
                </label>
                <label class="form-field">
                  <span>Email aktif <b>*</b></span>
                  <input type="email" name="email_siswa" value="<?= h($values['email_siswa']) ?>" placeholder="nama@email.com" autocomplete="email" maxlength="100" required>
                </label>
                <label class="form-field form-field-wide">
                  <span>Alamat lengkap <b>*</b></span>
                  <textarea name="alamat" rows="3" placeholder="Jalan, nomor rumah, RT/RW, kelurahan, kecamatan..." autocomplete="street-address" required><?= h($values['alamat']) ?></textarea>
                </label>
              </div>
            </section>

            <div class="registration-side">
              <section class="form-panel academic-panel">
                <header class="panel-heading">
                  <span class="section-number">02</span>
                  <div>
                    <h2>Informasi akademik</h2>
                    <p>Penempatan dan status pendidikan siswa.</p>
                  </div>
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

              <section class="form-panel document-panel">
                <header class="panel-heading">
                  <span class="section-number">03</span>
                  <div>
                    <h2>Data tersimpan</h2>
                    <p>Data akan langsung ditambahkan ke direktori siswa.</p>
                  </div>
                </header>
                <div class="registration-save-note">
                  <span class="notice-icon" aria-hidden="true">&#10003;</span>
                  <div>
                    <strong>NIS dibuat otomatis</strong>
                    <p>Tahun ajaran dan data siswa disimpan; kelas dipilih jika tersedia.</p>
                  </div>
                </div>
              </section>
            </div>
          </div>

          <div class="form-actions">
            <span><b>*</b> Wajib diisi</span>
            <div>
              <a class="btn-secondary-custom" href="index.php">Batal</a>
              <button class="btn-primary-custom" type="submit"><span aria-hidden="true">&#10003;</span> Simpan siswa</button>
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
