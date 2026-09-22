<?php
session_start();
$loggedInUser = $_SESSION['user'] ?? null;

$dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($dataDirectory)) {
  mkdir($dataDirectory, 0755, true);
}
if (!is_dir($uploadDirectory)) {
  mkdir($uploadDirectory, 0755, true);
}

$database = new PDO('sqlite:' . $dataDirectory . DIRECTORY_SEPARATOR . 'laporan.sqlite');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE IF NOT EXISTS laporan (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nama TEXT NOT NULL,
  tanggal TEXT NOT NULL,
  kelas TEXT NOT NULL,
  kategori TEXT NOT NULL,
  berat REAL NOT NULL,
  gambar TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$kategoriLabels = [
  'organik' => 'Sampah Organik',
  'anorganik' => 'Sampah Anorganik',
  'residu' => 'Sampah Residu',
  'b3' => 'Sampah B3'
];
$message = null;
$messageType = 'success';

function cleanInput(string $value): string
{
  return trim($value);
}

function normalizeCategory(string $value, array $kategoriLabels): ?string
{
  $value = strtolower(trim($value));
  $value = preg_replace('/^sampah\s+/', '', $value);
  foreach ($kategoriLabels as $key => $label) {
    if ($value === $key || $value === strtolower($label) || $value === strtolower(preg_replace('/^Sampah\s+/', '', $label))) {
      return $key;
    }
  }
  return null;
}

function saveReport(PDO $database, array $report): void
{
  $statement = $database->prepare('INSERT INTO laporan (nama, tanggal, kelas, kategori, berat, gambar) VALUES (?, ?, ?, ?, ?, ?)');
  $statement->execute([$report['nama'], $report['tanggal'], $report['kelas'], $report['kategori'], $report['berat'], $report['gambar']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (($_POST['action'] ?? '') === 'save_report') {
      $report = [
        'nama' => cleanInput($_POST['nama'] ?? ''),
        'tanggal' => cleanInput($_POST['tanggal'] ?? ''),
        'kelas' => cleanInput($_POST['kelas'] ?? ''),
        'kategori' => normalizeCategory($_POST['kategori'] ?? '', $kategoriLabels),
        'berat' => filter_var($_POST['berat'] ?? null, FILTER_VALIDATE_FLOAT),
        'gambar' => null
      ];
      if ($report['nama'] === '' || $report['tanggal'] === '' || $report['kelas'] === '' || $report['kategori'] === null || $report['berat'] === false || $report['berat'] <= 0) {
        throw new RuntimeException('Lengkapi semua data laporan dengan nilai yang valid.');
      }
      if (!empty($_FILES['gambar']['name'])) {
        if ($_FILES['gambar']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['gambar']['tmp_name'])) {
          throw new RuntimeException('Foto gagal diunggah.');
        }
        $extension = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
          throw new RuntimeException('Format foto harus JPG, PNG, GIF, atau WEBP.');
        }
        $fileName = bin2hex(random_bytes(12)) . '.' . $extension;
        move_uploaded_file($_FILES['gambar']['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName);
        $report['gambar'] = 'uploads/' . $fileName;
      }
      saveReport($database, $report);
      $message = 'Laporan berhasil disimpan ke database.';
    }

  } catch (Throwable $error) {
    if ($database->inTransaction()) {
      $database->rollBack();
    }
    $message = $error->getMessage();
    $messageType = 'error';
  }
}

$filterDate = cleanInput($_GET['filter_tanggal'] ?? '');
$filterClass = cleanInput($_GET['filter_kelas'] ?? '');
$totalsQuery = 'SELECT kategori, SUM(berat) AS total FROM laporan WHERE 1 = 1';
$totalsParameters = [];
if ($filterClass !== '') {
  $totalsQuery .= ' AND kelas = :kelas';
  $totalsParameters[':kelas'] = $filterClass;
}
if ($filterDate !== '') {
  $totalsQuery .= ' AND tanggal = :tanggal';
  $totalsParameters[':tanggal'] = $filterDate;
}
$totalsQuery .= ' GROUP BY kategori';
$totalsStatement = $database->prepare($totalsQuery);
$totalsStatement->execute($totalsParameters);
$totals = array_fill_keys(array_keys($kategoriLabels), 0.0);
foreach ($totalsStatement as $row) {
  if (isset($totals[$row['kategori']])) {
    $totals[$row['kategori']] = (float) $row['total'];
  }
}

if (($_GET['ajax'] ?? '') === 'chart') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['totals' => array_values($totals)], JSON_NUMERIC_CHECK);
  exit;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard SMKN 65 Jakarta x Bank Sampah BEO</title>
  <link rel="icon" type="image/png" href="favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="styles.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


</head>
<body>

  <div class="app-container">
    
    <nav class="top-nav">
      <a class="nav-brand" onclick="scrollToTop()">
        <img src="https://res.cloudinary.com/dvz66ax7j/image/upload/v1739619202/logo_smkn65_d9cb879d23.jpg" alt="Logo SMKN 65 Jakarta">
        <div class="brand-text">
          <h2>SMKN 65 JAKARTA</h2>
          <p>PASTIKAN - Pengelolaan Aktif Sampah Terintegrasi SPANCA</p>
        </div>
      </a>

      <ul class="nav-menu">
        <li class="active" id="nav-beranda"><a onclick="scrollToTop()"><i class="fa-solid fa-house"></i> Beranda</a></li>
        <li id="nav-grafik"><a href="#grafik"><i class="fa-solid fa-chart-column"></i> Grafik</a></li>
        <li id="nav-lapor"><a href="#input-laporan"><i class="fa-solid fa-pen-to-square"></i> Laporan</a></li>
        <li id="nav-dokum"><a href="#dokumentasi"><i class="fa-solid fa-camera"></i> Dokumentasi</a></li>
        <li id="nav-mitra"><a href="#mitra"><i class="fa-solid fa-handshake"></i> Mitra</a></li>
        <li id="nav-faq"><a href="#faq"><i class="fa-solid fa-circle-question"></i> FAQ</a></li>
        <li id="nav-kontak"><a href="#kontak"><i class="fa-solid fa-address-book"></i> Kontak</a></li>
      </ul>

      <div class="nav-actions">
        <button class="theme-toggle" id="themeToggleBtn" title="Ganti Tema">
          <i class="fa-solid fa-moon" id="themeIcon"></i>
        </button>

        <?php if ($loggedInUser !== null): ?>
          <div class="user-profile">
            <img src="https://ui-avatars.com/api/?name=65 &background=10b981&color=fff" alt="Profil">
          </div>
          <a class="logout-button" href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Keluar
          </a>
          </div>
        <?php endif; ?>
      </nav>

    <section class="hero-section" id="beranda">
      <div class="hero-card">
       

        <h1>
          Pengelolaan Aktif Sampah <span>Terintegrasi In SPANCA</span>
        </h1>

        <p>
          Selamat datang di website PASTIKAN.
Terimakasih telah menjadi bagian dari perubahan positif untuk lingkungan Kita. 
PASTIKAN Hadir sebagai solusi digital untuk mempermudah peserta didik dalam mengelola pengumpulan sampah secara realtime. 
Setiap langkah kecil kalian hari ini dapat membawa dampak besar bagi kebersihan lingkungan masa depan. Mari bersama-sama mewujudkan lingkungan yang lebih bersih, sehat, dan bebas dari sampah.
        </p>

        <div class="card-features">
          <div class="feature-item"><i class="fa-solid fa-boxes-stacked"></i> Pemilahan Organik & Anorganik</div>
          <div class="feature-item"><i class="fa-solid fa-chart-line"></i> Pantauan Statistik Real-time</div>
        </div>

        <a href="#input-laporan" class="btn-action">
          <i class="fa-solid fa-paper-plane"></i> Ayo Mulai Lapor
        </a>
      </div>
    </section>

    <section class="chart-section" id="grafik">
      <div class="chart-card">
        <h3 style="font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-chart-column" style="color: var(--primary);"></i> Rekapitulasi Kategori Sampah (kg)
        </h3> 
        <hr style="margin-bottom: 25px;">
        <?php if ($message !== null): ?>
          <div class="status-message<?php echo $messageType === 'error' ? ' error' : ''; ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>
        <div class="form-grid">
          <div class="form-group">
            <label for="filter-tanggal"><i class="fa-solid fa-calendar-day"></i> Tanggal:</label>
            <input type="date" id="filter-tanggal" value="<?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="form-group">
            <label for="filter-kelas"><i class="fa-solid fa-school"></i> Kelas :</label>
            <select id="filter-kelas" data-selected="<?php echo htmlspecialchars($filterClass, ENT_QUOTES, 'UTF-8'); ?>">
                <option value=""<?php echo $filterClass === '' ? ' selected' : ''; ?>>-- Semua Kelas --</option>
                <option value="XII RPL 1">XII RPL 1</option>
                <option value="XII RPL 2">XII RPL 2</option>
                <option value="XII DKV 1">XII DKV 1</option>
                <option value="XII DKV 2">XII DKV 2</option>
                <option value="XII PF 1">XII PF 1</option>
                <option value="XII PF 2">XII PF 2</option>
                <option value="XI RPL 1">XI RPL 1</option>
                <option value="XI RPL 2">XI RPL 2</option>
                <option value="XI DKV 1">XI DKV 1</option>
                <option value="XI DKV 2">XI DKV 2</option>
                <option value="XI PF 1">XI PF 1</option>
                <option value="XI PF 2">XI PF 2</option>
                <option value="X RPL 1">X RPL 1</option>
                <option value="X RPL 2">X RPL 2</option>
                <option value="X DKV 1">X DKV 1</option>
                <option value="X DKV 2">X DKV 2</option>
                <option value="X PF 1">X PF 1</option>
                <option value="X PF 2">X PF 2</option>
              </select>
          </div>
          <div class="tabel-container">
            <table>
            <thead>
              <tr>
                <th>Kategori Sampah</th>
                <th>Jumlah</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Sampah Organik</td>
                <td id="total-organik"><?php echo number_format($totals['organik'], 1, ',', '.'); ?> kg</td>
              </tr>
              <tr>
                <td>Sampah Anorganik</td>
                <td id="total-anorganik"><?php echo number_format($totals['anorganik'], 1, ',', '.'); ?> kg</td>
              </tr>
              <tr>
                <td>Sampah Residu</td>
                <td id="total-residu"><?php echo number_format($totals['residu'], 1, ',', '.'); ?> kg</td>
              </tr>
              <tr>
                <td>Sampah B3</td>
                <td id="total-b3"><?php echo number_format($totals['b3'], 1, ',', '.'); ?> kg</td>
              </tr>
            </tbody>
          </table>
          </div>
          <div class="chart-container">
            <canvas id="barChartKategori" data-totals="<?php echo htmlspecialchars(json_encode(array_values($totals), JSON_NUMERIC_CHECK), ENT_QUOTES, 'UTF-8'); ?>"></canvas>
          </div>
        </div>
      </div>
    </section>

    <section class="form-section" id="input-laporan">
      <div class="form-card">
        <div class="form-header">
          <h2><i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i> Form Input Laporan Sampah</h2>
          <p>Isi formulir di bawah ini untuk mencatat jenis dan bobot sampah harian yang disetorkan.</p>
        </div>

        <form id="laporanForm" method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_report">
          <div class="form-grid">
            
            <div class="form-group">
              <label for="nama"><i class="fa-solid fa-user"></i> Nama</label>
              <input type="text" id="nama" name="nama" placeholder="Masukan Nama Lengkap" required>
            </div>

            <div class="form-group">
              <label for="tanggal"><i class="fa-solid fa-calendar-days"></i> Tanggal Setor</label>
              <input type="date" id="tanggal-setor" name="tanggal" required>
            </div>
            
            <div class="form-group">
              <label for="kelas"><i class="fa-solid fa-school"></i> Kelas</label>
              <select id="kelas-setor" name="kelas" required>
                <option value="">-- Pilih Kelas --</option>
                <option value="XII RPL 1">XII RPL 1</option>
                <option value="XII RPL 2">XII RPL 2</option>
                <option value="XII DKV 1">XII DKV 1</option>
                <option value="XII DKV 2">XII DKV 2</option>
                <option value="XII PF 1">XII PF 1</option>
                <option value="XII PF 2">XII PF 2</option>
                <option value="XI RPL 1">XI RPL 1</option>
                <option value="XI RPL 2">XI RPL 2</option>
                <option value="XI DKV 1">XI DKV 1</option>
                <option value="XI DKV 2">XI DKV 2</option>
                <option value="XI PF 1">XI PF 1</option>
                <option value="XI PF 2">XI PF 2</option>
                <option value="X RPL 1">X RPL 1</option>
                <option value="X RPL 2">X RPL 2</option>
                <option value="X DKV 1">X DKV 1</option>
                <option value="X DKV 2">X DKV 2</option>
                <option value="X PF 1">X PF 1</option>
                <option value="X PF 2">X PF 2</option>
              </select>
            </div>

            <div class="form-group">
              <label for="kategori"><i class="fa-solid fa-filter"></i> Kategori Sampah</label>
              <select id="kategori" name="kategori" required>
                <option value="">-- Pilih Kategori --</option>
                <option value="organik" title="Sampah Organik: Sisa makanan, Sisa aktivitas memasak, Kulit buah, Sayuran, Daun, Rumput, dan Ranting kecil">Sampah Organik</option> 
                <option value="anorganik" title="Sampah Anorganik: Kertas, Kardus, Botol plastik, Botol kaca, Kantong plastik, dan Kemasan plastik, Kaleng logam">Sampah Anorganik</option>
                <option value="residu" title="Sampah Residu: Tisu kotor, Masker sekali pakai, Styrofoam kotor, Kemasan multilayer, Sampah tercemar lainnya,  dan sampah yang sulit didaur ulang.">Sampah Residu</option>
                <option value="b3" title="Sampah B3: Baterai bekas, Lampu, E-Waste, Kemasan pemutih, Kemasan pembersih lantai, Kemasan pengharum ruangan, Kemasan pestisida/obat nyamuk">Sampah B3</option>
              </select>
            </div>

            <div class="form-group">
              <label for="berat"><i class="fa-solid fa-weight-hanging"></i> Berat (Kg)</label>
              <input type="number" id="berat" name="berat" step="0.1" min="0.1" placeholder="Contoh: 2.5" required>
            </div>

            <div class="form-group full-width">
              <label for="gambar"><i class="fa-solid fa-camera"></i> Upload foto ketika membuang sampah pada tempatnya</label>
              <input type="file" id="gambar" name="gambar" accept="image/*">
            </div>

          </div>

          <button type="submit" class="btn-submit" style="margin-top: 20px;">
            <i class="fa-solid fa-paper-plane"></i> Kirim Laporan Sampah
          </button>
        </form>
      </div>
    </section>

    <section class="gallery-section" id="dokumentasi">
      <div class="gallery-header">
        <h2><i class="fa-solid fa-camera" style="color: var(--primary);"></i> Galeri Kegiatan</h2>
        <p>Dokumentasi aksi nyata siswa dan komunitas SMKN 65 Jakarta dalam memilah dan mengelola sampah.</p>
      </div>

      <div class="scroll-container">
        <div class="scroll-track">
          <div class="gallery-card">
            <img src="sos1.png" alt="Kegiatan Pemilahan Sampah">
            <div class="overlay-caption">Aksi Pemilahan Sampah Organik</div>
          </div>
          <div class="gallery-card">
            <img src="sos2.png" alt="Bank Sampah BEO">
            <div class="overlay-caption">Setor Sampah ke Bank Sampah BEO</div>
          </div>
          <div class="gallery-card">
            <img src="sos3.png" alt="Penimbangan Sampah">
            <div class="overlay-caption">Foto Bersama </div>
          </div>
          <div class="gallery-card">
            <img src="sos4.png" alt="Edukasi Adiwiyata">
            <div class="overlay-caption">Sosialisasi Lingkungan Hidup</div>
          </div>
          <div class="gallery-card">
            <img src="sos5.png" alt="Hasil Daur Ulang">
            <div class="overlay-caption">Sosialisasi Pembinaan Sampah</div>
          </div>
          <div class="gallery-card">
            <img src="sos1.png" alt="Kegiatan Pemilahan Sampah">
            <div class="overlay-caption">Aksi Pemilahan Sampah Organik</div>
          </div>
          <div class="gallery-card">
            <img src="sos2.png" alt="Bank Sampah BEO">
            <div class="overlay-caption">Setor Sampah ke Bank Sampah BEO</div>
          </div>
          <div class="gallery-card">
            <img src="sos3.png" alt="Penimbangan Sampah">
            <div class="overlay-caption">Foto Bersama </div>
          </div>
          <div class="gallery-card">
            <img src="sos4.png" alt="Edukasi Adiwiyata">
            <div class="overlay-caption">Sosialisasi Lingkungan Hidup</div>
          </div>
          <div class="gallery-card">
            <img src="sos5.png" alt="Hasil Daur Ulang">
            <div class="overlay-caption">Sosialisasi Pembinaan Sampah</div>
          </div>
        </div>
      </div>
    </section>

    <section class="about-section" id="mitra">
      <div class="about-card">
        <div class="about-header">
          <i class="fa-solid fa-recycle"></i>
          <div>
            <h2>Tentang Kerjasama </h2>
            <p>SMKN 65 Jakarta berkerjasama dengan beberapa bank sampah, salah satuya yaitu Bank Sampah BEO.</p>
          </div>
        </div>

        <div class="about-body">
          <p>
            <strong>Bank Sampah BEO</strong> Bank Sampah Beo Jl. Cipinang Besar Selatan RT
005 RW 10, Kelurahan Cipinang Besar Selatan, Jakarta Timur.
          </p>

          <div class="about-grid">
            <div class="info-box">
              <i class="fa-solid fa-bullseye"></i>
              <h4>Visi Utama</h4>
              <p>Mewujudkan lingkungan sekolah SMKN 65 Jakarta yang bersih, hijau, dan berkelanjutan melalui sistem pengelolaan sampah terintegrasi serta pembentukan karakter siswa yang peduli lingkungan.</p>
            </div>

            <div class="info-box">
              <i class="fa-solid fa-leaf"></i>
              <h4>Program Daur Ulang</h4>
              <p>Memilah sampah dari lingkungan sekolah berdasarkan kategorinya (organik, anorganik, residu, dan B3). Sampah bernilai daur ulang akan dikumpulkan secara terstruktur sebelum disalurkan ke mitra bank sampah untuk diolah kembali.</p>
            </div>

            <div class="info-box">
              <i class="fa-solid fa-hand-holding-dollar"></i>
              <h4>Tabungan Sampah</h4>
              <p>Mengubah hasil pengumpulan sampah terpilah menjadi tabungan bernilai ekonomis. Sampah yang terkumpul dari tiap kelas akan ditimbang dan dijual ke mitra bank sampah, lalu hasilnya dicatat sebagai kas atau saldo tabungan lingkungan kelas.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <script src="script.js"></script>

    <div class="notification-overlay" id="notifOverlay">
      <div class="notification-box">
        <div class="icon-badge">
          <i class="fa-solid fa-check"></i>
        </div>
        <div class="notification-title">Laporan Berhasil Disimpan!</div>
        <p class="notification-sub">
          Terima kasih! Data sampah dan foto bukti Anda telah terverifikasi dan tercatat pada database Bank Sampah BEO SMKN 65 Jakarta.
        </p>
        <button class="btn-close-notif" onclick="closeNotification()">
          Selesai & Lanjutkan
        </button>
      </div>
    </div>

<section class="faq-section" id="faq">
  <div class="faq-card">
    <div class="faq-header">
      <h2>
        <i class="fa-solid fa-circle-question"></i> Pertanyaan Sering Diajukan (FAQ)
      </h2>
      <p>Informasi seputar sistem pengelolaan sampah PASTIKAN SMKN 65 Jakarta & Bank Sampah BEO</p>
    </div>

    <div class="faq-container">
      <details class="faq-item">
        <summary>
          <span><i class="fa-solid fa-leaf"></i> Apa itu sistem PASTIKAN SPANCA?</span>
          <i class="fa-solid fa-chevron-down faq-icon"></i>
        </summary>
        <p>
          PASTIKAN (Pengelolaan Aktif Sampah Terintegrasi SPANCA) adalah platform digital SMKN 65 Jakarta yang memfasilitasi pencatatan, pemilahan, dan rekapitulasi data pengumpulan sampah harian peserta didik secara real-time.
        </p>
      </details>

      <details class="faq-item">
        <summary>
          <span><i class="fa-solid fa-trash-can"></i> Apa saja kategori sampah yang dapat disetorkan?</span>
          <i class="fa-solid fa-chevron-down faq-icon"></i>
        </summary>
        <p>
          Sampah dibagi menjadi 4 kategori utama: <b>Organik</b> (sisa makanan/daun), <b>Anorganik</b> (plastik, kertas, kaleng), <b>Residu</b> (tisu kotor/styrofoam), dan <b>B3</b> (baterai/lampu bekas).
        </p>
      </details>

      <details class="faq-item">
        <summary>
          <span><i class="fa-solid fa-handshake"></i> Dimana lokasi penyetoran sampah Mitra Bank Sampah BEO?</span>
          <i class="fa-solid fa-chevron-down faq-icon"></i>
        </summary>
        <p>
          Penyetoran dan penimbangan dapat dilakukan di lokasi mitra Bank Sampah BEO, Jl. Cipinang Besar Selatan RT 005 RW 10, Kel. Cipinang Besar Selatan, Jakarta Timur, atau melalui titik kumpul Adiwiyata SMKN 65 Jakarta.
        </p>
      </details>

      <details class="faq-item">
        <summary>
          <span><i class="fa-solid fa-paper-plane"></i> Bagaimana cara mengisi formulir laporan sampah?</span>
          <i class="fa-solid fa-chevron-down faq-icon"></i>
        </summary>
        <p>
          Pilih menu <a href="#input-laporan">Laporan</a>, isi Nama, Tanggal, Kelas, Kategori Sampah, Berat (kg), serta unggah foto bukti saat membuang sampah pada tempatnya, lalu klik "Kirim Laporan Sampah".
        </p>
      </details>
    </div>
  </div>
</section>

<footer class="site-footer" id="kontak">
  <div class="footer-grid">
    <div class="footer-col">
      <div class="footer-brand">
        <img src="https://res.cloudinary.com/dvz66ax7j/image/upload/v1739619202/logo_smkn65_d9cb879d23.jpg" alt="Logo SMKN 65 Jakarta" class="footer-logo" onerror="this.style.display='none'">
        <div>
          <h3>SMKN 65 JAKARTA</h3>
          <p class="brand-tag">PASTIKAN SPANCA</p>
        </div>
      </div>
      <p class="footer-desc">
        Pengelolaan Aktif Sampah Terintegrasi SPANCA x Bank Sampah BEO. Bersama mewujudkan lingkungan sekolah yang bersih, hijau, dan bebas sampah.
      </p>
    </div>

    <div class="footer-col">
      <h4 class="footer-title">Navigasi</h4>
      <ul class="footer-links">
        <li><a href="#beranda"><i class="fa-solid fa-chevron-right"></i> Beranda</a></li>
        <li><a href="#grafik"><i class="fa-solid fa-chevron-right"></i> Grafik Rekapitulasi</a></li>
        <li><a href="#input-laporan"><i class="fa-solid fa-chevron-right"></i> Form Laporan</a></li>
        <li><a href="#dokumentasi"><i class="fa-solid fa-chevron-right"></i> Galeri Kegiatan</a></li>
        <li><a href="#mitra"><i class="fa-solid fa-chevron-right"></i> Mitra Kerjasama</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4 class="footer-title">Kontak & Alamat</h4>
      <ul class="footer-contact">
        <li>
          <i class="fa-solid fa-location-dot"></i>
          <span><b>SMKN 65 Jakarta:</b> Jl. Declate No.1, RT.2/RW.3, Cipinang Besar Selatan, Jatinegara, Jakarta Timur.</span>
        </li>
        <li>
          <i class="fa-solid fa-recycle"></i>
          <span><b>Bank Sampah BEO:</b> Jl. Cipinang Besar Selatan RT 005 RW 10, Cipinang Besar Selatan, Jakarta Timur.</span>
        </li>
      </ul>
    </div>

    <div class="footer-col">
      <h4 class="footer-title">Sosial Media</h4>
      <p class="social-text">Ikuti aktivitas dan pembaruan seputar SMKN 65 SPANCA:</p>

      <div class="social-links">
        <a href="https://www.instagram.com/smkn65jakarta.official/" target="_blank" rel="noopener noreferrer" class="social-btn instagram" title="Instagram @smkn65jkt">
          <i class="fa-brands fa-instagram"></i>
        </a>
        <a href="https://www.youtube.com/@smknegeri65jakarta82" target="_blank" rel="noopener noreferrer" class="social-btn youtube" title="YouTube SMKN 65 Jakarta">
          <i class="fa-brands fa-youtube"></i>
        </a>
        <a href="https://www.tiktok.com/@username_tiktok" target="_blank" rel="noopener noreferrer" class="social-btn tiktok" title="TikTok SMKN 65 Jakarta">
          <i class="fa-brands fa-tiktok"></i>
        </a>
        <a href="#input-laporan" class="social-btn submit-report" title="Kirim Laporan Sampah">
          <i class="fa-solid fa-paper-plane"></i>
        </a>
      </div>

      <div class="operational-box">
        <p class="op-title"><i class="fa-solid fa-clock"></i> Jam Operasional Setor:</p>
        <p class="op-time">Senin - Jumat: 07.00 - 15.00 WIB</p>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <p>&copy; 2026-2027 <b>SMKN 65 Jakarta</b> x <b>Bank Sampah BEO</b>. All rights reserved.</p>
  </div>
</footer>
</div>
</body>
</html>