<?php
require_once 'config/koneksi.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function database_result($result)
{
    if ($result === false) {
        http_response_code(500);
        exit('Terjadi kesalahan saat mengambil data direktori siswa.');
    }

    return $result;
}

// Proses hapus data
if (isset($_GET['hapus'])) {
    $id = (int) $_GET['hapus'];
    $stmt = mysqli_prepare($conn, "DELETE FROM siswa WHERE id_siswa = ?");
    if (!$stmt) {
        http_response_code(500);
        exit('Terjadi kesalahan saat menyiapkan penghapusan data.');
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        http_response_code(500);
        exit('Data siswa gagal dihapus.');
    }
    mysqli_stmt_close($stmt);

    header('Location: index.php?pesan=hapus_sukses');
    exit;
}

$keyword = trim($_GET['cari'] ?? '');
$selected_jurusan = max(0, (int) ($_GET['jurusan'] ?? 0));
$academic_years = [];
for ($start_year = 2000; $start_year <= 2027; $start_year++) {
    $academic_years[] = sprintf('%d/%d', $start_year, $start_year + 1);
}
$selected_tahun = (string) ($_GET['tahun_ajaran'] ?? '');
if (!in_array($selected_tahun, $academic_years, true)) {
    $selected_tahun = '';
}
$search_param = '%' . $keyword . '%';
$per_page = 6;
$page = max(1, (int) ($_GET['halaman'] ?? 1));

$stats_result = database_result(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total_siswa,
            COALESCE(SUM(status_siswa = 'Aktif'), 0) AS siswa_aktif
     FROM siswa"
));
$stats = mysqli_fetch_assoc($stats_result);

$program_result = database_result(mysqli_query($conn, "SELECT COUNT(*) AS total_jurusan FROM jurusan"));
$total_jurusan = (int) mysqli_fetch_assoc($program_result)['total_jurusan'];

$tahun_ajaran = $selected_tahun !== '' ? $selected_tahun : 'Semua Tahun';

$jurusan_result = database_result(mysqli_query(
    $conn,
    "SELECT id_jurusan, nama_jurusan FROM jurusan ORDER BY nama_jurusan"
));
$daftar_jurusan = mysqli_fetch_all($jurusan_result, MYSQLI_ASSOC);

$from_sql = "FROM siswa
             LEFT JOIN kelas ON siswa.id_kelas = kelas.id_kelas
             LEFT JOIN jurusan ON kelas.id_jurusan = jurusan.id_jurusan
             WHERE (? = '' OR siswa.nama_lengkap LIKE ? OR siswa.nis LIKE ?
                    OR siswa.nisn LIKE ? OR siswa.alamat LIKE ?)
               AND (? = 0 OR jurusan.id_jurusan = ?)
               AND (? = '' OR COALESCE(siswa.tahun_ajaran, kelas.tahun_ajaran) = ?)";

$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total " . $from_sql);
if (!$count_stmt) {
    http_response_code(500);
    exit('Terjadi kesalahan saat menyiapkan pencarian data siswa.');
}
mysqli_stmt_bind_param(
    $count_stmt,
    'sssssiiss',
    $keyword,
    $search_param,
    $search_param,
    $search_param,
    $search_param,
    $selected_jurusan,
    $selected_jurusan,
    $selected_tahun,
    $selected_tahun
);
if (!mysqli_stmt_execute($count_stmt)) {
    mysqli_stmt_close($count_stmt);
    http_response_code(500);
    exit('Pencarian data siswa gagal dijalankan.');
}
$total_filtered = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt))['total'];
mysqli_stmt_close($count_stmt);

