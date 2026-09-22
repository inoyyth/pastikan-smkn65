# PASTIKAN SMKN 65 Jakarta x Bank Sampah BEO

Aplikasi pemantauan dan pencatatan sampah SMKN 65 Jakarta berbasis PHP dan SQLite.

## Menjalankan dengan Docker

### Prasyarat
- [Docker](https://docs.docker.com/get-docker/) & Docker Compose terpasang di komputer Anda.

### Cara Menjalankan

1. **Jalankan container**:
   ```bash
   docker compose up -d --build
   ```

2. **Buka aplikasi di browser**:
   Akses di URL:
   [http://localhost:8087](http://localhost:8087)

3. **Melihat log container**:
   ```bash
   docker compose logs -f
   ```

4. **Menghentikan container**:
   ```bash
   docker compose down
   ```

5. **Melihat status container**:
   ```bash
   docker compose ps
   ```

---

## Struktur Proyek & Data

- `index.php`: Halaman utama aplikasi (input data, filtering, statistik & grafik).
- `styles.css`: Gaya tampilan antarmuka (UI).
- `script.js`: Logika interaktif dan update grafik.
- `data/laporan.sqlite`: Database SQLite tempat penyimpanan laporan sampah.
- `uploads/`: Direktori penyimpanan foto dokumentasi yang diunggah.
- `Dockerfile` & `docker-compose.yml`: Konfigurasi containerisasi Docker.
- `docker-entrypoint.sh`: Script inisialisasi permission direktori SQLite dan uploads secara otomatis.
