# WP Store Import

WP Store Import adalah plugin ekstensi untuk WP Store yang menyediakan migrasi data dari:
- Velocity Toko
- WooCommerce

## Fitur
- Import Produk: judul, konten, excerpt, gambar utama, gallery, kategori, meta (harga, harga promo, SKU, stok, berat, opsi).
- Import Order: status, data pelanggan, alamat, ongkir, metode bayar, item dan total.
- Cek duplikasi dengan meta `_velocity_original_id`, `_woocommerce_original_id`, `_velocity_original_invoice`, `_woocommerce_original_order_id`.

## Cara Pakai
1. Aktifkan plugin WP Store dan WP Store Import.
2. Buka Admin WordPress: Produk → WP Store Import.
3. Pilih sumber data (Velocity Toko atau WooCommerce).
4. Klik “Mulai Import” dan tunggu proses selesai.

## Mapping Velocity Toko → WP Store
- Produk:
  - `harga` → `_store_price`
  - `harga_promo` → `_store_sale_price`
  - `sku` → `_store_sku`
  - `stok` → `_store_stock`
  - `berat` → `_store_weight_kg`
  - `minorder` → `_store_min_order`
  - `label` → `_store_label`
  - `flashsale` → `_store_flashsale_until`
  - Opsi: `opsistandart` → `_store_options`, `opsiharga` → `_store_advanced_options`
  - Kategori: `category-product` → `store_product_cat`
  - Gallery: meta `gallery` → `_store_gallery_ids`
- Order:
  - status, pelanggan, lokasi (kecamatan/kota/provinsi/kode pos), ongkir, resi, bayar, item, total

## Mapping WooCommerce → WP Store
- Produk:
  - `_price` → `_store_price`
  - `_sale_price` → `_store_sale_price`
  - `_sku` → `_store_sku`
  - `_manage_stock` & `_stock` → `_store_stock`
  - `_weight` → `_store_weight_kg`
  - Kategori: `product_cat` → `store_product_cat`
  - Gallery: `_product_image_gallery` → `_store_gallery_ids`
- Order:
  - Status `wc-*` dipetakan ke status WP Store
  - Pelanggan: meta billing (nama, email, phone, alamat, kota, kode pos, state)
  - Pengiriman: `_shipping_method`, `_shipping_total`
  - Pembayaran: `_payment_method`
  - Item: diambil dari tabel WooCommerce order items dan item meta
  - Total: `_order_total`

## Catatan
- Variasi/atribut kompleks pada WooCommerce belum dimigrasikan ke opsi tingkat lanjut; dapat ditambahkan kemudian via adapter.
- Untuk jumlah data besar, disarankan menjalankan import bertahap.