$total_pages = max(1, (int) ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$student_sql = "SELECT siswa.*, kelas.nama_kelas,
                       COALESCE(siswa.tahun_ajaran, kelas.tahun_ajaran) AS tahun_ajaran,
                       jurusan.nama_jurusan, jurusan.kode_jurusan
                " . $from_sql . "
                ORDER BY siswa.id_siswa DESC
                LIMIT ? OFFSET ?";
$student_stmt = mysqli_prepare($conn, $student_sql);
if (!$student_stmt) {
    http_response_code(500);
    exit('Terjadi kesalahan saat menyiapkan daftar siswa.');
}
mysqli_stmt_bind_param(
    $student_stmt,
    'sssssiissii',
    $keyword,
    $search_param,
    $search_param,
    $search_param,
    $search_param,
    $selected_jurusan,
    $selected_jurusan,
    $selected_tahun,
    $selected_tahun,
    $per_page,
    $offset
);
if (!mysqli_stmt_execute($student_stmt)) {
    mysqli_stmt_close($student_stmt);
    http_response_code(500);
    exit('Daftar siswa gagal dimuat.');
}
$daftar_siswa = mysqli_fetch_all(mysqli_stmt_get_result($student_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($student_stmt);

$notifikasi = ($_GET['pesan'] ?? '') === 'edit_sukses'
    ? 'Data siswa berhasil diperbarui.'
    : '';
$first_result = $total_filtered === 0 ? 0 : $offset + 1;
$last_result = min($offset + count($daftar_siswa), $total_filtered);
$page_url = function ($page_number) use ($keyword, $selected_jurusan, $selected_tahun) {
    return 'index.php?' . http_build_query([
        'cari' => $keyword,
        'jurusan' => $selected_jurusan,
        'tahun_ajaran' => $selected_tahun,
        'halaman' => $page_number,
    ]);
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Direktori Siswa - SiswaKita | Portal Akademik</title>
  <meta name="description" content="Kelola dan cari data induk siswa secara terpusat melalui Direktori Siswa SiswaKita.">
  <link rel="stylesheet" href="asssets/css/bootstrap.min.css?v=1">
  <link rel="stylesheet" href="asssets/css/style.css?v=20261117">
</head>
<body>
  <aside class="sidebar">
    <div>
      <a class="brand-box" href="index.php" style="color: inherit; text-decoration: none;">
        <div class="sidebar-brand-icon" aria-hidden="true">&#10022;</div>
        <div>
          <div class="brand-title">SiswaKita</div>
          <div class="brand-subtitle">Portal Akademik</div>
        </div>
      </a>

      <div class="menu-section">
        <p class="menu-heading">Menu Utama</p>
        <ul class="nav-list">
          <li><a href="index.php" class="nav-link-custom active" aria-current="page"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19v-1.5a5.5 5.5 0 0 1 11 0V19h-11ZM16 5.5a3 3 0 0 1 0 5.8M18 14a5 5 0 0 1 2.5 4.3V19H17"></path></svg><span>Direktori Siswa</span></a></li>
          <li><a href="registrasi.php" class="nav-link-custom"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14"></path></svg><span>Registrasi Baru</span></a></li>
        </ul>
      </div>
    </div>

    <div class="sidebar-footer">
      <div class="help-box">
        <div class="help-badge"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.25"></circle><path d="M8 7.25v4M8 4.75h.01"></path></svg></div>
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
          <span>Portal Akademik</span>
          <svg class="ui-icon breadcrumb-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m6 3 5 5-5 5"></path></svg>
          <span class="breadcrumb-active">Direktori Siswa</span>
        </nav>
        <div class="tahun-box">
          <div class="tahun-label">Tahun Ajaran</div>
          <div class="tahun-value"><?= h($tahun_ajaran) ?></div>
        </div>
      </header>

      <main class="content-container">
        <div class="page-header">
          <div>
            <div class="page-eyebrow">Data Siswa</div>
            <h1 class="page-title">Direktori Siswa</h1>
            <p class="page-subtitle">Kelola dan temukan seluruh data siswa dalam satu tempat.</p>
          </div>
          <a href="registrasi.php" class="btn-primary-custom"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14"></path></svg>Tambah siswa</a>
        </div>

        <?php if ($notifikasi !== ''): ?>
          <div id="directoryNotice" class="directory-notice" role="status" aria-live="polite">
            <span class="directory-notice-icon" aria-hidden="true">&#10003;</span>
            <span><?= h($notifikasi) ?></span>
            <button type="button" class="directory-notice-close" aria-label="Tutup notifikasi"><span aria-hidden="true">&times;</span></button>
          </div>
        <?php endif; ?>

        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon icon-blue"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19v-1.5a5.5 5.5 0 0 1 11 0V19h-11ZM16 5.5a3 3 0 0 1 0 5.8M18 14a5 5 0 0 1 2.5 4.3V19H17"></path></svg></div>
            <div><div class="stat-number"><?= number_format((int) $stats['total_siswa'], 0, ',', '.') ?></div><div class="stat-label">Total siswa</div></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon icon-green"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="m5 12.5 4.5 4.5L19 7"></path></svg></div>
            <div><div class="stat-number"><?= number_format((int) $stats['siswa_aktif'], 0, ',', '.') ?></div><div class="stat-label">Siswa aktif</div></div>
          </div>
          <div class="stat-card">
            <div class="stat-icon icon-amber"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M12 7v14M12 7C10.7 5.7 8.8 5 6 5H3v14h4c2.2 0 3.8.6 5 2m0-14c1.3-1.3 3.2-2 6-2h3v14h-4c-2.2 0-3.8.6-5 2"></path></svg></div>
            <div><div class="stat-number"><?= number_format($total_jurusan, 0, ',', '.') ?></div><div class="stat-label">Program keahlian</div></div>
          </div>
        </div>

        <section class="table-card" aria-label="Daftar siswa">
          <form id="directoryFilters" class="filter-bar" method="get" action="index.php">
            <input id="directorySearch" type="search" name="cari" class="search-input" placeholder="Cari nama, kode, atau alamat..." value="<?= h($keyword) ?>" aria-label="Cari siswa">
            <select name="jurusan" class="jurusan-select" aria-label="Filter jurusan" onchange="this.form.submit()">
              <option value="0">Semua jurusan</option>
              <?php foreach ($daftar_jurusan as $jurusan): ?>
                <option value="<?= (int) $jurusan['id_jurusan'] ?>" <?= $selected_jurusan === (int) $jurusan['id_jurusan'] ? 'selected' : '' ?>><?= h($jurusan['nama_jurusan']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="tahun_ajaran" class="jurusan-select" aria-label="Filter tahun ajaran" onchange="this.form.submit()">
              <option value="">Semua tahun</option>
              <?php foreach ($academic_years as $academic_year): ?>
                <option value="<?= h($academic_year) ?>" <?= $selected_tahun === $academic_year ? 'selected' : '' ?>><?= h($academic_year) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="halaman" value="1">
          </form>

          <div class="table-responsive">
            <table class="table-custom">
              <thead>
                <tr>
                  <th>Siswa</th>
                  <th>Kode</th>
                  <th>Domisili</th>
                  <th>Tanggal lahir</th>
                  <th>Jurusan</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($daftar_siswa): ?>
                  <?php foreach ($daftar_siswa as $row): ?>
                    <?php
                      $nama_parts = preg_split('/\s+/', trim($row['nama_lengkap']));
                      $initials = strtoupper(substr($nama_parts[0] ?? '?', 0, 1) . substr($nama_parts[1] ?? '', 0, 1));
                      $avatar_colors = ['av-blue', 'av-sky', 'av-blue', 'av-amber'];
                      $avatar_class = $avatar_colors[(int) $row['id_siswa'] % count($avatar_colors)];
                      $status = $row['status_siswa'] ?: 'Nonaktif';
                      $status_class = $status === 'Aktif' ? 'badge-aktif' : 'badge-nonaktif';
                      $dot_class = $status === 'Aktif' ? 'dot-green' : 'dot-gray';
                      $birth_date = '-';
                      if (!empty($row['tanggal_lahir']) && $row['tanggal_lahir'] !== '0000-00-00') {
                          $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                          $birth_timestamp = strtotime($row['tanggal_lahir']);
                          $birth_date = date('d', $birth_timestamp) . ' ' . $months[(int) date('n', $birth_timestamp)] . ' ' . date('Y', $birth_timestamp);
                      }
                      $angkatan = substr((string) ($row['tahun_ajaran'] ?? ''), 0, 4);
                      if (!preg_match('/^\d{4}$/', $angkatan)) {
                          $angkatan = '';
                      }
                    ?>
                    <tr class="student-row">
                      <td>
                        <div class="student-info">
                          <div class="avatar-circle <?= h($avatar_class) ?>"><?= h($initials) ?></div>
                          <div>
                            <div class="student-name"><?= h($row['nama_lengkap']) ?></div>
                            <div class="student-angkatan"><?= $angkatan !== '' ? 'Angkatan ' . h($angkatan) : h($row['nama_kelas'] ?? 'Siswa') ?></div>
                          </div>
                        </div>
                      </td>
                      <td><?= h($row['nis']) ?></td>
                      <td><?= h($row['alamat'] ?: '-') ?></td>
                      <td><?= h($birth_date) ?></td>
                      <td><strong><?= h($row['nama_jurusan'] ?? 'Belum diatur') ?></strong></td>
                      <td><span class="<?= h($status_class) ?>"><span class="<?= h($dot_class) ?>"></span><?= h($status) ?></span></td>
                      <td class="text-nowrap">
                        <a href="edit.php?id=<?= (int) $row['id_siswa'] ?>" class="btn btn-sm btn-warning">Edit</a>
                        <a href="index.php?hapus=<?= (int) $row['id_siswa'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus data siswa ini?')">Hapus</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="7" class="empty-state">Data siswa tidak ditemukan.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="table-footer">
            <div>Menampilkan <strong><?= $first_result ?>–<?= $last_result ?></strong> dari <strong><?= number_format($total_filtered, 0, ',', '.') ?></strong> siswa</div>
            <?php if ($total_filtered > 0): ?>
              <nav aria-label="Navigasi halaman">
                <ul class="pagination-list">
                  <li>
                    <?php if ($page > 1): ?>
                      <a href="<?= h($page_url($page - 1)) ?>" class="page-btn" aria-label="Halaman sebelumnya"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m10 3-5 5 5 5"></path></svg></a>
                    <?php else: ?>
                      <span class="page-btn disabled" aria-disabled="true"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m10 3-5 5 5 5"></path></svg></span>
                    <?php endif; ?>
                  </li>
                  <?php
                    $pages = array_values(array_unique(array_filter([1, $page - 1, $page, $page + 1, $total_pages], function ($number) use ($total_pages) {
                        return $number >= 1 && $number <= $total_pages;
                    })));
                    sort($pages);
                    $previous_page = 0;
                  ?>
                  <?php foreach ($pages as $page_number): ?>
                    <?php if ($previous_page > 0 && $page_number > $previous_page + 1): ?><li><span class="page-btn" aria-hidden="true">...</span></li><?php endif; ?>
                    <li><a href="<?= h($page_url($page_number)) ?>" class="page-btn <?= $page_number === $page ? 'active' : '' ?>" <?= $page_number === $page ? 'aria-current="page"' : '' ?>><?= $page_number ?></a></li>
                    <?php $previous_page = $page_number; ?>
                  <?php endforeach; ?>
                  <li>
                    <?php if ($page < $total_pages): ?>
                      <a href="<?= h($page_url($page + 1)) ?>" class="page-btn" aria-label="Halaman berikutnya"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m6 3 5 5-5 5"></path></svg></a>
                    <?php else: ?>
                      <span class="page-btn disabled" aria-disabled="true"><svg class="ui-icon" aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m6 3 5 5-5 5"></path></svg></span>
                    <?php endif; ?>
                  </li>
                </ul>
              </nav>
            <?php endif; ?>
          </div>
        </section>
      </main>
    </div>

    <footer class="footer-custom">
      <span>&copy; <?= date('Y') ?> SiswaKita</span>
      <span>Sistem Informasi Akademik &bull; v1.0</span>
    </footer>
  </div>
  <script>
    const directoryForm = document.getElementById('directoryFilters');
    const directorySearch = document.getElementById('directorySearch');
    let searchTimer;

    directorySearch.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        directoryForm.submit();
      }, 350);
    });

    const directoryNotice = document.getElementById('directoryNotice');
    if (directoryNotice) {
      const currentUrl = new URL(window.location.href);
      currentUrl.searchParams.delete('pesan');
      window.history.replaceState(null, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);

      const dismissNotice = function () {
        directoryNotice.classList.add('is-hidden');
        window.setTimeout(function () {
          directoryNotice.remove();
        }, 200);
      };

      directoryNotice.querySelector('.directory-notice-close').addEventListener('click', dismissNotice);
      window.setTimeout(dismissNotice, 4000);
    }
  </script>
</body>
</html>
