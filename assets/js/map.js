/**
 * Peta Bencana Magetan — peta choropleth per desa.
 *
 * Sumber data: REST /wp-json/pbm/v1/rekap (kolom Excel).
 * Pengelompokan murni per desa; koordinat tidak dipakai untuk plotting.
 */
(function () {
	'use strict';

	if (typeof L === 'undefined') return;

	var W = window.PBM || {};
	var i18n = W.i18n || {};

	/* Kelas warna: 0, 1-4, 5-19, 20-99, >=100 kejadian */
	var BIN = [1, 5, 20, 100];
	var WARNA = ['#f7f7f7', '#fee5d9', '#fcae91', '#fb6a4a', '#a50f15'];

	var state = {
		dari: W.dari || '',
		sampai: W.sampai || '',
		bencana: W.bencana || '',
		kecamatan: W.kecamatan || ''
	};

	/* Pengurutan tabel bencana per desa. Bawaan: jumlah total kejadian
	 * terbanyak dulu, supaya desa paling terdampak langsung terlihat. */
	var urut = { kolom: 'total', arah: 'turun' };

	var map, desaLayer, kecLayer, rekap = {}, rekapJenis = {}, legend;

	/* Master desa (sekali muat) dan indeks poligon per kode — dipakai
	 * tabel bencana per desa. */
	var daftarDesa = null;
	var kodePeta = {};
	var daftarGagal = false;

	/* Warna batang grafik jenis bencana. */
	var WARNA_BAR = ['#a50f15', '#d0452c', '#e8813a', '#efb145', '#7fa482', '#4f8fa8', '#5f73a8', '#98a2b3'];

	function warna(n) {
		if (!n) return WARNA[0];
		for (var i = 0; i < BIN.length; i++) if (n < BIN[i]) return WARNA[i + 1];
		return WARNA[WARNA.length - 1];
	}

	function angka(v) { return Number(v || 0).toLocaleString('id-ID'); }

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function api(path, params) {
		var url = new URL(W.rest + '/' + path);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k]) url.searchParams.set(k, params[k]);
		});
		return fetch(url).then(function (r) { return r.json(); });
	}

	/* ---------------------------------------------------------- gaya */

	function styleDesa(f) {
		var r = rekap[f.properties.kode];
		var n = r ? r.kejadian : 0;
		return {
			color: n ? '#7f1015' : '#5a6b7d',
			weight: n ? 1.6 : 0.8,
			opacity: 0.95,
			fillColor: warna(n),
			fillOpacity: n ? 0.9 : 0.3
		};
	}

	/* --------------------------------------------------------- popup */

	function popupHtml(kode, props) {
		var r = rekap[kode];
		var baris = '';
		if (r) {
			var perJenis = rekapJenis[kode] || {};
			var kunci = Object.keys(perJenis).sort(function (a, b) { return perJenis[b] - perJenis[a]; });
			baris = '<tr><td>' + (i18n.kejadian || 'Total kejadian') + '</td><td><b>' + angka(r.kejadian) + '</b></td></tr>';
			if (kunci.length) {
				baris += '<tr class="pbm-popup-sep"><td colspan="2">' + (i18n.perJenis || 'Per jenis bencana') + '</td></tr>';
				kunci.forEach(function (j) {
					baris += '<tr><td>' + escapeHtml(j) + '</td><td><b>' + angka(perJenis[j]) + '</b></td></tr>';
				});
			}
		} else {
			baris = '<tr><td colspan="2" class="pbm-popup-kosong">' + (i18n.tanpaData || 'Belum ada laporan') + '</td></tr>';
		}
		return '<div class="pbm-popup">'
			+ '<h4>' + escapeHtml(props.nama) + '</h4>'
			+ '<div class="pbm-popup-sub">Desa, Kec. ' + escapeHtml(props.kecamatan) + ' · ' + escapeHtml(kode) + '</div>'
			+ '<table>' + baris + '</table></div>';
	}

	/* ------------------------------------------------------ statistik */

	function hitungStats() {
		var el = document.getElementById('pbm-stats');

		var t = { kejadian: 0, desa: 0, kecamatan: {} };
		Object.keys(rekap).forEach(function (k) {
			var r = rekap[k];
			t.kejadian += r.kejadian;
			t.desa++;
			if (r.kecamatan) t.kecamatan[r.kecamatan] = 1;
		});

		if (el) {
			el.innerHTML =
				kartu(t.kejadian, i18n.kejadian) +
				kartu(t.desa, i18n.desaTerdampak) +
				kartu(Object.keys(t.kecamatan).length, i18n.kecamatan);
		}

		isiJenis();
		isiTabel();
	}

	function kartu(n, label) {
		return '<div class="pbm-stat"><b>' + angka(n) + '</b><span>' + label + '</span></div>';
	}

	/* ------------------------------------------ kejadian per jenis bencana */

	/** Akumulasi seluruh desa jadi satu daftar: nama jenis => jumlah. */
	function jumlahPerJenis() {
		var per = {};
		Object.keys(rekapJenis).forEach(function (kode) {
			var j = rekapJenis[kode] || {};
			Object.keys(j).forEach(function (k) { per[k] = (per[k] || 0) + j[k]; });
		});
		return per;
	}

	function isiJenis() {
		var per = jumlahPerJenis();
		var urut = Object.keys(per).sort(function (a, b) { return per[b] - per[a]; });
		var total = 0;
		urut.forEach(function (k) { total += per[k]; });
		var maks = urut.length ? per[urut[0]] : 1;

		var isi = '<h3 class="pbm-panel-t">' + escapeHtml(i18n.jenis || 'Kejadian per jenis bencana') + '</h3>';

		if (!urut.length) {
			isi += '<p class="pbm-kosong">' + escapeHtml(i18n.belumAda || 'Belum ada laporan.') + '</p>';
		} else {
			isi += '<ul class="pbm-bars">';
			urut.forEach(function (k, i) {
				var n = per[k];
				var pct = total ? Math.round(n / total * 100) : 0;
				isi += '<li>'
					+ '<span class="pbm-bar-nama" title="' + escapeHtml(k) + '">' + escapeHtml(k) + '</span>'
					+ '<span class="pbm-bar-jalur"><span class="pbm-bar-isi" style="width:'
					+ Math.max(2, n / maks * 100).toFixed(1) + '%;background:'
					+ WARNA_BAR[i % WARNA_BAR.length] + '"></span></span>'
					+ '<b class="pbm-bar-n">' + angka(n) + '</b>'
					+ '<em class="pbm-bar-pct">' + pct + '%</em>'
					+ '</li>';
			});
			isi += '</ul>';
			isi += '<p class="pbm-bar-total"><span>' + escapeHtml(i18n.total || 'Total kejadian')
				+ '</span><b>' + angka(total) + '</b></p>';
		}

		var box = document.getElementById('pbm-panel-jenis');
		if (box) { box.innerHTML = isi; return; }

		/* Halaman lama tanpa wadah khusus: sisipkan setelah kartu statistik.
		 * Halaman admin tidak punya #pbm-stats, jadi kartunya tetap milik
		 * server dan tidak disentuh di sini. */
		var s = document.getElementById('pbm-stats');
		if (!s || !s.parentNode) return;
		var lama = s.parentNode.querySelector('.pbm-jenis-box');
		if (lama) lama.parentNode.removeChild(lama);
		s.insertAdjacentHTML('afterend', '<div class="pbm-jenis-box">' + isi + '</div>');
	}

	/* ------------------------------------------- tabel bencana per desa */

	/** Muat master desa sekali jalan; sengaja lepas dari rantai peta. */
	function muatDaftarDesa() {
		api('desa')
			.then(function (d) { daftarDesa = Array.isArray(d) ? d : []; })
			.catch(function () { daftarDesa = []; daftarGagal = true; })
			.then(isiTabel);
	}

	/** Susunan kolom: No, Nama Desa, Nama Kecamatan, tiap jenis, Jumlah Total. */
	function kolomTabel() {
		var kolom = [
			{ k: 'no', t: 'angka', s: 'teks', l: i18n.labelNo || 'No' },
			{ k: 'nama', t: 'teks', l: i18n.labelDesa || 'Nama Desa' },
			{ k: 'kecamatan', t: 'teks', l: i18n.labelKec || 'Nama Kecamatan' }
		];

		/* Kepala kolom datang dari server (seluruh jenis pada data), jadi
		 * susunan kolom tetap walau saringan menyisakan satu jenis saja. */
		var jenis = (W.kolomJenis && W.kolomJenis.length) ? W.kolomJenis.slice() : [];
		if (!jenis.length) {
			jenis = [];
			Object.keys(rekapJenis).forEach(function (kode) {
				Object.keys(rekapJenis[kode] || {}).forEach(function (n) {
					if (jenis.indexOf(n) < 0) jenis.push(n);
				});
			});
		}
		jenis.forEach(function (nama, i) {
			kolom.push({ k: 'j' + i, t: 'angka', l: nama, jenis: nama });
		});

		kolom.push({ k: 'total', t: 'angka', l: i18n.labelTotal || 'Jumlah Total' });
		return kolom;
	}

	/** Gambar kepala kolom beserta tanda urutnya. */
	function kepalaTabel() {
		var tr = document.getElementById('pbm-tabel-kepala');
		if (!tr) return;

		var html = '';
		kolomTabel().forEach(function (c) {
			var aktif = urut.kolom === c.k;
			var arah = aktif ? urut.arah : '';
			/* Arah berikutnya saat kolom diklik: kolom angka dibuka dari
			 * yang terbesar, kolom teks dari huruf terkecil. */
			var berikut = aktif ? (urut.arah === 'naik' ? 'turun' : 'naik')
				: (c.t === 'angka' ? 'turun' : 'naik');

			html += '<th scope="col" class="pbm-th pbm-th-' + c.t + (aktif ? ' sorted ' + arah : '')
				+ '" aria-sort="' + (aktif ? (arah === 'naik' ? 'ascending' : 'descending') : 'none') + '">'
				+ '<button type="button" class="pbm-th-btn" data-kolom="' + escapeHtml(c.k)
				+ '" data-arah="' + berikut + '"><span>' + escapeHtml(c.l)
				+ '</span><span class="pbm-sort-ind" aria-hidden="true"></span></button></th>';
		});
		tr.innerHTML = html;
	}

	/** Isi badan tabel: saring master desa, isi hitungan, urutkan, render. */
	function isiTabel() {
		var tbody = document.getElementById('pbm-tabel-badan');
		var counter = document.getElementById('pbm-tabel-jumlah');
		if (!tbody) return;

		/* Kepala digambar ulang tiap kali: bila jenis kolom diturunkan dari
		 * data (bukan dari server), ia baru muncul setelah rekap turun. */
		kepalaTabel();

		var kolom = kolomTabel();
		var inp = document.getElementById('pbm-cari-desa');
		var q = ((inp && inp.value) || '').trim().toLowerCase();

		var pesan = '';
		if (!daftarDesa) pesan = i18n.memuatTabel || 'Memuat tabel…';
		else if (daftarGagal) pesan = i18n.gagal || 'Gagal memuat data.';

		if (pesan) {
			tbody.innerHTML = '<tr><td class="pbm-tabel-kosong" colspan="' + kolom.length + '">'
				+ escapeHtml(pesan) + '</td></tr>';
			if (counter) counter.textContent = '';
			return;
		}

		var baris = [];
		var totalMaster = 0;
		daftarDesa.forEach(function (d) {
			var sama = !state.kecamatan || d.kecamatan === state.kecamatan;
			if (sama) totalMaster++;
			if (!sama) return;

			var nama = d.nama || '';
			var cocok = !q
				|| nama.toLowerCase().indexOf(q) >= 0
				|| String(d.kecamatan || '').toLowerCase().indexOf(q) >= 0
				|| String(d.kode || '').indexOf(q) >= 0;
			if (!cocok) return;

			baris.push({
				kode: d.kode,
				nama: nama,
				kecamatan: d.kecamatan || '-',
				per: rekapJenis[d.kode] || {},
				total: rekap[d.kode] ? rekap[d.kode].kejadian : 0,
				/* Desa impor tanpa poligon tidak bisa dipetakan. */
				peta: !!kodePeta[d.kode]
			});
		});

		if (counter) {
			counter.textContent = '(' + angka(baris.length)
				+ (baris.length === totalMaster ? '' : ' / ' + angka(totalMaster)) + ')';
		}

		if (!baris.length) {
			tbody.innerHTML = '<tr><td class="pbm-tabel-kosong" colspan="' + kolom.length + '">'
				+ escapeHtml(i18n.tabelKosong || 'Tidak ada desa yang cocok.') + '</td></tr>';
			return;
		}

		var col = null;
		for (var i = 0; i < kolom.length; i++) if (kolom[i].k === urut.kolom) col = kolom[i];
		if (!col) col = { k: 'kecamatan', t: 'teks' };

		/** Nilai sebuah kolom untuk sebuah baris. */
		function nilai(r, c) {
			if (c.k === 'no') return r.kode;              /* urutan fisik master */
			if (c.k === 'nama') return r.nama;
			if (c.k === 'kecamatan') return r.kecamatan;
			if (c.k === 'total') return r.total;
			return r.per[c.jenis] || 0;
		}

		baris.sort(function (a, b) {
			var tipe = col.s || col.t;
			var h;
			if (tipe === 'angka') h = nilai(a, col) - nilai(b, col);
			else h = String(nilai(a, col)).localeCompare(String(nilai(b, col)), 'id');
			if (urut.arah === 'turun') h = -h;
			/* Pengikat: kecamatan lalu nama, selalu naik — supaya baris yang
			 * nilainya sama tetap terbaca berurutan apa pun arah urutnya. */
			if (h === 0) {
				h = a.kecamatan.localeCompare(b.kecamatan, 'id')
					|| a.nama.localeCompare(b.nama, 'id');
			}
			return h;
		});

		var html = '';
		baris.forEach(function (r, idx) {
			html += '<tr' + (r.peta ? ' class="pbm-baris" data-kode="' + escapeHtml(r.kode) + '"' : '') + '>';
			kolom.forEach(function (c, ci) {
				var cls = 'pbm-td';
				var isi;

				if (c.k === 'nama' || c.k === 'kecamatan') {
					isi = escapeHtml(c.k === 'nama' ? r.nama : r.kecamatan);
					cls += ' pbm-td-teks';
				} else {
					var v = c.k === 'no' ? idx + 1
						: (c.k === 'total' ? r.total : (r.per[c.jenis] || 0));
					isi = angka(v);
					cls += ' pbm-td-angka';
					if (c.k !== 'no' && !v) cls += ' nol';
				}

				if (ci < 3) cls += ' pbm-td-lekat';
				html += '<td class="' + cls + '">' + isi + '</td>';
			});
			html += '</tr>';
		});
		tbody.innerHTML = html;
	}

	/** Terbang ke poligon desa lalu buka popup-nya. */
	function terbangKeDesa(kode, el) {
		var ly = kodePeta[kode];
		if (!map || !ly) return;

		Array.prototype.forEach.call(document.querySelectorAll('.pbm-frontend .is-aktif'), function (b) {
			b.classList.remove('is-aktif');
		});
		if (el) el.classList.add('is-aktif');

		/* Peta mungkin berada di luar layar (tabel ada di bawahnya). */
		var p = document.getElementById('pbm-map');
		if (p) {
			var r = p.getBoundingClientRect();
			if (r.bottom < 0 || r.top > (window.innerHeight || 0)) {
				p.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}

		map.fitBounds(ly.getBounds(), { padding: [40, 40], maxZoom: 15 });
		window.setTimeout(function () { ly.openPopup(); }, 420);
	}

	/* -------------------------------------------------------- legenda */

	function buatLegenda() {
		if (!legend) {
			legend = L.control({ position: 'bottomright' });
			legend.onAdd = function () {
				var div = L.DomUtil.create('div', 'pbm-leaflet-legend');
				function item(c, t) {
					return '<span class="kotak" style="background:' + c + '"></span>' + t + '<br>';
				}
				div.innerHTML = '<b>Jumlah kejadian</b>'
					+ item(WARNA[0], '0') + item(WARNA[1], '1–4') + item(WARNA[2], '5–19')
					+ item(WARNA[3], '20–99') + item(WARNA[4], '≥ 100')
					+ '<div class="pbm-legenda-titik"><span class="garis"></span>Batas kecamatan</div>';
				return div;
			};
		}
		legend.addTo(map);
	}

	/* ----------------------------------------------------------- data */

	function muat() {
		return api('rekap', {
			dari: state.dari,
			sampai: state.sampai,
			bencana: state.bencana,
			kecamatan: state.kecamatan
		})
			.then(function (d) {
				rekap = (d && d.desa) || {};
				rekapJenis = (d && d.jenis) || {};
				if (desaLayer) desaLayer.setStyle(styleDesa);
				hitungStats();

				var rk = document.getElementById('pbm-rekap');
				if (rk) {
					var n = Object.keys(rekap).length;
					var periode = (state.dari || state.sampai)
						? (state.dari || '…') + ' s.d. ' + (state.sampai || '…')
						: 'seluruh periode';
					rk.innerHTML = '<p>' + (n
						? n + ' desa punya laporan pada ' + periode
							+ (state.bencana ? ' (jenis: ' + escapeHtml(state.bencana) + ')' : '')
							+ '. Klik desa di peta untuk rincian per jenis bencana.'
						: (i18n.kosong || 'Tidak ada laporan pada periode ini.')) + '</p>';
				}
			})
			.catch(function () {
				var el = document.getElementById('pbm-stats') || document.getElementById('pbm-rekap');
				if (el) el.innerHTML = '<p class="pbm-error">' + (i18n.gagal || 'Gagal memuat data.') + '</p>';
			});
	}

	/* ----------------------------------------------------------- peta */

	function initMap() {
		var el = document.getElementById('pbm-map');
		if (!el) return;

		map = L.map(el, { scrollWheelZoom: false, minZoom: 9 });
		map.setView([-7.6535, 111.3473], 11);

		L.tileLayer(
			'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
			{ attribution: 'Tiles © Esri — Sumber: PDSI - Pusdatinkom BNPB', maxZoom: 17 }
		).addTo(map);

		var info = L.control({ position: 'topleft' });
		info.onAdd = function () {
			var div = L.DomUtil.create('div', 'pbm-info');
			div.id = 'pbm-info-inner';
			div.innerHTML = '<b>Kabupaten Magetan</b><br><span>' + (i18n.muat || 'Memuat…') + '</span>';
			L.DomEvent.disableClickPropagation(div);
			return div;
		};
		info.addTo(map);

		Promise.all([
			fetch(W.desaUrl).then(function (r) { return r.json(); }),
			fetch(W.kecUrl).then(function (r) { return r.json(); })
		]).then(function (arr) {
			desaLayer = L.geoJSON(arr[0], {
				style: styleDesa,
				onEachFeature: function (f, layer) {
					layer.bindPopup(function () { return popupHtml(f.properties.kode, f.properties); });
					layer.on('mouseover', function () { layer.setStyle({ weight: 2.5, color: '#1d3557' }); });
					layer.on('mouseout', function () { desaLayer.resetStyle(layer); });
				}
			}).addTo(map);

			/* Peta kode -> poligon, supaya baris daftar desa bisa terbang
			 * ke desa yang diklik. */
			desaLayer.eachLayer(function (ly) {
				var p = ly.feature && ly.feature.properties;
				if (p && p.kode) kodePeta[p.kode] = ly;
			});
			isiTabel();   /* gambar ulang: sekarang baris sudah bisa diklik */

			kecLayer = L.geoJSON(arr[1], {
				interactive: false,
				style: { color: '#33475b', weight: 2, fill: false, dashArray: '4 3' }
			}).addTo(map);

			map.fitBounds(desaLayer.getBounds(), { padding: [8, 8] });
			buatLegenda();

			var infoEl = document.getElementById('pbm-info-inner');
			if (infoEl) {
				infoEl.innerHTML = '<b>Kabupaten Magetan</b><br><span>' + (i18n.info || '') + '</span>';
			}
			var rk = document.getElementById('pbm-rekap');
			if (rk) rk.innerHTML = '<p>Memuat data…</p>';

			return muat();
		}).catch(function () {
			var d = document.getElementById('pbm-info-inner');
			if (d) d.innerHTML = '<b>Kabupaten Magetan</b><br><span class="pbm-error">' + (i18n.gagal || 'Gagal memuat.') + '</span>';
		});
	}

	/* -------------------------------------------------------- filter */

	function pasangFilter() {
		var terapkan = document.getElementById('pbm-terapkan');
		var reset = document.getElementById('pbm-reset');

		if (terapkan) {
			terapkan.addEventListener('click', function () {
				state.dari = (document.getElementById('pbm-dari') || {}).value || '';
				state.sampai = (document.getElementById('pbm-sampai') || {}).value || '';
				state.bencana = (document.getElementById('pbm-jenis') || {}).value || '';
				state.kecamatan = (document.getElementById('pbm-kecamatan') || {}).value || '';
				muat();
			});
		}
		if (reset) {
			reset.addEventListener('click', function () {
				['pbm-dari', 'pbm-sampai'].forEach(function (id) {
					var e = document.getElementById(id);
					if (e) e.value = '';
				});
				var j = document.getElementById('pbm-jenis');
				if (j) j.value = '';
				var kc = document.getElementById('pbm-kecamatan');
				if (kc) kc.value = '';
				state = { dari: '', sampai: '', bencana: '', kecamatan: '' };
				muat();
			});
		}
	}

	/* ------------------------------------------------ tabel bencana */

	function pasangTabel() {
		var cari = document.getElementById('pbm-cari-desa');
		if (cari) {
			cari.addEventListener('input', isiTabel);
			cari.addEventListener('search', isiTabel); /* tombol silang di sebagian peramban */
		}

		/* Kepala diganti lewat innerHTML, jadi sambungannya dipasang pada
		 * <tr>-nya sendiri yang tidak ikut diganti. */
		var kepala = document.getElementById('pbm-tabel-kepala');
		if (kepala) {
			kepala.addEventListener('click', function (e) {
				var btn = e.target && e.target.closest ? e.target.closest('.pbm-th-btn') : null;
				if (!btn) return;
				urut.kolom = btn.getAttribute('data-kolom');
				urut.arah = btn.getAttribute('data-arah');
				isiTabel();
			});
		}

		var badan = document.getElementById('pbm-tabel-badan');
		if (badan) {
			badan.addEventListener('click', function (e) {
				var tr = e.target && e.target.closest ? e.target.closest('tr[data-kode]') : null;
				if (!tr) return;
				terbangKeDesa(tr.getAttribute('data-kode'), tr);
			});
		}
	}

	function mulai() {
		kepalaTabel();   /* kepala kolom datang dari server, bisa digambar awal */
		isiTabel();      /* tampilkan "Memuat…" sebelum data turun */
		muatDaftarDesa();
		initMap();
		pasangFilter();
		pasangTabel();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mulai);
	} else {
		mulai();
	}
})();